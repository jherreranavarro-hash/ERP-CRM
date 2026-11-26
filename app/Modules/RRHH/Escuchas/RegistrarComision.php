<?php

namespace App\Modules\RRHH\Escuchas;

use App\Modules\Facturacion\Eventos\DocumentoEmitido;
use App\Modules\RRHH\Models\Comision;
use App\Modules\RRHH\Models\Empleado;

/**
 * Al emitirse un documento de venta, RRHH calcula la comisión del vendedor que
 * lo creó según su contrato vigente. Las notas de crédito restan.
 */
class RegistrarComision
{
    public function handle(DocumentoEmitido $e): void
    {
        $d = $e->documento;
        $signo = match ((int) $d->tipo_dte) {
            33, 34, 39, 41, 56 => 1,
            61 => -1,
            default => 0,
        };
        if (! $signo || ! $d->creado_por) {
            return;
        }
        $emp = Empleado::with('contratoVigente')->where('user_id', $d->creado_por)->where('estado', 'activo')->first();
        $tasa = (float) ($emp?->contratoVigente?->comision_pct ?? 0);
        if (! $emp || $tasa <= 0) {
            return;
        }
        $base = $signo * ((int) $d->monto_neto + (int) $d->monto_exento);
        Comision::firstOrCreate(['documento_id' => $d->id], [
            'empleado_id' => $emp->id, 'periodo' => $d->fecha_emision->format('Y-m'), 'base' => $base, 'tasa' => $tasa,
            'monto' => (int) round($base * $tasa / 100),
        ]);
    }
}
