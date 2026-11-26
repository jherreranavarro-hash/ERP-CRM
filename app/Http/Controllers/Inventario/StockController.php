<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Models\Local;
use App\Modules\Inventario\Inventario;
use App\Modules\Inventario\Models\MovimientoStock;
use App\Modules\Inventario\Models\Stock;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Inventario → Stock: existencias por local (físico, reservado, disponible) y kardex por producto. */
class StockController extends Controller
{
    public function index(Request $request)
    {
        $locales = Local::where('activo', true)->orderBy('es_bodega')->orderBy('nombre')->get(['id', 'nombre']);
        $localId = $request->integer('local_id') ?: $locales->first()?->id;

        $reservas = DB::table('orden_venta_lineas as l')->join('ordenes_venta as o', 'o.id', '=', 'l.orden_venta_id')
            ->where('o.estado', 'confirmada')->where('o.reserva_hasta', '>=', now()->toDateString())->where('o.local_id', $localId)
            ->groupBy('l.producto_id')->selectRaw('l.producto_id, sum(l.cantidad) as reservado');

        $q = Producto::query()->where('productos.activo', true)
            ->leftJoin('stock as s', fn ($j) => $j->on('s.producto_id', '=', 'productos.id')->where('s.local_id', $localId))
            ->leftJoinSub($reservas, 'r', 'r.producto_id', '=', 'productos.id')
            ->select('productos.id', 'productos.sku', 'productos.nombre', 'productos.unidad',
                DB::raw('coalesce(s.cantidad, 0) as fisico'), DB::raw('coalesce(r.reservado, 0) as reservado'),
                DB::raw('coalesce(s.costo_promedio, 0) as costo'), 's.minimo')
            ->orderBy('productos.nombre');
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => $q->where('productos.nombre', 'ilike', "%{$b}%")->orWhere('productos.sku', 'ilike', "%{$b}%"));
        }
        if ($request->input('filtro') === 'bajo') {
            $q->whereNotNull('s.minimo')->whereColumn('s.cantidad', '<=', 's.minimo');
        } elseif ($request->input('filtro') === 'sin') {
            $q->whereRaw('coalesce(s.cantidad, 0) - coalesce(r.reservado, 0) <= 0');
        }

        $fila = fn ($p) => [
            'id' => $p->id, 'sku' => $p->sku, 'nombre' => $p->nombre, 'unidad' => $p->unidad,
            'fisico' => (float) $p->fisico, 'reservado' => (float) $p->reservado, 'disponible' => (float) $p->fisico - (float) $p->reservado,
            'costo' => (float) $p->costo, 'valorizado' => (int) round((float) $p->fisico * (float) $p->costo), 'minimo' => $p->minimo !== null ? (float) $p->minimo : null,
        ];

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('inventario.stock.exportar'), 403);

            return Csv::descargar('stock', ['SKU', 'Producto', 'Unidad', 'Físico', 'Reservado', 'Disponible', 'Costo promedio', 'Valorizado'],
                $q->get()->map(fn ($p) => array_values(array_diff_key($fila($p), ['id' => 1, 'minimo' => 1]))));
        }

        $valor = (int) Stock::where('local_id', $localId)->where('cantidad', '>', 0)->sum(DB::raw('round(cantidad * costo_promedio)'));

        return Inertia::render('Inventario/Stock', [
            'productos' => $q->paginate(50)->withQueryString()->through($fila),
            'locales' => $locales,
            'filtros' => ['local_id' => $localId] + $request->only('buscar', 'filtro'),
            'resumen' => [
                'valorizado' => $valor,
                'bajo_minimo' => Stock::where('local_id', $localId)->whereNotNull('minimo')->whereColumn('cantidad', '<=', 'minimo')->count(),
            ],
        ]);
    }

    public function kardex(Request $request, Producto $producto)
    {
        $locales = Local::where('activo', true)->orderBy('es_bodega')->orderBy('nombre')->get(['id', 'nombre']);
        $localId = $request->integer('local_id') ?: $locales->first()?->id;
        $stock = Stock::where('producto_id', $producto->id)->where('local_id', $localId)->first();
        $inv = app(Inventario::class);

        return Inertia::render('Inventario/Kardex', [
            'producto' => $producto->only(['id', 'sku', 'nombre', 'unidad']),
            'locales' => $locales,
            'localId' => $localId,
            'stock' => [
                'fisico' => (float) ($stock->cantidad ?? 0), 'reservado' => $localId ? (float) (string) $inv->reservado($producto->id, $localId) : 0.0,
                'costo' => (float) ($stock->costo_promedio ?? 0), 'minimo' => $stock?->minimo !== null ? (float) $stock->minimo : null,
            ],
            'movimientos' => MovimientoStock::with('usuario:id,name')->where('producto_id', $producto->id)->where('local_id', $localId)->orderByDesc('id')
                ->paginate(50)->withQueryString()->through(fn (MovimientoStock $m) => [
                    'id' => $m->id, 'fecha' => $m->created_at->toIso8601String(), 'tipo' => MovimientoStock::TIPOS[$m->tipo] ?? $m->tipo,
                    'cantidad' => (float) $m->cantidad, 'costo' => $m->costo_unitario !== null ? (float) $m->costo_unitario : null,
                    'saldo' => (float) $m->saldo_cantidad, 'costo_promedio' => (float) $m->saldo_costo_promedio, 'referencia' => $m->referencia,
                    'usuario' => $m->usuario?->name, 'enlace' => match ($m->origen_tipo) {
                        'documento' => "/caja/documentos/{$m->origen_id}", 'recepcion' => "/inventario/recepciones/{$m->origen_id}", default => null,
                    },
                ]),
        ]);
    }

    public function minimo(Request $request, Producto $producto)
    {
        $d = $request->validate(['local_id' => ['required', 'integer', 'exists:locales,id'], 'minimo' => ['nullable', 'numeric', 'min:0']]);
        Stock::firstOrCreate(['producto_id' => $producto->id, 'local_id' => $d['local_id']])->forceFill(['minimo' => $d['minimo']])->save();

        return back()->with('exito', 'Stock mínimo actualizado.');
    }
}
