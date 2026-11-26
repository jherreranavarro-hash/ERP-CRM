<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\OrdenCompraLinea;
use App\Modules\Compras\OrdenesCompra;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Compras → Orden de Compra: borrador, comparación de cotizaciones, aprobación, recepción y cierre. */
class OrdenesCompraController extends Controller
{
    public function __construct(private OrdenesCompra $ordenes) {}

    public function index(Request $request)
    {
        $q = OrdenCompra::with('proveedor:id,razon_social,rut', 'local:id,nombre', 'creador:id,name')->orderByDesc('numero');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => ctype_digit($b) ? $q->where('numero', (int) $b) : $q->whereHas('proveedor', fn ($p) => $p->where('razon_social', 'ilike', "%{$b}%")));
        }
        $fila = fn (OrdenCompra $o) => [
            'id' => $o->id, 'numero' => $o->numero, 'fecha' => $o->fecha->toDateString(), 'proveedor' => $o->proveedor?->razon_social, 'local' => $o->local?->nombre,
            'entrega' => $o->fecha_entrega?->toDateString(), 'neto' => $o->monto_neto, 'total' => $o->monto_total, 'estado' => $o->estado, 'creador' => $o->creador?->name,
        ];

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('compras.orden-de-compra.exportar'), 403);

            return Csv::descargar('ordenes-de-compra', ['N°', 'Fecha', 'Proveedor', 'Local', 'Entrega', 'Neto', 'Total', 'Estado', 'Creada por'],
                $q->get()->map(fn ($o) => array_values(array_diff_key($fila($o), ['id' => 1]))));
        }

        return Inertia::render('Compras/Ordenes/Index', [
            'ordenes' => $q->paginate(25)->withQueryString()->through($fila),
            'filtros' => $request->only('estado', 'buscar'),
            'estados' => OrdenCompra::ESTADOS,
            'pendientes' => OrdenCompra::where('estado', 'por_aprobar')->count(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Compras/Ordenes/Form', ['orden' => null] + $this->opciones());
    }

    public function store(Request $request)
    {
        $oc = $this->ordenes->guardar($this->validar($request), $request->user());

        return to_route('compras.ordenes.show', $oc)->with('exito', "Orden de compra N° {$oc->numero} guardada en borrador. Envíala cuando esté lista.");
    }

    public function show(Request $request, OrdenCompra $orden)
    {
        $orden->load('lineas.producto:id,sku,nombre,unidad', 'cotizaciones', 'proveedor', 'local:id,nombre', 'creador:id,name', 'aprobador:id,name',
            'recepciones:id,orden_compra_id,numero,fecha,estado,costo_total', 'facturas:id,orden_compra_id,tipo_dte,folio,fecha_emision,monto_total,estado');

        return Inertia::render('Compras/Ordenes/Show', [
            'orden' => $this->detalle($orden) + [
                'propia' => $orden->creado_por === $request->user()->id,
                'recepciones' => $orden->recepciones->sortByDesc('numero')->map(fn ($r) => ['id' => $r->id, 'numero' => $r->numero, 'fecha' => $r->fecha->toDateString(), 'estado' => $r->estado, 'costo_total' => $r->costo_total])->values(),
                'facturas' => $orden->facturas->map(fn ($f) => ['id' => $f->id, 'nombre' => $f->nombre(), 'fecha' => $f->fecha_emision->toDateString(), 'total' => $f->monto_total, 'estado' => $f->estado])->values(),
            ],
            'estados' => OrdenCompra::ESTADOS,
        ]);
    }

    public function edit(OrdenCompra $orden)
    {
        abort_unless($orden->estado === 'borrador', 404);
        $orden->load('lineas.producto:id,sku,nombre,unidad', 'cotizaciones', 'proveedor');

        return Inertia::render('Compras/Ordenes/Form', ['orden' => $this->detalle($orden)] + $this->opciones());
    }

    public function update(Request $request, OrdenCompra $orden)
    {
        $this->ordenes->guardar($this->validar($request), $request->user(), $orden);

        return to_route('compras.ordenes.show', $orden)->with('exito', 'Orden de compra actualizada.');
    }

    public function destroy(OrdenCompra $orden)
    {
        abort_unless($orden->estado === 'borrador', 403, 'Solo se elimina un borrador; una orden enviada se anula.');
        $orden->delete();

        return to_route('compras.ordenes.index')->with('exito', 'Borrador eliminado.');
    }

    public function enviar(Request $request, OrdenCompra $orden)
    {
        $oc = $this->ordenes->enviar($orden, $request->user());

        return back()->with('exito', $oc->estado === 'aprobada'
            ? "Orden N° {$oc->numero} aprobada: ya se puede enviar al proveedor y recibir."
            : "Orden N° {$oc->numero} enviada: espera la aprobación de otra persona.");
    }

    public function aprobar(Request $request, OrdenCompra $orden)
    {
        $this->ordenes->aprobar($orden, $request->user());

        return back()->with('exito', 'Orden de compra aprobada.');
    }

    public function rechazar(Request $request, OrdenCompra $orden)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->ordenes->rechazar($orden, $request->user(), $d['motivo']);

        return back()->with('exito', 'Orden de compra rechazada.');
    }

    public function anular(Request $request, OrdenCompra $orden)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->ordenes->anular($orden, $request->user(), $d['motivo']);

        return back()->with('exito', 'Orden de compra anulada.');
    }

    public function cerrar(Request $request, OrdenCompra $orden)
    {
        $this->ordenes->cerrar($orden, $request->user());

        return back()->with('exito', 'Orden cerrada: lo pendiente ya no se espera.');
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'proveedor_id' => ['required', 'integer'],
            'local_id' => ['required', 'integer', 'exists:locales,id'],
            'fecha_entrega' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'lineas' => ['required', 'array', 'min:1', 'max:'.OrdenesCompra::MAX_LINEAS],
            'lineas.*.producto_id' => ['required', 'integer'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'lineas.*.costo_unitario' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'cotizaciones' => ['nullable', 'array', 'max:5'],
            'cotizaciones.*.proveedor_nombre' => ['nullable', 'string', 'max:100'],
            'cotizaciones.*.monto_neto' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'cotizaciones.*.plazo_entrega_dias' => ['nullable', 'integer', 'min:0', 'max:365'],
            'cotizaciones.*.observacion' => ['nullable', 'string', 'max:200'],
            'cotizaciones.*.elegida' => ['boolean'],
        ], [], ['lineas' => 'productos']);
    }

    private function opciones(): array
    {
        $p = app(Parametros::class);

        return [
            'locales' => Local::where('activo', true)->orderByDesc('es_bodega')->orderBy('nombre')->get(['id', 'nombre']),
            'reglas' => [
                'aprobacion' => (int) $p->valor('compras.oc_aprobacion_monto'),
                'cotizaciones_desde' => (int) $p->valor('compras.cotizaciones_desde'),
                'cotizaciones_minimas' => (int) $p->valor('compras.cotizaciones_minimas'),
                'tasa_iva' => (float) $p->valor('tributario.tasa_iva'),
            ],
        ];
    }

    private function detalle(OrdenCompra $o): array
    {
        $dec = \App\Modules\Inventario\Inventario::dec(...);

        return [
            'id' => $o->id, 'numero' => $o->numero, 'estado' => $o->estado, 'fecha' => $o->fecha->toDateString(), 'fecha_entrega' => $o->fecha_entrega?->toDateString(),
            'proveedor_id' => $o->proveedor_id, 'proveedor' => $o->proveedor ? ['id' => $o->proveedor->id, 'razon_social' => $o->proveedor->razon_social, 'rut' => $o->proveedor->rut, 'plazo_pago_dias' => $o->proveedor->plazo_pago_dias] : null,
            'local_id' => $o->local_id, 'local' => $o->local?->nombre, 'observaciones' => $o->observaciones,
            'neto' => $o->monto_neto, 'iva' => $o->monto_iva, 'total' => $o->monto_total, 'motivos' => $o->motivos ?? [], 'motivo_rechazo' => $o->motivo_rechazo,
            'creador' => $o->creador?->name, 'aprobador' => $o->aprobador?->name, 'aprobado_at' => $o->aprobado_at?->toIso8601String(),
            'lineas' => $o->lineas->map(fn (OrdenCompraLinea $l) => [
                'id' => $l->id, 'producto_id' => $l->producto_id, 'sku' => $l->producto?->sku, 'nombre' => $l->producto?->nombre, 'unidad' => $l->producto?->unidad,
                'cantidad' => $dec($l->cantidad), 'costo_unitario' => $dec($l->costo_unitario), 'monto' => $l->monto,
                'recibida' => $dec($l->cantidad_recibida), 'facturada' => $dec($l->cantidad_facturada),
            ])->all(),
            'cotizaciones' => $o->cotizaciones->map(fn ($c) => ['proveedor_id' => $c->proveedor_id, 'proveedor_nombre' => $c->proveedor_nombre, 'monto_neto' => $c->monto_neto,
                'plazo_entrega_dias' => $c->plazo_entrega_dias, 'observacion' => $c->observacion, 'elegida' => $c->elegida])->all(),
        ];
    }
}
