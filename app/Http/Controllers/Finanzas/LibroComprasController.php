<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\FacturaProveedor;
use App\Support\Exportacion\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Finanzas → Libro de Compras, generado desde las facturas de proveedor registradas (las rechazadas no entran). */
class LibroComprasController extends Controller
{
    public function index(Request $request)
    {
        $mes = CarbonImmutable::createFromFormat('Y-m', $request->input('mes', now()->format('Y-m')))->startOfMonth();
        $base = FacturaProveedor::query()->where('estado', '<>', 'rechazada')
            ->whereBetween('fecha_emision', [$mes->toDateString(), $mes->endOfMonth()->toDateString()]);

        $resumen = (clone $base)->groupBy('tipo_dte')->orderBy('tipo_dte')
            ->get(['tipo_dte', DB::raw('count(*) as cantidad'), DB::raw('sum(monto_neto) as neto'), DB::raw('sum(monto_exento) as exento'), DB::raw('sum(monto_iva) as iva'), DB::raw('sum(monto_total) as total')])
            ->map(fn ($r) => ['tipo' => FacturaProveedor::TIPOS[$r->tipo_dte], 'cantidad' => (int) $r->cantidad, 'neto' => (int) $r->neto, 'exento' => (int) $r->exento, 'iva' => (int) $r->iva, 'total' => (int) $r->total]);

        $detalle = (clone $base)->with('proveedor:id,rut,razon_social')->orderBy('fecha_emision')->orderBy('tipo_dte')->orderBy('folio');

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.libro-de-compras.exportar'), 403);

            return Csv::descargar('libro-compras-'.$mes->format('Y-m'), ['Tipo', 'Folio', 'Fecha', 'RUT', 'Razón social', 'Neto', 'Exento', 'IVA', 'Total', 'Estado'],
                $detalle->cursor()->map(fn (FacturaProveedor $f) => [$f->tipo_dte, $f->folio, $f->fecha_emision->toDateString(), $f->proveedor?->rut, $f->proveedor?->razon_social, $f->monto_neto, $f->monto_exento, $f->monto_iva, $f->monto_total, $f->estado]));
        }

        return Inertia::render('Finanzas/LibroCompras', [
            'mes' => $mes->format('Y-m'),
            'resumen' => $resumen,
            'totales' => ['neto' => $resumen->sum('neto'), 'exento' => $resumen->sum('exento'), 'iva' => $resumen->sum('iva'), 'total' => $resumen->sum('total'), 'cantidad' => $resumen->sum('cantidad')],
            'facturas' => $detalle->paginate(50)->withQueryString()->through(fn (FacturaProveedor $f) => [
                'id' => $f->id, 'tipo' => FacturaProveedor::TIPOS[$f->tipo_dte], 'tipo_dte' => $f->tipo_dte, 'folio' => $f->folio, 'fecha' => $f->fecha_emision->toDateString(),
                'rut' => $f->proveedor?->rut, 'razon_social' => $f->proveedor?->razon_social, 'neto' => $f->monto_neto, 'exento' => $f->monto_exento, 'iva' => $f->monto_iva, 'total' => $f->monto_total, 'estado' => $f->estado,
            ]),
        ]);
    }
}
