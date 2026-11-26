<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Facturacion\Models\Documento;
use App\Support\Exportacion\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Finanzas → Libro de Ventas, generado desde los documentos emitidos. */
class LibroVentasController extends Controller
{
    public function index(Request $request)
    {
        $mes = CarbonImmutable::createFromFormat('Y-m', $request->input('mes', now()->format('Y-m')))->startOfMonth();
        $base = Documento::query()->whereNotNull('folio')->whereIn('tipo_dte', [33, 34, 39, 41, 56, 61])
            ->whereBetween('fecha_emision', [$mes->toDateString(), $mes->endOfMonth()->toDateString()]);

        // Las notas de crédito restan.
        $signo = 'CASE WHEN tipo_dte = 61 THEN -1 ELSE 1 END';
        $resumen = (clone $base)->groupBy('tipo_dte')->orderBy('tipo_dte')
            ->get(['tipo_dte', DB::raw('count(*) as cantidad'), DB::raw("sum(monto_neto * {$signo}) as neto"), DB::raw("sum(monto_exento * {$signo}) as exento"), DB::raw("sum(monto_iva * {$signo}) as iva"), DB::raw("sum(monto_total * {$signo}) as total")])
            ->map(fn ($r) => ['tipo' => Documento::TIPOS[$r->tipo_dte], 'cantidad' => (int) $r->cantidad, 'neto' => (int) $r->neto, 'exento' => (int) $r->exento, 'iva' => (int) $r->iva, 'total' => (int) $r->total]);

        $detalle = (clone $base)->orderBy('fecha_emision')->orderBy('tipo_dte')->orderBy('folio');

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.libro-de-ventas.exportar'), 403);

            return Csv::descargar('libro-ventas-'.$mes->format('Y-m'), ['Tipo', 'Folio', 'Fecha', 'RUT', 'Razón social', 'Neto', 'Exento', 'IVA', 'Total', 'Estado'],
                $detalle->cursor()->map(fn (Documento $d) => [$d->tipo_dte, $d->folio, $d->fecha_emision->toDateString(), $d->receptor_rut, $d->receptor_razon_social, $d->monto_neto, $d->monto_exento, $d->monto_iva, $d->monto_total, $d->estado]));
        }

        return Inertia::render('Finanzas/LibroVentas', [
            'mes' => $mes->format('Y-m'),
            'resumen' => $resumen,
            'totales' => ['neto' => $resumen->sum('neto'), 'exento' => $resumen->sum('exento'), 'iva' => $resumen->sum('iva'), 'total' => $resumen->sum('total'), 'cantidad' => $resumen->sum('cantidad')],
            'documentos' => $detalle->paginate(50)->withQueryString()->through(fn (Documento $d) => [
                'id' => $d->id, 'tipo' => Documento::CORTOS[$d->tipo_dte], 'tipo_dte' => $d->tipo_dte, 'folio' => $d->folio, 'fecha' => $d->fecha_emision->toDateString(),
                'rut' => $d->receptor_rut, 'razon_social' => $d->receptor_razon_social, 'neto' => $d->monto_neto, 'exento' => $d->monto_exento, 'iva' => $d->monto_iva, 'total' => $d->monto_total, 'estado' => $d->estado,
            ]),
        ]);
    }
}
