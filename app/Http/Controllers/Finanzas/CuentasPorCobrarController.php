<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Facturacion\Models\Documento;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Finanzas → Cuentas por Cobrar: documentos emitidos con saldo, con antigüedad. */
class CuentasPorCobrarController extends Controller
{
    private const TRAMOS = ['al_dia' => 'Al día', '1_30' => '1–30 días', '31_60' => '31–60 días', '61_90' => '61–90 días', 'mas_90' => 'Más de 90 días'];

    public function index(Request $request)
    {
        $q = Documento::query()->with('cliente:id,razon_social,rut')->where('estado', 'emitido')->where('saldo', '>', 0)
            ->orderByRaw('coalesce(fecha_vencimiento, fecha_emision)');
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where('receptor_razon_social', 'ilike', "%{$b}%");
        }

        $todos = (clone $q)->get();
        $tramos = array_fill_keys(array_keys(self::TRAMOS), 0);
        foreach ($todos as $d) {
            $tramos[$this->tramo($d)] += $d->saldo;
        }

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.cuentas-por-cobrar.exportar'), 403);

            return Csv::descargar('cuentas-por-cobrar', ['Documento', 'Cliente', 'RUT', 'Emisión', 'Vencimiento', 'Total', 'Saldo', 'Tramo'],
                $todos->map(fn (Documento $d) => [$d->nombre(), $d->receptor_razon_social, $d->receptor_rut, $d->fecha_emision->toDateString(), $d->fecha_vencimiento?->toDateString(), $d->monto_total, $d->saldo, self::TRAMOS[$this->tramo($d)]]));
        }

        return Inertia::render('Finanzas/CuentasPorCobrar', [
            'tramos' => collect(self::TRAMOS)->map(fn ($n, $k) => ['clave' => $k, 'nombre' => $n, 'monto' => $tramos[$k]])->values(),
            'total' => array_sum($tramos),
            'documentos' => $q->paginate(25)->withQueryString()->through(fn (Documento $d) => [
                'id' => $d->id, 'nombre' => $d->nombre(), 'cliente' => $d->receptor_razon_social ?? $d->cliente?->razon_social, 'rut' => $d->receptor_rut,
                'emision' => $d->fecha_emision->toDateString(), 'vencimiento' => $d->fecha_vencimiento?->toDateString(), 'total' => $d->monto_total, 'saldo' => $d->saldo,
                'tramo' => self::TRAMOS[$this->tramo($d)], 'vencido' => $this->tramo($d) !== 'al_dia',
            ]),
            'filtros' => $request->only('buscar'),
        ]);
    }

    private function tramo(Documento $d): string
    {
        $vence = $d->fecha_vencimiento ?? $d->fecha_emision;
        $dias = (int) $vence->startOfDay()->diffInDays(now()->startOfDay(), false);

        return match (true) {
            $dias <= 0 => 'al_dia',
            $dias <= 30 => '1_30',
            $dias <= 60 => '31_60',
            $dias <= 90 => '61_90',
            default => 'mas_90',
        };
    }
}
