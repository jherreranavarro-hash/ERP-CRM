<?php

namespace App\Modules\RRHH;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\RRHH\Integraciones\RelojControl;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Marca;
use App\Support\Rut;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Control de asistencia: marcas del reloj control, de la app o manuales (con
 * motivo), y el cálculo diario de horas trabajadas, atrasos y horas extra
 * contra el turno asignado.
 */
class Asistencia
{
    public const ZONA = 'America/Santiago';

    public function __construct(private Parametros $parametros, private Auditoria $auditoria, private RelojControl $reloj) {}

    public function marcar(Empleado $e, string $tipo, string $momento, string $origen, ?User $user, ?string $motivo = null): Marca
    {
        if (! in_array($tipo, ['entrada', 'salida'], true) || ! array_key_exists($origen, Marca::ORIGENES)) {
            throw ValidationException::withMessages(['tipo' => 'Marca no válida.']);
        }
        if ($origen === 'manual' && trim((string) $motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Una marca manual necesita el motivo (ej. olvido de marcación).']);
        }
        if ($e->estado !== 'activo') {
            throw ValidationException::withMessages(['empleado' => 'La persona no está activa.']);
        }
        $m = Marca::create(['empleado_id' => $e->id, 'tipo' => $tipo, 'momento' => CarbonImmutable::parse($momento, self::ZONA), 'origen' => $origen,
            'motivo' => $motivo ? mb_substr(trim($motivo), 0, 200) : null, 'registrado_por' => $user?->id]);
        if ($origen === 'manual') {
            $this->auditoria->registrar('asistencia.marca_manual', $m, null, ['empleado' => $e->nombreCompleto(), 'tipo' => $tipo, 'momento' => $momento, 'motivo' => $motivo]);
        }

        return $m;
    }

    /** Importa el archivo del reloj control. Las marcas repetidas (misma huella) se omiten. @return array{nuevas:int, repetidas:int, desconocidas:list<string>} */
    public function importar(string $contenido, User $user): array
    {
        $marcas = $this->reloj->leer($contenido);
        $empleados = Empleado::where('estado', 'activo')->get(['id', 'rut'])->keyBy('rut');
        $r = ['nuevas' => 0, 'repetidas' => 0, 'desconocidas' => []];
        DB::transaction(function () use ($marcas, $empleados, &$r) {
            foreach ($marcas as $m) {
                $rut = Rut::normalizar($m['rut']);
                $e = $rut ? $empleados->get($rut) : null;
                if (! $e) {
                    $r['desconocidas'][] = $m['rut'];

                    continue;
                }
                $momento = CarbonImmutable::parse($m['momento'], self::ZONA);
                $huella = hash('sha256', $e->id.'|'.$momento->utc()->toIso8601String().'|'.$m['tipo']);
                $ins = DB::table('marcas')->insertOrIgnore([
                    'empresa_id' => app(\App\Modules\Core\Tenancy\Tenancy::class)->id(), 'empleado_id' => $e->id, 'tipo' => $m['tipo'], 'momento' => $momento->format('Y-m-d H:i:sP'),
                    'origen' => 'reloj', 'huella' => $huella, 'created_at' => now(),
                ]);
                $ins ? $r['nuevas']++ : $r['repetidas']++;
            }
        });
        $r['desconocidas'] = array_values(array_unique($r['desconocidas']));
        $this->auditoria->registrar('asistencia.importar', null, null, ['nuevas' => $r['nuevas'], 'repetidas' => $r['repetidas'], 'desconocidas' => count($r['desconocidas'])]);

        return $r;
    }

    /**
     * Resumen diario entre dos fechas (inclusive): primera entrada, última salida,
     * minutos trabajados, del turno, atraso y extra (con el tope diario legal).
     *
     * @return Collection<int, array{fecha:string, entrada:?string, salida:?string, trabajados:int, turno:int, atraso:int, extra:int, estado:string}>
     */
    public function diario(Empleado $e, string $desde, string $hasta): Collection
    {
        $e->loadMissing('turno');
        $inicio = CarbonImmutable::parse($desde, self::ZONA)->startOfDay();
        $fin = CarbonImmutable::parse($hasta, self::ZONA)->endOfDay();
        $marcas = Marca::where('empleado_id', $e->id)->whereBetween('momento', [$inicio->format('Y-m-d H:i:sP'), $fin->format('Y-m-d H:i:sP')])->orderBy('momento')->get()
            ->groupBy(fn ($m) => $m->momento->setTimezone(self::ZONA)->toDateString());
        $maxExtra = (int) $this->parametros->valor('nomina.max_horas_extra_dia') * 60;
        $dias = collect();
        for ($d = $inicio; $d->lte($fin); $d = $d->addDay()) {
            $fecha = $d->toDateString();
            $delDia = $marcas->get($fecha, collect());
            $laboral = $e->turno && in_array($d->dayOfWeekIso, $e->turno->dias ?? [], true);
            $turno = $laboral ? $e->turno->minutos() : 0;
            $entrada = $delDia->firstWhere('tipo', 'entrada')?->momento->setTimezone(self::ZONA);
            $salida = $delDia->where('tipo', 'salida')->last()?->momento->setTimezone(self::ZONA);
            $trabajados = 0;
            $atraso = 0;
            if ($entrada && $salida && $salida->gt($entrada)) {
                $trabajados = (int) $entrada->diffInMinutes($salida) - ($laboral ? $e->turno->colacion_min : 0);
                $trabajados = max(0, $trabajados);
            }
            if ($laboral && $entrada) {
                $inicioTurno = CarbonImmutable::parse($fecha.' '.$e->turno->hora_inicio, self::ZONA);
                $atraso = max(0, (int) $inicioTurno->diffInMinutes($entrada, false));
            }
            $extra = $laboral ? min($maxExtra, max(0, $trabajados - $turno)) : 0;
            $estado = match (true) {
                ! $laboral && ! $entrada => 'libre',
                $laboral && ! $entrada => $d->isFuture() ? 'pendiente' : 'ausente',
                ! $salida => 'sin_salida',
                default => 'completo',
            };
            $dias->push(['fecha' => $fecha, 'entrada' => $entrada?->format('H:i'), 'salida' => $salida?->format('H:i'), 'trabajados' => $trabajados, 'turno' => $turno,
                'atraso' => $atraso, 'extra' => $extra, 'excedido' => $laboral && $trabajados - $turno > $maxExtra, 'estado' => $estado]);
        }

        return $dias;
    }

    /** Horas extra del mes (AAAA-MM), con dos decimales. */
    public function horasExtra(Empleado $e, string $periodo): string
    {
        $inicio = CarbonImmutable::parse($periodo.'-01');
        $min = $this->diario($e, $inicio->toDateString(), $inicio->endOfMonth()->toDateString())->sum('extra');

        return number_format($min / 60, 2, '.', '');
    }
}
