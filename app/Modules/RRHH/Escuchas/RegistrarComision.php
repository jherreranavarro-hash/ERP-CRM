<?php

namespace App\Modules\RRHH\Escuchas;

use App\Modules\Facturacion\Eventos\DocumentoEmitido;
use App\Modules\RRHH\Models\Comision;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\ReglaComision;

/**
 * Al emitirse un documento de venta, RRHH calcula la comisión del vendedor que
 * lo creó: la tasa de su contrato, o la regla de la categoría del producto
 * (la del vendedor antes que la general). Las notas de crédito restan.
 * Queda por aprobar antes de entrar a la nómina.
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
        if (! $emp?->contratoVigente) {
            return;
        }
        $general = (float) $emp->contratoVigente->comision_pct;
        $reglas = ReglaComision::where(fn ($q) => $q->whereNull('empleado_id')->orWhere('empleado_id', $emp->id))->get()
            ->sortBy(fn ($r) => $r->empleado_id ? 0 : 1)->unique('categoria_id')->keyBy('categoria_id');
        if ($general <= 0 && $reglas->isEmpty()) {
            return;
        }
        // Reparte el neto del documento entre sus líneas (respeta descuentos globales y boletas con IVA incluido).
        $lineas = $d->lineas()->with('producto:id,categoria_id')->get();
        $sumaItems = max(1, (int) $lineas->sum('monto_item'));
        $neto = (int) $d->monto_neto + (int) $d->monto_exento;
        $monto = 0.0;
        $detalle = [];
        foreach ($lineas as $l) {
            $base = $neto * $l->monto_item / $sumaItems;
            $tasa = (float) ($reglas->get($l->producto?->categoria_id)?->tasa ?? $general);
            $monto += $base * $tasa / 100;
            $detalle[(string) $tasa] = ($detalle[(string) $tasa] ?? 0) + $base;
        }
        if (abs($monto) < 0.5) {
            return;
        }
        Comision::firstOrCreate(['documento_id' => $d->id], [
            'empleado_id' => $emp->id, 'periodo' => $d->fecha_emision->format('Y-m'), 'base' => $signo * $neto,
            'tasa' => $neto ? round($monto / $neto * 100, 2) : $general, 'monto' => $signo * (int) round($monto), 'estado' => 'pendiente',
            'detalle' => collect($detalle)->map(fn ($b, $t) => ['tasa' => (float) $t, 'base' => (int) round($b)])->values()->all(),
        ]);
    }
}
