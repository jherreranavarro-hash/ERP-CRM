<?php

namespace App\Modules\Operacion;

use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Operacion\Models\RetencionEjecucion;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Mantenedor "Retención de datos". Por cada empresa aplica el plazo de cada tipo y
 * la acción al vencer (archivar = exportar a JSONL en el disco "archivo" y borrar;
 * anonimizar = vaciar los datos personales; eliminar = borrar). La retención legal
 * de la empresa suspende todo borrado.
 *
 * Lo que la app no toca a propósito:
 *  - Auditoría (trazabilidad): es de solo inserción para el rol de la app. La purga
 *    de lo que supere el plazo (mín. 6 años) la hace el DBA con el rol dueño.
 *  - Documentos tributarios y libros: son inmutables y su plazo mínimo es de 6 años.
 *  - Prospectos: siempre se anonimizan (nunca se borran) porque los referencian
 *    llamadas, conversaciones y campañas; "archivar" exporta antes de anonimizar.
 */
class Retencion
{
    public const ANONIMO = 'Prospecto anonimizado';

    public const TEXTO_RETIRADO = '[contenido retirado por la política de retención]';

    public function __construct(private Parametros $parametros, private Tenancy $tenancy) {}

    /** @return list<RetencionEjecucion> */
    public function aplicar(): array
    {
        $hechas = [];
        foreach (Empresa::where('activo', true)->orderBy('id')->get() as $empresa) {
            $this->tenancy->como($empresa, function () use (&$hechas) {
                array_push($hechas, ...$this->aplicarEmpresa());
            });
        }
        array_push($hechas, ...$this->aplicarPlataforma());

        return $hechas;
    }

    /** @return list<RetencionEjecucion> */
    public function aplicarEmpresa(): array
    {
        if ($this->parametros->valor('retencion.retencion_legal')) {
            return [$this->registrar('todas', 'suspendida', 0, now())];
        }
        $accion = (string) $this->parametros->valor('retencion.accion_al_vencer');

        return [
            $this->grabaciones($accion),
            $this->mensajeria($accion),
            $this->prospectos($accion),
        ];
    }

    /** Logs de API y de aplicación: no dependen de la empresa. @return list<RetencionEjecucion> */
    public function aplicarPlataforma(): array
    {
        $hechas = [];

        $limite = now()->subDays((int) $this->parametros->valor('retencion.logs_api_dias'));
        $q = DB::table('api_registros')->where('created_at', '<', $limite);
        $empresasEnRetencion = Empresa::all()->filter(fn ($e) => $this->tenancy->como($e, fn () => (bool) $this->parametros->valor('retencion.retencion_legal')))->pluck('id');
        if ($empresasEnRetencion->isNotEmpty()) {
            $q->where(fn ($w) => $w->whereNull('empresa_id')->orWhereNotIn('empresa_id', $empresasEnRetencion));
        }
        [$n, $archivo] = $this->archivarYBorrar($q, 'api_registros', 'logs_api');
        $hechas[] = $this->registrar('logs_api', 'archivar', $n, $limite, $archivo, global: true);

        $limite = now()->subDays((int) $this->parametros->valor('retencion.logs_app_dias'));
        $n = 0;
        foreach (File::glob(storage_path('logs/*.log')) as $f) {
            if (File::lastModified($f) < $limite->timestamp && basename($f) !== 'laravel.log') {
                File::delete($f);
                $n++;
            }
        }
        $hechas[] = $this->registrar('logs_app', 'eliminar', $n, $limite, global: true);

        return $hechas;
    }

    private function grabaciones(string $accion): RetencionEjecucion
    {
        $limite = now()->subDays((int) $this->parametros->valor('retencion.grabaciones_dias'));
        $q = DB::table('llamadas')->where('empresa_id', $this->tenancy->id())->whereNotNull('grabacion_ref')->where('inicio', '<', $limite);

        // El audio vive en el almacenamiento de la central: se deja un manifiesto con las
        // referencias para que el proceso del PBX lo purgue, y la app olvida la referencia.
        $refs = (clone $q)->orderBy('id')->get(['id', 'grabacion_ref', 'inicio']);
        $archivo = $refs->isEmpty() ? null : $this->escribir('grabaciones', $refs);
        $n = $refs->isEmpty() ? 0 : (clone $q)->update(['grabacion_ref' => null, 'updated_at' => now()]);

        return $this->registrar('grabaciones', $accion, $n, $limite, $archivo);
    }

    private function mensajeria(string $accion): RetencionEjecucion
    {
        $limite = now()->subDays((int) $this->parametros->valor('retencion.mensajeria_dias'));
        $total = 0;
        $archivos = [];
        foreach (['wa_mensajes', 'li_mensajes'] as $tabla) {
            $q = DB::table($tabla)->where('empresa_id', $this->tenancy->id())->where('created_at', '<', $limite);
            if ($accion === 'anonimizar') {
                $total += (clone $q)->where('cuerpo', '<>', self::TEXTO_RETIRADO)->update(['cuerpo' => self::TEXTO_RETIRADO, 'updated_at' => now()]);

                continue;
            }
            [$n, $archivo] = $this->archivarYBorrar($q, $tabla, 'mensajeria', $accion === 'archivar');
            $total += $n;
            $archivos[] = $archivo;
        }

        return $this->registrar('mensajeria', $accion, $total, $limite, implode(', ', array_filter($archivos)) ?: null);
    }

    private function prospectos(string $accion): RetencionEjecucion
    {
        $limite = now()->subDays((int) $this->parametros->valor('retencion.prospectos_dias'));
        $q = DB::table('leads')->where('empresa_id', $this->tenancy->id())
            ->whereNotIn('estado', ['ganado'])
            ->where('nombre', '<>', self::ANONIMO)
            ->whereRaw('coalesce(ultimo_contacto_at, updated_at) < ?', [$limite]);

        $filas = (clone $q)->orderBy('id')->get();
        $archivo = $accion === 'archivar' && $filas->isNotEmpty() ? $this->escribir('prospectos', $filas) : null;
        $ids = $filas->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('leads')->whereIn('id', $ids)->update([
                'nombre' => self::ANONIMO, 'rut' => null, 'telefono' => '000000000', 'email' => null,
                'direccion' => null, 'notas' => null, 'updated_at' => now(),
            ]);
            DB::table('ads_leads')->whereIn('lead_id', $ids)->update(['nombre' => self::ANONIMO, 'telefono' => '000000000', 'email' => null, 'updated_at' => now()]);
            DB::table('wa_conversaciones')->whereIn('lead_id', $ids)->whereNull('cliente_id')->update(['nombre' => null, 'updated_at' => now()]);
        }

        return $this->registrar('prospectos', 'anonimizar', $ids->count(), $limite, $archivo);
    }

    /** @return array{0:int,1:?string} */
    private function archivarYBorrar(Builder $q, string $tabla, string $categoria, bool $archivar = true): array
    {
        $total = 0;
        $archivo = null;
        // En lotes para no cargar millones de filas en memoria.
        while (true) {
            $lote = (clone $q)->orderBy('id')->limit(2000)->get();
            if ($lote->isEmpty()) {
                break;
            }
            if ($archivar) {
                $archivo = $this->escribir($categoria.'-'.$tabla, $lote, $archivo);
            }
            $total += DB::table($tabla)->whereIn('id', $lote->pluck('id'))->delete();
        }

        return [$total, $archivo];
    }

    private function escribir(string $nombre, $filas, ?string $archivo = null): string
    {
        $empresa = $this->tenancy->id() ?? 'plataforma';
        $archivo ??= sprintf('retencion/%s/%s-%s.jsonl', $empresa, $nombre, now()->format('Ymd-His'));
        $lineas = $filas->map(fn ($f) => json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n")."\n";
        Storage::disk('archivo')->append($archivo, rtrim($lineas, "\n"));

        return $archivo;
    }

    private function registrar(string $categoria, string $accion, int $n, CarbonInterface $limite, ?string $archivo = null, bool $global = false): RetencionEjecucion
    {
        return RetencionEjecucion::create([
            'empresa_id' => $global ? null : $this->tenancy->id(),
            'categoria' => $categoria, 'accion' => $accion, 'registros' => $n,
            'limite' => $limite->toDateString(), 'archivo' => $archivo,
        ]);
    }
}
