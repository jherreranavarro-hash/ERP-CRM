<?php

namespace App\Modules\ContactCenter;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\ContactCenter\Models\Agente;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\ListaNegra;
use App\Modules\Core\Auditoria\Auditoria;
use App\Support\Rut;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carga de prospectos y asignación automática a los agentes de la campaña:
 * rotativa (round-robin), por zona (comuna del prospecto) o por carga (el
 * agente con menos prospectos abiertos), respetando la capacidad de cada uno.
 */
class Asignador
{
    public function __construct(private Auditoria $auditoria) {}

    /**
     * Importa un CSV con columnas nombre;telefono;rut;email;comuna;direccion (con encabezado).
     *
     * @return array{creados:int, duplicados:int, bloqueados:int, errores:array<int,string>}
     */
    public function importar(Campana $c, string $contenido, User $user): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        $lineas = preg_split('/\r\n|\n|\r/', trim($contenido));
        $sep = substr_count($lineas[0] ?? '', ';') >= substr_count($lineas[0] ?? '', ',') ? ';' : ',';
        $cab = array_map(fn ($h) => mb_strtolower(trim($h)), str_getcsv(array_shift($lineas) ?? '', $sep));
        if (! in_array('nombre', $cab, true) || ! in_array('telefono', $cab, true)) {
            throw ValidationException::withMessages(['archivo' => 'El archivo necesita las columnas nombre y telefono.']);
        }
        if (count($lineas) > 5000) {
            throw ValidationException::withMessages(['archivo' => 'Máximo 5.000 prospectos por archivo.']);
        }
        $r = ['creados' => 0, 'duplicados' => 0, 'bloqueados' => 0, 'errores' => []];
        DB::transaction(function () use ($c, $lineas, $cab, $sep, &$r) {
            foreach ($lineas as $i => $linea) {
                if (trim($linea) === '') {
                    continue;
                }
                $v = array_combine($cab, array_pad(array_slice(str_getcsv($linea, $sep), 0, count($cab)), count($cab), null));
                $tel = preg_replace('/[^\d+]/', '', (string) $v['telefono']);
                $nombre = trim((string) $v['nombre']);
                if ($nombre === '' || strlen(preg_replace('/\D/', '', $tel)) < 8) {
                    $r['errores'][] = 'Fila '.($i + 2).': nombre o teléfono no válido.';

                    continue;
                }
                $rut = Rut::normalizar($v['rut'] ?? null);
                if ($rut && ! Rut::valido($rut)) {
                    $rut = null;
                }
                if (Lead::where('campana_id', $c->id)->where('telefono', $tel)->exists()) {
                    $r['duplicados']++;

                    continue;
                }
                $bloqueado = ListaNegra::bloqueado($tel);
                Lead::create(['campana_id' => $c->id, 'nombre' => mb_substr($nombre, 0, 120), 'telefono' => $tel, 'rut' => $rut, 'email' => mb_substr(trim((string) ($v['email'] ?? '')), 0, 120) ?: null,
                    'comuna' => mb_substr(trim((string) ($v['comuna'] ?? '')), 0, 60) ?: null, 'direccion' => mb_substr(trim((string) ($v['direccion'] ?? '')), 0, 160) ?: null,
                    'cliente_id' => $rut ? Cliente::where('rut', $rut)->value('id') : null, 'estado' => $bloqueado ? 'no_contactar' : 'nuevo']);
                $bloqueado ? $r['bloqueados']++ : $r['creados']++;
            }
        });
        $this->auditoria->registrar('campana.importar', $c, null, ['creados' => $r['creados'], 'duplicados' => $r['duplicados'], 'bloqueados' => $r['bloqueados']]);

        return $r;
    }

    /** @return array{asignados:int, sin_agente:int} */
    public function asignar(Campana $campana, User $user): array
    {
        return DB::transaction(function () use ($campana, $user) {
            $c = Campana::whereKey($campana->id)->lockForUpdate()->firstOrFail();
            $agentes = $c->agentes()->where('agentes.activo', true)->orderBy('agentes.id')->get();
            if ($agentes->isEmpty()) {
                throw ValidationException::withMessages(['campana' => 'La campaña no tiene agentes asignados.']);
            }
            $carga = Lead::whereIn('agente_id', $agentes->pluck('id'))->whereIn('estado', Lead::ABIERTOS)->groupBy('agente_id')->selectRaw('agente_id, count(*) as n')->pluck('n', 'agente_id')->all();
            $ultimo = (int) Lead::where('campana_id', $c->id)->whereNotNull('agente_id')->latest('asignado_at')->latest('id')->value('agente_id');
            $pos = $agentes->search(fn ($a) => $a->id === $ultimo);
            $pos = $pos === false ? -1 : $pos;
            $r = ['asignados' => 0, 'sin_agente' => 0];

            foreach (Lead::where('campana_id', $c->id)->where('estado', 'nuevo')->whereNull('agente_id')->orderBy('id')->lockForUpdate()->get() as $lead) {
                $libres = $agentes->filter(fn ($a) => ($carga[$a->id] ?? 0) < $a->capacidad)->values();
                $elegido = match ($c->asignacion) {
                    // Primero quien cubre la comuna; si nadie, quien no tiene zonas (atiende todas).
                    'zona' => ($libres->filter(fn ($a) => $lead->comuna && in_array(mb_strtolower($lead->comuna), array_map('mb_strtolower', $a->zonas ?? []), true))->whenEmpty(fn () => $libres->filter(fn ($a) => empty($a->zonas))))
                        ->sortBy(fn ($a) => [$carga[$a->id] ?? 0, $a->id])->first(),
                    'carga' => $libres->sortBy(fn ($a) => [$carga[$a->id] ?? 0, $a->id])->first(),
                    default => (function () use ($agentes, $libres, &$pos) {
                        for ($i = 1; $i <= $agentes->count(); $i++) {
                            $cand = $agentes[($pos + $i) % $agentes->count()];
                            if ($libres->contains('id', $cand->id)) {
                                $pos = ($pos + $i) % $agentes->count();

                                return $cand;
                            }
                        }

                        return null;
                    })(),
                };
                if (! $elegido) {
                    $r['sin_agente']++;

                    continue;
                }
                $lead->forceFill(['agente_id' => $elegido->id, 'estado' => 'asignado', 'asignado_at' => now()])->save();
                $carga[$elegido->id] = ($carga[$elegido->id] ?? 0) + 1;
                $r['asignados']++;
            }
            $this->auditoria->registrar('campana.asignar', $c, null, $r + ['regla' => $c->asignacion]);

            return $r;
        });
    }

    /** Reasigna prospectos abiertos a otro agente de la misma campaña. */
    public function reasignar(array $leadIds, Agente $agente, User $user): int
    {
        $leads = Lead::whereIn('id', $leadIds)->whereIn('estado', Lead::ABIERTOS)->get();
        foreach ($leads as $l) {
            if (! $agente->campanas()->whereKey($l->campana_id)->exists()) {
                throw ValidationException::withMessages(['agente_id' => "{$agente->nombre()} no participa en la campaña del prospecto {$l->nombre}."]);
            }
        }
        foreach ($leads as $l) {
            $l->forceFill(['agente_id' => $agente->id, 'estado' => $l->estado === 'nuevo' ? 'asignado' : $l->estado, 'asignado_at' => now()])->save();
        }
        $this->auditoria->registrar('leads.reasignar', null, null, ['agente' => $agente->nombre(), 'cantidad' => $leads->count()]);

        return $leads->count();
    }
}
