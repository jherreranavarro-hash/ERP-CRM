<?php

namespace App\Modules\RRHH;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Finanzas\Contabilidad;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Prestamo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Préstamos al personal: se aprueban con cuatro ojos, se contabilizan al
 * otorgarse y se descuentan en cuotas en la nómina (con el tope legal del 15%).
 */
class Prestamos
{
    public function __construct(private Parametros $parametros, private Auditoria $auditoria, private Contabilidad $contabilidad) {}

    public function solicitar(Empleado $e, array $d, User $user): Prestamo
    {
        $e->loadMissing('contratoVigente');
        if (! $e->contratoVigente) {
            throw ValidationException::withMessages(['empleado' => 'La persona no tiene un contrato vigente.']);
        }
        $monto = (int) $d['monto'];
        $cuotas = (int) $d['cuotas'];
        if ($monto <= 0 || $cuotas < 1 || $cuotas > 48) {
            throw ValidationException::withMessages(['monto' => 'Monto mayor que cero y entre 1 y 48 cuotas.']);
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $d['primer_periodo'])) {
            throw ValidationException::withMessages(['primer_periodo' => 'Indica el mes de la primera cuota.']);
        }
        $cuota = (int) ceil($monto / $cuotas);
        $tope = (int) floor($e->contratoVigente->sueldo_base * (float) $this->parametros->valor('nomina.prestamo_max_cuota_pct') / 100);
        $otras = (int) Prestamo::where('empleado_id', $e->id)->whereIn('estado', ['por_aprobar', 'vigente'])->sum('valor_cuota');
        if ($cuota + $otras > $tope) {
            throw ValidationException::withMessages(['cuotas' => 'La cuota ($'.number_format($cuota, 0, ',', '.').') más las vigentes supera el tope de descuento ($'.number_format($tope, 0, ',', '.').'): aumenta las cuotas.']);
        }
        $p = Prestamo::create(['empleado_id' => $e->id, 'monto' => $monto, 'cuotas' => $cuotas, 'valor_cuota' => $cuota, 'saldo' => $monto,
            'primer_periodo' => $d['primer_periodo'], 'motivo' => mb_substr(trim($d['motivo']), 0, 200), 'estado' => 'por_aprobar', 'solicitado_por' => $user->id]);
        $this->auditoria->registrar('prestamo.solicitar', $p, null, ['empleado' => $e->nombreCompleto(), 'monto' => $monto, 'cuotas' => $cuotas]);

        return $p;
    }

    public function resolver(Prestamo $prestamo, bool $aprobar, User $user): Prestamo
    {
        return DB::transaction(function () use ($prestamo, $aprobar, $user) {
            $p = Prestamo::with('empleado')->whereKey($prestamo->id)->lockForUpdate()->firstOrFail();
            if ($p->estado !== 'por_aprobar') {
                throw ValidationException::withMessages(['prestamo' => 'El préstamo ya está resuelto.']);
            }
            if ($p->solicitado_por === $user->id || $p->empleado->user_id === $user->id) {
                throw ValidationException::withMessages(['prestamo' => 'Lo aprueba otra persona.']);
            }
            $p->forceFill(['estado' => $aprobar ? 'vigente' : 'rechazado', 'aprobado_por' => $user->id, 'aprobado_at' => now()])->save();
            if ($aprobar) {
                $this->contabilidad->automatico('prestamo', $p->id, now()->toDateString(), "Préstamo al personal · {$p->empleado->nombreCompleto()}", [
                    ['prestamos_personal', $p->monto, 0], ['banco', 0, $p->monto],
                ], $user);
            }
            $this->auditoria->registrar($aprobar ? 'prestamo.aprobar' : 'prestamo.rechazar', $p, null, ['empleado' => $p->empleado->nombreCompleto(), 'monto' => $p->monto]);

            return $p;
        });
    }

    /** Cuota a descontar en el período (la última ajusta el saldo). */
    public static function cuota(Prestamo $p, string $periodo): int
    {
        return $p->estado === 'vigente' && $p->primer_periodo <= $periodo ? min($p->valor_cuota, $p->saldo) : 0;
    }
}
