<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Inventario\Despachos;
use App\Modules\Inventario\Models\Despacho;
use App\Modules\Inventario\Transporte\Transportistas;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Inventario → Despachos: programación, ruta, entrega con firma y seguimiento. */
class DespachosController extends Controller
{
    public function __construct(private Despachos $despachos, private Transportistas $transportistas) {}

    public function index(Request $request)
    {
        $q = Despacho::with('documento:id,tipo_dte,folio', 'cliente:id,razon_social', 'local:id,nombre')->orderByDesc('fecha_programada')->orderByDesc('numero');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }
        if ($f = $request->input('fecha')) {
            $q->whereDate('fecha_programada', $f);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => ctype_digit($b)
                ? $q->where('numero', (int) $b)->orWhereHas('documento', fn ($d) => $d->where('folio', (int) $b))
                : $q->where('comuna', 'ilike', "%{$b}%")->orWhere('direccion', 'ilike', "%{$b}%")->orWhereHas('cliente', fn ($c) => $c->where('razon_social', 'ilike', "%{$b}%")));
        }
        $fila = fn (Despacho $d) => [
            'id' => $d->id, 'numero' => $d->numero, 'fecha' => $d->fecha_programada->toDateString(), 'ventana' => $d->ventana, 'documento' => $d->documento?->nombre(),
            'cliente' => $d->cliente?->razon_social, 'direccion' => $d->direccion, 'comuna' => $d->comuna, 'transportista' => $this->transportistas->opciones()[$d->transportista] ?? $d->transportista,
            'tracking' => $d->tracking, 'estado' => $d->estado, 'intentos' => $d->intentos,
        ];

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('inventario.despachos.exportar'), 403);

            return Csv::descargar('despachos', ['N°', 'Fecha', 'Ventana', 'Documento', 'Cliente', 'Dirección', 'Comuna', 'Transportista', 'Seguimiento', 'Estado', 'Intentos'],
                $q->get()->map(fn ($d) => array_values(array_diff_key($fila($d), ['id' => 1]))));
        }

        $hoy = now()->toDateString();

        return Inertia::render('Inventario/Despachos/Index', [
            'despachos' => $q->paginate(25)->withQueryString()->through($fila),
            'filtros' => $request->only('estado', 'fecha', 'buscar'),
            'estados' => Despacho::ESTADOS,
            'resumen' => [
                'hoy' => Despacho::whereDate('fecha_programada', $hoy)->whereIn('estado', ['programado', 'en_ruta'])->count(),
                'en_ruta' => Despacho::where('estado', 'en_ruta')->count(),
                'por_aprobar' => Despacho::where('estado', 'por_aprobar')->count(),
                'fallidos' => Despacho::where('estado', 'fallido')->count(),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $doc = Documento::with('cliente')->findOrFail($request->integer('documento_id'));
        $p = app(Parametros::class);

        return Inertia::render('Inventario/Despachos/Form', [
            'documento' => [
                'id' => $doc->id, 'nombre' => $doc->nombre(), 'total' => $doc->monto_total, 'cliente' => $doc->cliente?->razon_social,
                'direccion' => $doc->cliente?->direccion, 'comuna' => $doc->cliente?->comuna, 'telefono' => $doc->cliente?->telefono,
            ],
            'transportistas' => $this->transportistas->opciones(),
            'tarifa' => (int) $p->valor('despacho.tarifa_base', $doc->local_id),
            'gratisDesde' => (int) $p->valor('despacho.gratis_desde', $doc->local_id),
            'gratisRequiereAprobacion' => (bool) $p->valor('aprobaciones.despacho_gratis'),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'documento_id' => ['required', 'integer'],
            'direccion' => ['required', 'string', 'max:150'],
            'comuna' => ['required', 'string', 'max:60'],
            'contacto' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'fecha_programada' => ['required', 'date'],
            'ventana' => ['nullable', Rule::in(['am', 'pm'])],
            'transportista' => ['required', 'string', 'max:30'],
            'cobro' => ['required', 'integer', 'min:0', 'max:10000000'],
            'observaciones' => ['nullable', 'string', 'max:300'],
        ]);
        $desp = $this->despachos->crear(Documento::findOrFail($d['documento_id']), $d, $request->user());

        return to_route('inventario.despachos.show', $desp)->with('exito', $desp->estado === 'por_aprobar'
            ? "Despacho N° {$desp->numero} creado: el despacho gratis espera la aprobación de otra persona."
            : "Despacho N° {$desp->numero} programado para el ".$desp->fecha_programada->format('d-m-Y').'.');
    }

    public function show(Request $request, Despacho $despacho)
    {
        $despacho->load('documento:id,tipo_dte,folio,monto_total', 'cliente:id,razon_social,rut', 'local:id,nombre', 'eventos.usuario:id,name', 'creador:id,name', 'aprobador:id,name');

        return Inertia::render('Inventario/Despachos/Show', [
            'despacho' => [
                'id' => $despacho->id, 'numero' => $despacho->numero, 'estado' => $despacho->estado, 'fecha' => $despacho->fecha_programada->toDateString(), 'ventana' => $despacho->ventana,
                'documento' => $despacho->documento ? ['id' => $despacho->documento->id, 'nombre' => $despacho->documento->nombre(), 'total' => $despacho->documento->monto_total] : null,
                'cliente' => $despacho->cliente?->razon_social, 'rut' => $despacho->cliente?->rut, 'local' => $despacho->local?->nombre,
                'direccion' => $despacho->direccion, 'comuna' => $despacho->comuna, 'contacto' => $despacho->contacto, 'telefono' => $despacho->telefono,
                'transportista' => $this->transportistas->opciones()[$despacho->transportista] ?? $despacho->transportista, 'tracking' => $despacho->tracking,
                'cobro' => $despacho->cobro, 'motivos' => $despacho->motivos ?? [], 'observaciones' => $despacho->observaciones, 'intentos' => $despacho->intentos,
                'motivo_fallo' => $despacho->motivo_fallo, 'recibido_nombre' => $despacho->recibido_nombre, 'recibido_rut' => $despacho->recibido_rut,
                'entregado_at' => $despacho->entregado_at?->toIso8601String(), 'tiene_firma' => (bool) $despacho->firma_path,
                'creador' => $despacho->creador?->name, 'aprobador' => $despacho->aprobador?->name, 'propio' => $despacho->creado_por === $request->user()->id,
                'siguientes' => Despacho::TRANSICIONES[$despacho->estado],
                'eventos' => $despacho->eventos->map(fn ($e) => ['de' => $e->de, 'a' => $e->a, 'nota' => $e->nota, 'usuario' => $e->usuario?->name, 'fecha' => $e->created_at->toIso8601String()])->all(),
            ],
            'estados' => Despacho::ESTADOS,
        ]);
    }

    public function firma(Despacho $despacho)
    {
        $png = $this->despachos->firma($despacho) ?? abort(404);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function aprobar(Request $request, Despacho $despacho)
    {
        $this->despachos->aprobar($despacho, $request->user());

        return back()->with('exito', 'Despacho gratis aprobado: queda programado.');
    }

    public function despachar(Request $request, Despacho $despacho)
    {
        $d = $this->despachos->despachar($despacho, $request->user());

        return back()->with('exito', 'Despacho en ruta.'.($d->tracking ? " Seguimiento: {$d->tracking}." : ''));
    }

    public function entregar(Request $request, Despacho $despacho)
    {
        $d = $request->validate([
            'recibido_nombre' => ['required', 'string', 'max:100'],
            'recibido_rut' => ['nullable', 'string', 'max:12'],
            'firma' => ['required', 'string', 'max:450000'],
        ], ['firma.required' => 'Falta la firma de quien recibe.']);
        $this->despachos->entregar($despacho, $d['recibido_nombre'], $d['recibido_rut'] ?? null, $d['firma'], $request->user());

        return back()->with('exito', 'Entrega registrada con firma.');
    }

    public function fallar(Request $request, Despacho $despacho)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->despachos->fallar($despacho, $d['motivo'], $request->user());

        return back()->with('exito', 'Entrega fallida registrada: reprográmala o anúlala.');
    }

    public function reprogramar(Request $request, Despacho $despacho)
    {
        $d = $request->validate(['fecha_programada' => ['required', 'date']]);
        $this->despachos->reprogramar($despacho, $d['fecha_programada'], $request->user());

        return back()->with('exito', 'Despacho reprogramado.');
    }

    public function anular(Request $request, Despacho $despacho)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->despachos->anular($despacho, $d['motivo'], $request->user());

        return back()->with('exito', 'Despacho anulado.');
    }
}
