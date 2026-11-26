<?php

namespace App\Http\Controllers\Ventas;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Local;
use App\Modules\Inventario\Inventario;
use App\Modules\Ventas\Models\Cotizacion;
use App\Modules\Ventas\Models\OrdenVenta;
use App\Modules\Ventas\OrdenesVenta;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** VentasCRM → Orden de Venta (e Inventario → Orden de Venta, solo lectura). */
class OrdenesController extends Controller
{
    public function __construct(private OrdenesVenta $ordenes) {}

    public function index(Request $request)
    {
        $q = OrdenVenta::with('cliente:id,razon_social', 'local:id,nombre', 'documento:id,tipo_dte,folio', 'creador:id,name')->orderByDesc('numero');
        match ($request->input('estado')) {
            'confirmada' => $q->where('estado', 'confirmada')->where('reserva_hasta', '>=', now()->toDateString()),
            'vencida' => $q->where('estado', 'confirmada')->where('reserva_hasta', '<', now()->toDateString()),
            'facturada', 'anulada' => $q->where('estado', $request->input('estado')),
            default => null,
        };
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => ctype_digit($b) ? $q->where('numero', (int) $b) : $q->whereHas('cliente', fn ($c) => $c->where('razon_social', 'ilike', "%{$b}%")));
        }
        $fila = fn (OrdenVenta $o) => [
            'id' => $o->id, 'numero' => $o->numero, 'fecha' => $o->fecha->toDateString(), 'reserva_hasta' => $o->reserva_hasta->toDateString(),
            'cliente' => $o->cliente?->razon_social, 'local' => $o->local?->nombre, 'total' => $o->monto_total, 'estado' => $o->estadoVisible(),
            'documento' => $o->documento?->nombre(), 'ejecutivo' => $o->creador?->name,
        ];

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('ventascrm.orden-de-venta.exportar') || $request->user()->tienePermiso('inventario.orden-de-venta.exportar'), 403);

            return Csv::descargar('ordenes-de-venta', ['N°', 'Fecha', 'Reserva hasta', 'Cliente', 'Local', 'Total', 'Estado', 'Documento', 'Ejecutivo'],
                $q->get()->map(fn ($o) => array_values(array_diff_key($fila($o), ['id' => 1]))));
        }

        return Inertia::render('Ventas/Ordenes/Index', [
            'ordenes' => $q->paginate(25)->withQueryString()->through($fila),
            'filtros' => $request->only('estado', 'buscar'),
            'estados' => OrdenVenta::ESTADOS,
        ]);
    }

    public function show(Request $request, OrdenVenta $orden)
    {
        $orden->load('lineas', 'cliente', 'local:id,nombre', 'cotizacion:id,numero', 'documento:id,tipo_dte,folio,estado', 'creador:id,name');
        $inv = app(Inventario::class);
        $dec = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');

        return Inertia::render('Ventas/Ordenes/Show', [
            'orden' => [
                'id' => $orden->id, 'numero' => $orden->numero, 'estado' => $orden->estadoVisible(), 'fecha' => $orden->fecha->toDateString(),
                'reserva_hasta' => $orden->reserva_hasta->toDateString(), 'forma_pago' => $orden->forma_pago, 'local' => $orden->local?->nombre,
                'cliente' => $orden->cliente?->only(['id', 'rut', 'razon_social', 'giro', 'direccion', 'comuna']),
                'cotizacion' => $orden->cotizacion ? ['id' => $orden->cotizacion->id, 'numero' => $orden->cotizacion->numero] : null,
                'documento' => $orden->documento ? ['id' => $orden->documento->id, 'nombre' => $orden->documento->nombre(), 'estado' => $orden->documento->estado] : null,
                'neto' => $orden->monto_neto, 'exento' => $orden->monto_exento, 'iva' => $orden->monto_iva, 'total' => $orden->monto_total,
                'descuento_global_pct' => (string) $orden->descuento_global_pct, 'descuento_global_monto' => $orden->descuento_global_monto,
                'observaciones' => $orden->observaciones, 'motivo_anulacion' => $orden->motivo_anulacion, 'creador' => $orden->creador?->name,
                'lineas' => $orden->lineas->map(fn ($l) => [
                    'producto_id' => $l->producto_id, 'codigo' => $l->codigo, 'descripcion' => $l->descripcion, 'cantidad' => $dec($l->cantidad), 'unidad' => $l->unidad,
                    'precio_unitario' => $dec($l->precio_unitario), 'descuento_pct' => $dec($l->descuento_pct), 'descuento_monto' => $l->descuento_monto, 'monto_item' => $l->monto_item,
                    'fisico' => $l->producto_id ? (float) (string) $inv->fisico($l->producto_id, $orden->local_id) : null,
                ])->all(),
            ],
            'puedeFacturar' => $request->user()->tienePermiso('caja.facturas-de-ventas.crear') && $request->user()->tienePermiso('ventascrm.orden-de-venta.editar'),
        ]);
    }

    public function desdeCotizacion(Request $request, Cotizacion $cotizacion)
    {
        $d = $request->validate(['local_id' => ['nullable', 'integer', 'exists:locales,id']]);
        $ov = $this->ordenes->desdeCotizacion($cotizacion, $request->user(), $d['local_id'] ?? null);

        return to_route('ventas.ordenes.show', $ov)->with('exito', "Orden de venta N° {$ov->numero} confirmada: el stock queda reservado hasta el ".$ov->reserva_hasta->format('d-m-Y').'.');
    }

    public function facturar(Request $request, OrdenVenta $orden)
    {
        $d = $request->validate(['tipo_dte' => ['required', Rule::in([33, 39])]]);
        $doc = $this->ordenes->facturar($orden, (int) $d['tipo_dte'], $request->user());

        return to_route('caja.documentos.show', $doc)->with('exito', 'Documento creado desde la orden de venta. Al emitirlo se descuenta el stock reservado.');
    }

    public function anular(Request $request, OrdenVenta $orden)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->ordenes->anular($orden, $request->user(), $d['motivo']);

        return back()->with('exito', 'Orden anulada: la reserva de stock se liberó.');
    }
}
