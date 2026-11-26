<?php

namespace App\Http\Controllers\Ventas;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Ventas\Cotizaciones;
use App\Modules\Ventas\Models\Cotizacion;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** VentasCRM → Cotización: constructor, aprobación, envío, aceptación y conversión a documento. */
class CotizacionesController extends Controller
{
    public function __construct(private Cotizaciones $cotizaciones, private Parametros $parametros) {}

    public function index(Request $request)
    {
        $q = Cotizacion::query()->with('cliente:id,razon_social', 'creador:id,name', 'documento:id,tipo_dte,folio')->orderByDesc('numero');
        $estado = $request->input('estado');
        if ($estado === 'vencida') {
            $q->whereIn('estado', ['borrador', 'por_aprobar', 'enviada'])->where('valida_hasta', '<', now()->toDateString());
        } elseif ($estado) {
            $q->where('estado', $estado);
            if (in_array($estado, ['borrador', 'por_aprobar', 'enviada'], true)) {
                $q->where('valida_hasta', '>=', now()->toDateString());
            }
        }
        if ($request->boolean('mias')) {
            $q->where('creado_por', $request->user()->id);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => ctype_digit($b) ? $q->where('numero', (int) $b) : $q->where('contacto_nombre', 'ilike', "%{$b}%")
                ->orWhereHas('cliente', fn ($c) => $c->where('razon_social', 'ilike', "%{$b}%")));
        }

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('ventascrm.cotizacion.exportar'), 403);

            return Csv::descargar('cotizaciones', ['Número', 'Fecha', 'Válida hasta', 'Cliente', 'Neto', 'IVA', 'Total', 'Estado', 'Ejecutivo'],
                $q->get()->map(fn (Cotizacion $c) => [$c->numero, $c->fecha->toDateString(), $c->valida_hasta->toDateString(), $c->destinatario(), $c->monto_neto, $c->monto_iva, $c->monto_total, Cotizacion::ESTADOS[$c->estadoVisible()], $c->creador?->name]));
        }

        // Embudo del período: cuántas se enviaron y cuántas terminaron en venta.
        $base = Cotizacion::query()->where('fecha', '>=', now()->subDays(30)->toDateString());
        $emitidas = (clone $base)->whereNotIn('estado', ['borrador', 'por_aprobar'])->count();
        $convertidas = (clone $base)->where('estado', 'convertida')->count();

        return Inertia::render('Ventas/Cotizaciones/Index', [
            'cotizaciones' => $q->paginate(25)->withQueryString()->through(fn (Cotizacion $c) => $this->fila($c)),
            'filtros' => $request->only('estado', 'buscar', 'mias'),
            'estados' => Cotizacion::ESTADOS,
            'embudo' => ['enviadas' => $emitidas, 'convertidas' => $convertidas, 'tasa' => $emitidas ? round($convertidas * 100 / $emitidas, 1) : null,
                'monto_abierto' => (int) Cotizacion::whereIn('estado', ['enviada', 'aceptada'])->where('valida_hasta', '>=', now()->toDateString())->sum('monto_total')],
        ]);
    }

    public function create()
    {
        return Inertia::render('Ventas/Cotizaciones/Form', $this->datosFormulario() + ['cotizacion' => null]);
    }

    public function store(Request $request)
    {
        $cot = $this->cotizaciones->guardar($this->validar($request), $request->user());

        return to_route('ventas.cotizaciones.show', $cot)->with('exito', $cot->estado === 'por_aprobar' ? "Cotización N° {$cot->numero} guardada: requiere aprobación antes de enviarla." : "Cotización N° {$cot->numero} guardada.");
    }

    public function show(Request $request, Cotizacion $cotizacion)
    {
        $cotizacion->load('lineas', 'cliente', 'creador:id,name', 'aprobador:id,name', 'documento:id,tipo_dte,folio,estado', 'local:id,nombre');

        return Inertia::render('Ventas/Cotizaciones/Show', [
            'cotizacion' => $this->detalle($cotizacion) + [
                'creador' => $cotizacion->creador?->name,
                'aprobador' => $cotizacion->aprobador?->name,
                'local' => $cotizacion->local?->nombre,
                'propia' => $cotizacion->creado_por === $request->user()->id,
                'documento' => $cotizacion->documento ? ['id' => $cotizacion->documento->id, 'nombre' => $cotizacion->documento->nombre(), 'estado' => $cotizacion->documento->estado] : null,
            ],
            'puedeFacturar' => $request->user()->tienePermiso('caja.facturas-de-ventas.crear'),
        ]);
    }

    public function edit(Cotizacion $cotizacion)
    {
        abort_unless($cotizacion->editable(), 404);
        $cotizacion->load('lineas', 'cliente');

        return Inertia::render('Ventas/Cotizaciones/Form', $this->datosFormulario() + ['cotizacion' => $this->detalle($cotizacion)]);
    }

    public function update(Request $request, Cotizacion $cotizacion)
    {
        $cot = $this->cotizaciones->guardar($this->validar($request), $request->user(), $cotizacion);

        return to_route('ventas.cotizaciones.show', $cot)->with('exito', 'Cotización actualizada.');
    }

    public function destroy(Cotizacion $cotizacion)
    {
        abort_unless(in_array($cotizacion->estado, ['borrador', 'por_aprobar'], true), 403, 'Solo se elimina una cotización en borrador.');
        $cotizacion->delete();

        return to_route('ventas.cotizaciones.index')->with('exito', 'Cotización eliminada.');
    }

    public function calcular(Request $request)
    {
        return $this->cotizaciones->calcular($request->validate([
            'cliente_id' => ['nullable', 'integer'],
            'forma_pago' => ['nullable', Rule::in(['contado', 'credito'])],
            'descuento_global_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lineas' => ['array', 'max:60'],
            'lineas.*' => ['array'],
        ]));
    }

    public function aprobar(Request $request, Cotizacion $cotizacion)
    {
        $this->cotizaciones->aprobar($cotizacion, $request->user(), $request->validate(['comentario' => ['nullable', 'string', 'max:500']])['comentario'] ?? null);

        return back()->with('exito', 'Cotización aprobada: ya se puede enviar.');
    }

    public function enviar(Request $request, Cotizacion $cotizacion)
    {
        $this->cotizaciones->enviar($cotizacion, $request->user());

        return back()->with('exito', 'Cotización marcada como enviada al cliente.');
    }

    public function aceptar(Request $request, Cotizacion $cotizacion)
    {
        $this->cotizaciones->aceptar($cotizacion, $request->user());

        return back()->with('exito', 'Cotización aceptada por el cliente.');
    }

    public function rechazar(Request $request, Cotizacion $cotizacion)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->cotizaciones->rechazar($cotizacion, $request->user(), $d['motivo']);

        return back()->with('exito', 'Cotización cerrada como rechazada.');
    }

    public function convertir(Request $request, Cotizacion $cotizacion)
    {
        $d = $request->validate(['tipo_dte' => ['required', Rule::in([33, 39])]]);
        $doc = $this->cotizaciones->convertir($cotizacion, (int) $d['tipo_dte'], $request->user());

        return to_route('caja.documentos.show', $doc)->with('exito', $doc->estado === 'por_aprobar'
            ? 'Documento creado desde la cotización: requiere aprobación antes de emitir.'
            : 'Documento creado desde la cotización. Revísalo y emítelo.');
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'cliente_id' => ['nullable', 'integer'],
            'contacto_nombre' => ['nullable', 'string', 'max:100'],
            'contacto_email' => ['nullable', 'email', 'max:120'],
            'contacto_telefono' => ['nullable', 'string', 'max:20'],
            'local_id' => ['nullable', 'integer', Rule::exists('locales', 'id')],
            'forma_pago' => ['required', Rule::in(['contado', 'credito'])],
            'descuento_global_pct' => ['nullable', 'numeric', 'between:0,100'],
            'valida_hasta' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'lineas' => ['required', 'array', 'min:1', 'max:60'],
            'lineas.*.producto_id' => ['nullable', 'integer'],
            'lineas.*.descripcion' => ['nullable', 'string', 'max:80'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'lineas.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.descuento_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lineas.*.exento' => ['boolean'],
        ], [], ['lineas' => 'líneas']);
    }

    private function datosFormulario(): array
    {
        return [
            'locales' => Local::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'descuentoMaximo' => $this->parametros->valor('aprobaciones.descuento_max_pct'),
            'vigenciaDias' => (int) $this->parametros->valor('cotizaciones.vigencia_dias'),
        ];
    }

    private function fila(Cotizacion $c): array
    {
        return [
            'id' => $c->id, 'numero' => $c->numero, 'fecha' => $c->fecha->toDateString(), 'valida_hasta' => $c->valida_hasta->toDateString(),
            'destinatario' => $c->destinatario(), 'total' => $c->monto_total, 'estado' => $c->estadoVisible(), 'ejecutivo' => $c->creador?->name,
            'documento' => $c->documento ? $c->documento->nombre() : null,
        ];
    }

    private function detalle(Cotizacion $c): array
    {
        $dec = fn ($v) => $v === null ? null : rtrim(rtrim((string) $v, '0'), '.');

        return array_merge($this->fila($c), [
            'estado_real' => $c->estado,
            'cliente' => $c->cliente?->only(['id', 'rut', 'razon_social', 'giro', 'direccion', 'comuna', 'lista_precio', 'tiene_credito']),
            'contacto_nombre' => $c->contacto_nombre, 'contacto_email' => $c->contacto_email, 'contacto_telefono' => $c->contacto_telefono,
            'local_id' => $c->local_id, 'forma_pago' => $c->forma_pago,
            'descuento_global_pct' => (string) $c->descuento_global_pct, 'descuento_global_monto' => $c->descuento_global_monto,
            'neto' => $c->monto_neto, 'exento' => $c->monto_exento, 'iva' => $c->monto_iva, 'tasa_iva' => (string) $c->tasa_iva,
            'observaciones' => $c->observaciones, 'motivo_rechazo' => $c->motivo_rechazo,
            'motivos' => $c->aprobacion_motivos ?? [], 'aprobado' => (bool) $c->aprobado_por,
            'lineas' => $c->lineas->map(fn ($l) => [
                'producto_id' => $l->producto_id, 'codigo' => $l->codigo, 'descripcion' => $l->descripcion, 'cantidad' => $dec($l->cantidad), 'unidad' => $l->unidad,
                'precio_unitario' => $dec($l->precio_unitario), 'precio_lista' => $dec($l->precio_lista), 'descuento_pct' => $dec($l->descuento_pct),
                'descuento_monto' => $l->descuento_monto, 'monto_item' => $l->monto_item, 'exento' => $l->exento,
            ])->all(),
        ]);
    }
}
