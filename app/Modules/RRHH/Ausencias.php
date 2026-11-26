<?php

namespace App\Modules\RRHH;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\RRHH\Models\Ausencia;
use App\Modules\RRHH\Models\Empleado;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vacaciones y ausencias con solicitud y aprobación (cuatro ojos). Los días
 * de vacaciones disponibles se calculan por antigüedad: 15 días hábiles al año
 * (1,25 por mes trabajado) más el feriado progresivo del art. 68.
 */
class Ausencias
{
    public function __construct(private Auditoria $auditoria) {}

    public function solicitar(Empleado $e, array $d, User $user): Ausencia
    {
        if (! array_key_exists($d['tipo'], Ausencia::TIPOS)) {
            throw ValidationException::withMessages(['tipo' => 'Tipo de ausencia no válido.']);
        }
        $desde = CarbonImmutable::parse($d['desde']);
        $hasta = CarbonImmutable::parse($d['hasta']);
        if ($hasta->lt($desde)) {
            throw ValidationException::withMessages(['hasta' => 'La fecha final es anterior a la inicial.']);
        }
        $habiles = self::habiles($desde, $hasta);
        if ($habiles === 0 && $d['tipo'] === 'vacaciones') {
            throw ValidationException::withMessages(['desde' => 'El período no tiene días hábiles.']);
        }

        return DB::transaction(function () use ($e, $d, $user, $desde, $hasta, $habiles) {
            Empleado::whereKey($e->id)->lockForUpdate()->first();
            $choca = Ausencia::where('empleado_id', $e->id)->whereIn('estado', ['solicitada', 'aprobada'])
                ->where('desde', '<=', $hasta->toDateString())->where('hasta', '>=', $desde->toDateString())->exists();
            if ($choca) {
                throw ValidationException::withMessages(['desde' => 'Se cruza con otra ausencia solicitada o aprobada.']);
            }
            if ($d['tipo'] === 'vacaciones') {
                $saldo = $this->saldoVacaciones($e, $desde)['disponibles'];
                if ($habiles > $saldo) {
                    throw ValidationException::withMessages(['hasta' => "Solicita {$habiles} días hábiles y tiene {$saldo} disponibles."]);
                }
            }
            $a = Ausencia::create(['empleado_id' => $e->id, 'tipo' => $d['tipo'], 'desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(),
                'dias_habiles' => $habiles, 'detalle' => isset($d['detalle']) ? mb_substr(trim($d['detalle']), 0, 200) : null, 'estado' => 'solicitada', 'solicitado_por' => $user->id]);
            $this->auditoria->registrar('ausencia.solicitar', $a, null, ['empleado' => $e->nombreCompleto(), 'tipo' => $d['tipo'], 'dias' => $habiles]);

            return $a;
        });
    }

    public function resolver(Ausencia $ausencia, bool $aprobar, User $user, ?string $motivo = null): Ausencia
    {
        return DB::transaction(function () use ($ausencia, $aprobar, $user, $motivo) {
            $a = Ausencia::with('empleado')->whereKey($ausencia->id)->lockForUpdate()->firstOrFail();
            if ($a->estado !== 'solicitada') {
                throw ValidationException::withMessages(['ausencia' => 'La solicitud ya está resuelta.']);
            }
            if ($a->solicitado_por === $user->id || $a->empleado->user_id === $user->id) {
                throw ValidationException::withMessages(['ausencia' => 'Una solicitud propia la resuelve otra persona.']);
            }
            if (! $aprobar && trim((string) $motivo) === '') {
                throw ValidationException::withMessages(['motivo' => 'Indica el motivo del rechazo.']);
            }
            if ($aprobar && $a->tipo === 'vacaciones' && $a->dias_habiles > $this->saldoVacaciones($a->empleado, $a->desde->toImmutable(), $a->id)['disponibles']) {
                throw ValidationException::withMessages(['ausencia' => 'Ya no tiene días suficientes: recházala.']);
            }
            $a->forceFill(['estado' => $aprobar ? 'aprobada' : 'rechazada', 'resuelto_por' => $user->id, 'resuelto_at' => now(),
                'motivo_rechazo' => $aprobar ? null : mb_substr(trim($motivo), 0, 200)])->save();
            $this->auditoria->registrar($aprobar ? 'ausencia.aprobar' : 'ausencia.rechazar', $a, null, ['empleado' => $a->empleado->nombreCompleto()]);

            return $a;
        });
    }

    public function anular(Ausencia $ausencia, User $user): Ausencia
    {
        $a = Ausencia::whereKey($ausencia->id)->firstOrFail();
        if (! in_array($a->estado, ['solicitada', 'aprobada'], true) || ($a->estado === 'aprobada' && $a->desde->isPast())) {
            throw ValidationException::withMessages(['ausencia' => 'Solo se anula una ausencia que aún no empieza.']);
        }
        $a->forceFill(['estado' => 'anulada'])->save();
        $this->auditoria->registrar('ausencia.anular', $a);

        return $a;
    }

    /**
     * @return array{devengados:float, progresivos:int, tomados:int, disponibles:int, anios:int}
     */
    public function saldoVacaciones(Empleado $e, ?CarbonImmutable $al = null, ?int $excluir = null): array
    {
        $al ??= CarbonImmutable::today();
        $ingreso = CarbonImmutable::parse($e->fecha_ingreso);
        $meses = max(0, (int) $ingreso->diffInMonths($al));
        $anios = intdiv($meses, 12);
        $devengados = round($meses * 1.25, 2);
        // Feriado progresivo: con 10 años trabajados (hasta 10 con empleadores anteriores),
        // un día más por cada 3 años nuevos con el empleador actual.
        $umbral = 10 - min(10, (int) $e->anios_previos);
        $progresivos = 0;
        for ($a = 1; $a <= $anios; $a++) {
            $progresivos += intdiv(max(0, $a - $umbral), 3);
        }
        $tomados = (int) Ausencia::where('empleado_id', $e->id)->where('tipo', 'vacaciones')->whereIn('estado', ['solicitada', 'aprobada'])
            ->when($excluir, fn ($q) => $q->whereKeyNot($excluir))->sum('dias_habiles');

        return ['devengados' => $devengados, 'progresivos' => $progresivos, 'tomados' => $tomados, 'disponibles' => (int) floor($devengados + $progresivos - $tomados), 'anios' => $anios];
    }

    /** Días hábiles (lunes a viernes) entre dos fechas, inclusive. */
    public static function habiles(CarbonImmutable $desde, CarbonImmutable $hasta): int
    {
        $n = 0;
        for ($d = $desde; $d->lte($hasta); $d = $d->addDay()) {
            $n += $d->isWeekday() ? 1 : 0;
        }

        return $n;
    }

    /** Días corridos de ausencias no pagadas (licencias, permisos sin goce) dentro de un mes. */
    public function diasNoPagados(Empleado $e, CarbonImmutable $inicio, CarbonImmutable $fin): int
    {
        $n = 0;
        foreach (Ausencia::where('empleado_id', $e->id)->where('estado', 'aprobada')->whereIn('tipo', Ausencia::NO_PAGADAS)
            ->where('desde', '<=', $fin->toDateString())->where('hasta', '>=', $inicio->toDateString())->get() as $a) {
            $d1 = $a->desde->max($inicio);
            $d2 = $a->hasta->min($fin);
            $n += (int) $d1->diffInDays($d2) + 1;
        }

        return $n;
    }
}
