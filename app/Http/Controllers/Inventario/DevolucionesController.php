<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Inventario\Devoluciones;
use App\Modules\Inventario\Escuchas\MoverStockAlEmitir;
use App\Modules\Inventario\Models\Devolucion;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Inventario / Caja → Devolución: vinculada al documento original; al aprobarse genera la NC. */
class DevolucionesController extends Controller
{
    public function __construct(private Devoluciones $devoluciones) {}

    public function index(Request $request)
    {
        $q = Devolucion::with('documento:id,tipo_dte,folio,cliente_id', 'documento.cliente:id,razon_social', 'notaCredito:id,tipo_dte,folio', 'solicitante:id,name')->orderByDesc('numero');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => ctype_digit($b)
                ? $q->where('numero', (int) $b)->orWhereHas('documento', fn ($d) => $d->where('folio', (int) $b))
                : $q->whereHas('documento.cliente', fn ($c) => $c->where('razon_social', 'ilike', "%{$b}%")));
        }

        return Inertia::render('Inventario/Devoluciones/Index', [
            'devoluciones' => $q->paginate(25)->withQueryString()->through(fn (Devolucion $d) => [
                'id' => $d->id, 'numero' => $d->numero, 'fecha' => $d->created_at->toIso8601String(), 'documento' => $d->documento?->nombre(),
                'cliente' => $d->documento?->cliente?->razon_social, 'motivo' => Devolucion::MOTIVOS[$d->motivo], 'monto' => $d->monto_estimado,
                'estado' => $d->estado, 'nota_credito' => $d->notaCredito?->nombre(), 'solicitante' => $d->solicitante?->name,
            ]),
            'filtros' => $request->only('estado', 'buscar'),
            'estados' => Devolucion::ESTADOS,
            'pendientes' => Devolucion::where('estado', 'solicitada')->count(),
        ]);
    }

    /** Elige el documento (por id o por tipo y folio) y muestra lo que se puede devolver de cada línea. */
    public function create(Request $request)
    {
        $doc = null;
        if ($id = $request->integer('documento_id')) {
            $doc = Documento::find($id);
        } elseif ($folio = $request->integer('folio')) {
            $doc = Documento::where('tipo_dte', $request->integer('tipo') ?: 33)->where('folio', $folio)->first();
        }
        $valido = $doc && in_array($doc->tipo_dte, MoverStockAlEmitir::VENTAS, true) && in_array($doc->estado, ['emitido', 'pagado'], true);
        $devuelto = $valido ? $this->devoluciones->devuelto($doc) : [];

        return Inertia::render('Inventario/Devoluciones/Form', [
            'busqueda' => $request->only('tipo', 'folio'),
            'error' => ($request->hasAny(['documento_id', 'folio']) && ! $valido) ? 'No hay una factura o boleta emitida y vigente con ese número.' : null,
            'documento' => $valido ? [
                'id' => $doc->id, 'nombre' => $doc->nombre(), 'fecha' => $doc->fecha_emision->toDateString(), 'cliente' => $doc->cliente?->razon_social,
                'total' => $doc->monto_total, 'acreditable' => $doc->saldoAcreditable(),
                'lineas' => $doc->lineas->map(fn ($l) => [
                    'id' => $l->id, 'descripcion' => $l->descripcion, 'codigo' => $l->codigo, 'unidad' => $l->unidad, 'producto_id' => $l->producto_id,
                    'vendida' => (float) $l->cantidad, 'disponible' => (float) (string) BigDecimal::of((string) $l->cantidad)->minus($devuelto[$l->id] ?? '0'),
                    'precio' => (float) $l->precio_unitario, 'monto_item' => $l->monto_item,
                ])->all(),
            ] : null,
            'motivos' => Devolucion::MOTIVOS,
            'umbral' => (int) app(Parametros::class)->valor('inventario.devolucion_aprobacion_monto'),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'documento_id' => ['required', 'integer'],
            'motivo' => ['required', Rule::in(array_keys(Devolucion::MOTIVOS))],
            'detalle' => ['required', 'string', 'max:300'],
            'lineas' => ['required', 'array', 'min:1'],
            'lineas.*.documento_linea_id' => ['required', 'integer'],
            'lineas.*.cantidad' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.reingresa' => ['boolean'],
        ]);
        $lineas = array_values(array_filter($d['lineas'], fn ($l) => (float) ($l['cantidad'] ?? 0) > 0));
        $dev = $this->devoluciones->solicitar(Documento::findOrFail($d['documento_id']), $lineas, $d['motivo'], $d['detalle'], $request->user());

        return to_route('inventario.devoluciones.show', $dev)->with('exito', $dev->estado === 'aprobada'
            ? "Devolución N° {$dev->numero} aprobada: se emitió la nota de crédito."
            : "Devolución N° {$dev->numero} registrada: espera la aprobación de otra persona.");
    }

    public function show(Request $request, Devolucion $devolucion)
    {
        $devolucion->load('lineas.lineaOriginal', 'documento.cliente:id,razon_social,rut', 'notaCredito:id,tipo_dte,folio,monto_total,estado', 'solicitante:id,name', 'aprobador:id,name', 'local:id,nombre');

        return Inertia::render('Inventario/Devoluciones/Show', [
            'devolucion' => [
                'id' => $devolucion->id, 'numero' => $devolucion->numero, 'estado' => $devolucion->estado, 'fecha' => $devolucion->created_at->toIso8601String(),
                'motivo' => Devolucion::MOTIVOS[$devolucion->motivo], 'detalle' => $devolucion->detalle, 'monto' => $devolucion->monto_estimado,
                'documento' => ['id' => $devolucion->documento_id, 'nombre' => $devolucion->documento?->nombre(), 'cliente' => $devolucion->documento?->cliente?->razon_social],
                'nota_credito' => $devolucion->notaCredito ? ['id' => $devolucion->notaCredito->id, 'nombre' => $devolucion->notaCredito->nombre(), 'total' => $devolucion->notaCredito->monto_total] : null,
                'local' => $devolucion->local?->nombre, 'solicitante' => $devolucion->solicitante?->name, 'aprobador' => $devolucion->aprobador?->name,
                'motivo_rechazo' => $devolucion->motivo_rechazo, 'propia' => $devolucion->solicitado_por === $request->user()->id,
                'lineas' => $devolucion->lineas->map(fn ($l) => [
                    'descripcion' => $l->lineaOriginal?->descripcion, 'codigo' => $l->lineaOriginal?->codigo, 'unidad' => $l->lineaOriginal?->unidad,
                    'cantidad' => (float) $l->cantidad, 'vendida' => (float) $l->lineaOriginal?->cantidad, 'reingresa' => $l->reingresa,
                ])->all(),
            ],
        ]);
    }

    public function aprobar(Request $request, Devolucion $devolucion)
    {
        $d = $this->devoluciones->aprobar($devolucion, $request->user());

        return back()->with('exito', 'Devolución aprobada: se emitió '.$d->notaCredito?->nombre().' y el stock reingresó.');
    }

    public function rechazar(Request $request, Devolucion $devolucion)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->devoluciones->rechazar($devolucion, $request->user(), $d['motivo']);

        return back()->with('exito', 'Devolución rechazada.');
    }
}
