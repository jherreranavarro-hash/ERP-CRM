<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Modules\Compras\FacturasProveedor;
use App\Modules\Compras\Models\FacturaProveedor;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\PagoProveedor;
use App\Modules\Core\Parametros\Parametros;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Compras → Factura de Proveedores: registro con 3-way match contra OC y recepción. */
class FacturasProveedorController extends Controller
{
    public function __construct(private FacturasProveedor $facturas) {}

    public function index(Request $request)
    {
        $q = FacturaProveedor::with('proveedor:id,razon_social,rut', 'ordenCompra:id,numero')->orderByDesc('fecha_emision')->orderByDesc('id');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => ctype_digit($b) ? $q->where('folio', (int) $b) : $q->whereHas('proveedor', fn ($p) => $p->where('razon_social', 'ilike', "%{$b}%")));
        }
        $fila = fn (FacturaProveedor $f) => [
            'id' => $f->id, 'nombre' => $f->nombre(), 'proveedor' => $f->proveedor?->razon_social, 'rut' => $f->proveedor?->rut, 'emision' => $f->fecha_emision->toDateString(),
            'vencimiento' => $f->fecha_vencimiento->toDateString(), 'oc' => $f->ordenCompra?->numero, 'total' => $f->monto_total, 'saldo' => $f->saldo, 'estado' => $f->estado,
        ];

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('compras.factura-de-proveedores.exportar'), 403);

            return Csv::descargar('facturas-proveedor', ['Documento', 'Proveedor', 'RUT', 'Emisión', 'Vencimiento', 'OC', 'Total', 'Saldo', 'Estado'],
                $q->get()->map(fn ($f) => array_values(array_diff_key($fila($f), ['id' => 1]))));
        }

        return Inertia::render('Compras/Facturas/Index', [
            'facturas' => $q->paginate(25)->withQueryString()->through($fila),
            'filtros' => $request->only('estado', 'buscar'),
            'estados' => FacturaProveedor::ESTADOS,
            'porRevisar' => FacturaProveedor::where('estado', 'por_revisar')->count(),
        ]);
    }

    public function create(Request $request)
    {
        $oc = null;
        if ($id = $request->integer('orden_compra_id')) {
            $o = OrdenCompra::with('proveedor', 'lineas.producto:id,sku,nombre,unidad')->findOrFail($id);
            $dec = \App\Modules\Inventario\Inventario::dec(...);
            $oc = [
                'id' => $o->id, 'numero' => $o->numero, 'proveedor' => ['id' => $o->proveedor->id, 'razon_social' => $o->proveedor->razon_social, 'rut' => $o->proveedor->rut, 'plazo_pago_dias' => $o->proveedor->plazo_pago_dias],
                'lineas' => $o->lineas->map(fn ($l) => [
                    'orden_compra_linea_id' => $l->id, 'producto_id' => $l->producto_id, 'nombre' => $l->producto?->nombre, 'sku' => $l->producto?->sku, 'unidad' => $l->producto?->unidad,
                    'costo_oc' => $dec($l->costo_unitario), 'por_facturar' => $dec($l->porFacturar()),
                ])->all(),
            ];
        }
        $p = app(Parametros::class);

        return Inertia::render('Compras/Facturas/Form', [
            'orden' => $oc,
            'tipos' => FacturaProveedor::TIPOS,
            'tasaIva' => (float) $p->valor('tributario.tasa_iva'),
            'tolerancia' => (float) $p->valor('compras.match_tolerancia_pct'),
            // Órdenes de compra con algo recibido sin facturar, para elegir.
            'ordenes' => OrdenCompra::with('proveedor:id,razon_social')->whereIn('estado', ['parcial', 'recibida', 'cerrada', 'aprobada'])
                ->whereHas('lineas', fn ($l) => $l->whereColumn('cantidad_recibida', '>', 'cantidad_facturada'))
                ->orderByDesc('numero')->limit(50)->get()->map(fn ($o) => ['id' => $o->id, 'numero' => $o->numero, 'proveedor' => $o->proveedor?->razon_social]),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'proveedor_id' => ['required', 'integer'],
            'tipo_dte' => ['required', Rule::in(array_keys(FacturaProveedor::TIPOS))],
            'folio' => ['required', 'integer', 'min:1', 'max:9999999999'],
            'fecha_emision' => ['required', 'date', 'before_or_equal:today'],
            'fecha_vencimiento' => ['nullable', 'date', 'after_or_equal:fecha_emision'],
            'orden_compra_id' => ['nullable', 'integer'],
            'monto_neto' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'monto_exento' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'monto_iva' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'monto_total' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'observaciones' => ['nullable', 'string', 'max:300'],
            'lineas' => ['nullable', 'array', 'max:'.FacturasProveedor::MAX_LINEAS],
            'lineas.*.orden_compra_linea_id' => ['nullable', 'integer'],
            'lineas.*.producto_id' => ['nullable', 'integer'],
            'lineas.*.descripcion' => ['nullable', 'string', 'max:80'],
            'lineas.*.cantidad' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'lineas.*.costo_unitario' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        $f = $this->facturas->registrar($d, $request->user());

        return to_route('compras.facturas.show', $f)->with('exito', $f->estado === 'por_pagar'
            ? "{$f->nombre()} registrada: calza con la orden y lo recibido, y queda por pagar."
            : "{$f->nombre()} registrada con diferencias: queda por revisar por otra persona.");
    }

    public function show(Request $request, FacturaProveedor $factura)
    {
        $factura->load('proveedor', 'ordenCompra:id,numero', 'lineas.lineaOc', 'pagos.registrador:id,name', 'pagos.aprobador:id,name', 'registrador:id,name', 'aprobador:id,name');
        $dec = \App\Modules\Inventario\Inventario::dec(...);

        return Inertia::render('Compras/Facturas/Show', [
            'factura' => [
                'id' => $factura->id, 'nombre' => $factura->nombre(), 'tipo_dte' => $factura->tipo_dte, 'folio' => $factura->folio, 'estado' => $factura->estado,
                'proveedor' => ['id' => $factura->proveedor->id, 'razon_social' => $factura->proveedor->razon_social, 'rut' => $factura->proveedor->rut],
                'emision' => $factura->fecha_emision->toDateString(), 'vencimiento' => $factura->fecha_vencimiento->toDateString(),
                'oc' => $factura->ordenCompra ? ['id' => $factura->ordenCompra->id, 'numero' => $factura->ordenCompra->numero] : null,
                'neto' => $factura->monto_neto, 'exento' => $factura->monto_exento, 'iva' => $factura->monto_iva, 'total' => $factura->monto_total, 'saldo' => $factura->saldo,
                'diferencias' => $factura->diferencias ?? [], 'motivo_rechazo' => $factura->motivo_rechazo, 'observaciones' => $factura->observaciones,
                'registrador' => $factura->registrador?->name, 'aprobador' => $factura->aprobador?->name, 'propia' => $factura->registrado_por === $request->user()->id,
                'lineas' => $factura->lineas->map(fn ($l) => ['id' => $l->id, 'descripcion' => $l->descripcion, 'cantidad' => $dec($l->cantidad), 'costo_unitario' => $dec($l->costo_unitario),
                    'monto' => $l->monto, 'costo_oc' => $l->lineaOc ? $dec($l->lineaOc->costo_unitario) : null])->all(),
                'pagos' => $factura->pagos->map(fn (PagoProveedor $p) => ['id' => $p->id, 'fecha' => $p->fecha->toDateString(), 'monto' => $p->monto, 'metodo' => PagoProveedor::METODOS[$p->metodo],
                    'referencia' => $p->referencia, 'estado' => $p->estado, 'registrador' => $p->registrador?->name, 'aprobador' => $p->aprobador?->name,
                    'propio' => $p->registrado_por === $request->user()->id, 'motivo_rechazo' => $p->motivo_rechazo])->all(),
            ],
            'metodos' => PagoProveedor::METODOS,
            'limitePago' => (int) app(Parametros::class)->valor('aprobaciones.pago_proveedor_monto'),
        ]);
    }

    public function aprobar(Request $request, FacturaProveedor $factura)
    {
        $this->facturas->aprobar($factura, $request->user());

        return back()->with('exito', 'Diferencias aceptadas: la factura queda por pagar.');
    }

    public function rechazar(Request $request, FacturaProveedor $factura)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->facturas->rechazar($factura, $request->user(), $d['motivo']);

        return back()->with('exito', 'Factura rechazada: no pasa a pago. Pide al proveedor la nota de crédito o la corrección.');
    }
}
