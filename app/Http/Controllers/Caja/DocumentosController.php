<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\DocumentoReferencia;
use App\Modules\Facturacion\Models\Pago;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Caja → Facturas de Ventas: borradores, aprobación, emisión, cobro y notas. */
class DocumentosController extends Controller
{
    public function __construct(private Documentos $documentos, private Parametros $parametros) {}

    public function index(Request $request)
    {
        $q = Documento::query()->with('cliente:id,razon_social')->orderByDesc('id');
        foreach (['tipo_dte', 'estado', 'estado_sii'] as $f) {
            if ($v = $request->input($f)) {
                $q->where($f, $v);
            }
        }
        if ($d = $request->date('desde')) {
            $q->where('fecha_emision', '>=', $d->toDateString());
        }
        if ($h = $request->date('hasta')) {
            $q->where('fecha_emision', '<=', $h->toDateString());
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => ctype_digit($b) ? $q->where('folio', (int) $b) : $q->where('receptor_razon_social', 'ilike', "%{$b}%")
                ->orWhereHas('cliente', fn ($c) => $c->where('razon_social', 'ilike', "%{$b}%")));
        }

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('caja.facturas-de-ventas.exportar'), 403);

            return Csv::descargar('documentos', ['Tipo', 'Folio', 'Fecha', 'Receptor', 'Neto', 'Exento', 'IVA', 'Total', 'Saldo', 'Estado', 'SII'],
                $q->get()->map(fn (Documento $d) => [Documento::CORTOS[$d->tipo_dte], $d->folio, $d->fecha_emision->toDateString(), $d->receptor_razon_social ?? $d->cliente?->razon_social,
                    $d->monto_neto, $d->monto_exento, $d->monto_iva, $d->monto_total, $d->saldo, $d->estado, $d->estado_sii]));
        }

        return Inertia::render('Caja/Documentos/Index', [
            'documentos' => $q->paginate(25)->withQueryString()->through(fn (Documento $d) => $this->fila($d)),
            'filtros' => $request->only('tipo_dte', 'estado', 'estado_sii', 'desde', 'hasta', 'buscar'),
            'tipos' => Documento::CORTOS,
            'estados' => Documento::ESTADOS,
            'facturacionActiva' => (bool) $this->parametros->valor('modulos.facturacion'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Caja/Documentos/Form', $this->datosFormulario() + ['documento' => null, 'tipoInicial' => (int) $request->input('tipo', 39)]);
    }

    public function store(Request $request)
    {
        $doc = $this->documentos->guardar($this->validar($request), $request->user());

        return to_route('caja.documentos.show', $doc)->with('exito', $doc->estado === 'por_aprobar' ? 'Borrador guardado: requiere aprobación antes de emitir.' : 'Borrador guardado.');
    }

    public function edit(Documento $documento)
    {
        abort_unless($documento->esBorrador(), 404);
        $documento->load('lineas', 'referencias', 'cliente');

        return Inertia::render('Caja/Documentos/Form', $this->datosFormulario() + ['documento' => $this->detalle($documento), 'tipoInicial' => $documento->tipo_dte]);
    }

    public function update(Request $request, Documento $documento)
    {
        $doc = $this->documentos->guardar($this->validar($request) + ['referencias' => $documento->referencias->map->only(['documento_referido_id', 'codigo', 'razon'])->all()], $request->user(), $documento);

        return to_route('caja.documentos.show', $doc)->with('exito', 'Borrador actualizado.');
    }

    public function destroy(Documento $documento)
    {
        abort_unless($documento->esBorrador(), 403, 'Un documento emitido no se elimina: se corrige con nota de crédito.');
        $documento->delete();

        return to_route('caja.documentos.index')->with('exito', 'Borrador eliminado.');
    }

    public function calcular(Request $request)
    {
        return $this->documentos->calcular($request->validate([
            'tipo_dte' => ['required', 'integer', Rule::in(array_keys(Documento::TIPOS))],
            'cliente_id' => ['nullable', 'integer'],
            'forma_pago' => ['nullable', Rule::in(['contado', 'credito'])],
            'descuento_global_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lineas' => ['array', 'max:60'],
            'lineas.*' => ['array'],
            'referencias' => ['array'],
        ]));
    }

    public function show(Request $request, Documento $documento)
    {
        $documento->load('lineas', 'referencias.referido', 'pagos.usuario:id,name', 'cliente', 'creador:id,name', 'local:id,nombre');
        $notas = DocumentoReferencia::with('documento:id,tipo_dte,folio,monto_total,estado,fecha_emision')->where('documento_referido_id', $documento->id)->get()
            ->map(fn ($r) => ['id' => $r->documento->id, 'nombre' => $r->documento->nombre(), 'total' => $r->documento->monto_total, 'estado' => $r->documento->estado, 'codigo' => DocumentoReferencia::CODIGOS[$r->codigo] ?? null]);

        return Inertia::render('Caja/Documentos/Show', [
            'documento' => $this->detalle($documento) + [
                'pagos' => $documento->pagos->map(fn (Pago $p) => ['id' => $p->id, 'monto' => $p->monto, 'metodo' => Pago::METODOS[$p->metodo] ?? $p->metodo, 'fecha' => $p->fecha->toDateString(), 'usuario' => $p->usuario?->name, 'referencia' => $p->referencia]),
                'notas' => $notas,
                'acreditable' => $documento->esBorrador() || $documento->esNota() ? 0 : $documento->saldoAcreditable(),
                'creador' => $documento->creador?->name,
                'local' => $documento->local?->nombre,
                'propio' => $documento->creado_por === $request->user()->id,
                'despacho' => \App\Modules\Inventario\Models\Despacho::where('documento_id', $documento->id)->where('estado', '<>', 'anulado')->first(['id', 'numero', 'estado']),
                'devoluciones' => \App\Modules\Inventario\Models\Devolucion::where('documento_id', $documento->id)->orderBy('numero')->get(['id', 'numero', 'estado']),
            ],
            'medios' => array_intersect_key(Pago::METODOS, array_flip($this->parametros->valor('caja.medios', $documento->local_id))),
            'codigos' => DocumentoReferencia::CODIGOS,
        ]);
    }

    public function aprobar(Request $request, Documento $documento)
    {
        $this->documentos->aprobar($documento, $request->user(), $request->validate(['comentario' => ['nullable', 'string', 'max:500']])['comentario'] ?? null);

        return back()->with('exito', 'Documento aprobado: ya se puede emitir.');
    }

    public function emitir(Request $request, Documento $documento)
    {
        $doc = $this->documentos->emitir($documento, $request->user());

        return back()->with('exito', "{$doc->nombre()} emitido por $".number_format($doc->monto_total, 0, ',', '.').'.');
    }

    public function pagar(Request $request, Documento $documento)
    {
        $d = $request->validate(['monto' => ['required', 'integer', 'min:1'], 'metodo' => ['required', 'string'], 'referencia' => ['nullable', 'string', 'max:60']]);
        $this->documentos->registrarPago($documento, $d['monto'], $d['metodo'], $request->user(), $d['referencia'] ?? null);

        return back()->with('exito', 'Pago registrado.');
    }

    public function nota(Request $request, Documento $documento)
    {
        $d = $request->validate([
            'tipo_dte' => ['required', Rule::in([56, 61])],
            'codigo' => ['required', Rule::in([1, 2, 3])],
            'razon' => ['required', 'string', 'max:90'],
        ]);
        $nota = $this->documentos->prepararNota($documento, (int) $d['tipo_dte'], (int) $d['codigo'], $d['razon'], $request->user(),
            (int) $d['codigo'] === 3 ? $documento->lineas->map(fn ($l) => ['producto_id' => $l->producto_id, 'descripcion' => $l->descripcion, 'cantidad' => (string) $l->cantidad, 'unidad' => $l->unidad, 'precio_unitario' => (string) $l->precio_unitario, 'exento' => $l->exento, 'precio_manual' => true])->all() : null);

        return (int) $d['codigo'] === 3
            ? to_route('caja.documentos.edit', $nota)->with('exito', 'Ajusta las cantidades o precios a corregir y guarda.')
            : to_route('caja.documentos.show', $nota)->with('exito', 'Nota preparada. Revísala y emítela.');
    }

    public function pdf(Documento $documento)
    {
        abort_unless($documento->pdf_path && Storage::disk('local')->exists($documento->pdf_path), 404);

        return Storage::disk('local')->download($documento->pdf_path, str_replace(' ', '-', $documento->nombre()).'.pdf');
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'tipo_dte' => ['required', 'integer', Rule::in(array_keys(Documento::TIPOS))],
            'cliente_id' => ['nullable', 'integer'],
            'local_id' => ['nullable', 'integer', Rule::exists('locales', 'id')],
            'forma_pago' => ['required', Rule::in(['contado', 'credito'])],
            'descuento_global_pct' => ['nullable', 'numeric', 'between:0,100'],
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
            'tipos' => array_intersect_key(Documento::TIPOS, array_flip(array_map('intval', $this->parametros->valor('tributario.tipos_dte')))),
            'locales' => Local::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'descuentoMaximo' => $this->parametros->valor('aprobaciones.descuento_max_pct'),
            'facturacionActiva' => (bool) $this->parametros->valor('modulos.facturacion'),
        ];
    }

    private function fila(Documento $d): array
    {
        return [
            'id' => $d->id, 'tipo_dte' => $d->tipo_dte, 'tipo' => Documento::CORTOS[$d->tipo_dte], 'folio' => $d->folio,
            'fecha' => $d->fecha_emision->toDateString(), 'receptor' => $d->receptor_razon_social ?? $d->cliente?->razon_social,
            'total' => $d->monto_total, 'saldo' => $d->saldo, 'estado' => $d->estado, 'estado_sii' => $d->estado_sii, 'forma_pago' => $d->forma_pago,
        ];
    }

    private function detalle(Documento $d): array
    {
        // array_merge: aquí 'receptor' es el receptor congelado (objeto), no el nombre de la fila.
        return array_merge($this->fila($d), [
            'nombre' => $d->nombre(),
            'tipo_largo' => Documento::TIPOS[$d->tipo_dte],
            'cliente' => $d->cliente?->only(['id', 'rut', 'razon_social', 'giro', 'direccion', 'comuna', 'lista_precio', 'tiene_credito']),
            'receptor' => $d->receptor_rut ? ['rut' => $d->receptor_rut, 'razon_social' => $d->receptor_razon_social, 'giro' => $d->receptor_giro, 'direccion' => $d->receptor_direccion, 'comuna' => $d->receptor_comuna] : null,
            'local_id' => $d->local_id,
            'vencimiento' => $d->fecha_vencimiento?->toDateString(),
            'descuento_global_pct' => (string) $d->descuento_global_pct,
            'descuento_global_monto' => $d->descuento_global_monto,
            'neto' => $d->monto_neto, 'exento' => $d->monto_exento, 'iva' => $d->monto_iva, 'tasa_iva' => (string) $d->tasa_iva, 'iva_incluido' => $d->iva_incluido,
            'observaciones' => $d->observaciones,
            'motivos' => $d->aprobacion_motivos ?? [],
            'aprobado' => (bool) $d->aprobado_por,
            'sii' => ['track_id' => $d->sii_track_id, 'intentos' => $d->sii_intentos, 'pdf' => (bool) $d->pdf_path],
            'lineas' => $d->lineas->map(fn ($l) => [
                'producto_id' => $l->producto_id, 'codigo' => $l->codigo, 'descripcion' => $l->descripcion, 'cantidad' => rtrim(rtrim((string) $l->cantidad, '0'), '.'),
                'unidad' => $l->unidad, 'precio_unitario' => rtrim(rtrim((string) $l->precio_unitario, '0'), '.'), 'precio_lista' => $l->precio_lista !== null ? rtrim(rtrim((string) $l->precio_lista, '0'), '.') : null,
                'descuento_pct' => rtrim(rtrim((string) $l->descuento_pct, '0'), '.'), 'descuento_monto' => $l->descuento_monto, 'monto_item' => $l->monto_item, 'exento' => $l->exento,
            ])->all(),
            'referencias' => $d->referencias->map(fn ($r) => ['documento_referido_id' => $r->documento_referido_id, 'nombre' => $r->referido?->nombre() ?? Documento::CORTOS[$r->tipo_dte_ref]." N° {$r->folio_ref}", 'codigo' => DocumentoReferencia::CODIGOS[$r->codigo] ?? null, 'razon' => $r->razon])->all(),
        ]);
    }
}
