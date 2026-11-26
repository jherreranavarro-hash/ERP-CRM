<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Models\Categoria;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Inventario\Models\CambioPrecio;
use App\Modules\Inventario\Models\CambioPrecioLinea;
use App\Modules\Inventario\Models\HistorialPrecio;
use App\Modules\Inventario\Precios;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Inventario → Actualización de Precios: solicitudes (individuales o masivas), aprobación e historial. */
class PreciosController extends Controller
{
    public function __construct(private Precios $precios) {}

    public function index(Request $request)
    {
        $vista = $request->input('vista') === 'historial' ? 'historial' : 'solicitudes';
        $verCosto = $request->user()->tienePermiso('finanzas.rentabilidad.ver') || $request->user()->es_superadmin;

        $datos = [
            'vista' => $vista,
            'filtros' => $request->only('estado', 'buscar', 'vista'),
            'estados' => CambioPrecio::ESTADOS,
            'umbral' => (float) app(Parametros::class)->valor('inventario.precio_variacion_pct'),
            'pendientes' => CambioPrecio::where('estado', 'por_aprobar')->count(),
        ];

        if ($vista === 'historial') {
            $q = HistorialPrecio::query()->join('productos as p', 'p.id', '=', 'historial_precios.producto_id')->leftJoin('users as u', 'u.id', '=', 'historial_precios.usuario_id')
                ->select('historial_precios.*', 'p.sku', 'p.nombre', 'u.name as usuario')->orderByDesc('historial_precios.id');
            if (! $verCosto) {
                $q->where('historial_precios.campo', '<>', 'costo');
            }
            if ($b = $request->string('buscar')->trim()->value()) {
                $q->where(fn ($q) => $q->where('p.nombre', 'ilike', "%{$b}%")->orWhere('p.sku', 'ilike', "%{$b}%"));
            }

            return Inertia::render('Inventario/Precios/Index', $datos + [
                'historial' => $q->paginate(50)->withQueryString()->through(fn ($h) => [
                    'id' => $h->id, 'fecha' => $h->created_at->toIso8601String(), 'sku' => $h->sku, 'producto' => $h->nombre, 'producto_id' => $h->producto_id,
                    'campo' => HistorialPrecio::CAMPOS[$h->campo], 'anterior' => $h->anterior !== null ? (float) $h->anterior : null, 'nuevo' => $h->nuevo !== null ? (float) $h->nuevo : null,
                    'origen' => HistorialPrecio::ORIGENES[$h->origen] ?? $h->origen, 'cambio_precio_id' => $h->cambio_precio_id, 'usuario' => $h->usuario,
                ]),
                'solicitudes' => null,
            ]);
        }

        $q = CambioPrecio::with('solicitante:id,name', 'aprobador:id,name')->withCount('lineas')->orderByDesc('numero');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }

        return Inertia::render('Inventario/Precios/Index', $datos + [
            'solicitudes' => $q->paginate(25)->withQueryString()->through(fn (CambioPrecio $c) => [
                'id' => $c->id, 'numero' => $c->numero, 'fecha' => $c->created_at->toIso8601String(), 'descripcion' => $c->descripcion, 'lineas' => $c->lineas_count,
                'estado' => $c->estado, 'solicitante' => $c->solicitante?->name, 'aprobador' => $c->aprobador?->name, 'motivos' => $c->motivos ?? [],
            ]),
            'historial' => null,
        ]);
    }

    public function create()
    {
        return Inertia::render('Inventario/Precios/Form', [
            'categorias' => Categoria::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'campos' => CambioPrecioLinea::CAMPOS,
            'umbral' => (float) app(Parametros::class)->valor('inventario.precio_variacion_pct'),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'descripcion' => ['required', 'string', 'max:150'],
            'cambios' => ['required', 'array', 'min:1', 'max:'.Precios::MAX_LINEAS],
            'cambios.*.producto_id' => ['required', 'integer'],
            'cambios.*.campo' => ['required', Rule::in(array_keys(CambioPrecioLinea::CAMPOS))],
            'cambios.*.nuevo' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ], [], ['cambios' => 'productos']);
        $c = $this->precios->proponer($d['cambios'], $d['descripcion'], $request->user());

        return $this->resultado($c);
    }

    public function masivo(Request $request)
    {
        $d = $request->validate([
            'descripcion' => ['required', 'string', 'max:150'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'campo' => ['required', Rule::in(array_keys(CambioPrecioLinea::CAMPOS))],
            'porcentaje' => ['required', 'numeric', 'between:-99.99,500'],
            'redondeo' => ['required', 'integer', Rule::in([1, 10, 50, 100])],
        ]);
        $c = $this->precios->masivo($d['categoria_id'] ?? null, $d['campo'], (string) $d['porcentaje'], (int) $d['redondeo'], $d['descripcion'], $request->user());

        return $this->resultado($c);
    }

    public function show(Request $request, CambioPrecio $cambio)
    {
        $cambio->load('lineas.producto:id,sku,nombre,costo', 'solicitante:id,name', 'aprobador:id,name');
        $verCosto = $request->user()->tienePermiso('finanzas.rentabilidad.ver') || $request->user()->es_superadmin;

        return Inertia::render('Inventario/Precios/Show', [
            'cambio' => [
                'id' => $cambio->id, 'numero' => $cambio->numero, 'descripcion' => $cambio->descripcion, 'estado' => $cambio->estado, 'motivos' => $cambio->motivos ?? [],
                'fecha' => $cambio->created_at->toIso8601String(), 'aplicado_at' => $cambio->aplicado_at?->toIso8601String(),
                'solicitante' => $cambio->solicitante?->name, 'aprobador' => $cambio->aprobador?->name, 'motivo_rechazo' => $cambio->motivo_rechazo,
                'propia' => $cambio->solicitado_por === $request->user()->id,
                'lineas' => $cambio->lineas->map(fn (CambioPrecioLinea $l) => [
                    'id' => $l->id, 'producto_id' => $l->producto_id, 'sku' => $l->producto?->sku, 'producto' => $l->producto?->nombre, 'campo' => CambioPrecioLinea::CAMPOS[$l->campo],
                    'anterior' => $l->anterior !== null ? (float) $l->anterior : null, 'nuevo' => $l->nuevo !== null ? (float) $l->nuevo : null,
                    'variacion' => $l->variacion_pct !== null ? (float) $l->variacion_pct : null,
                    'costo' => $verCosto && $l->producto?->costo !== null ? (float) $l->producto->costo : null,
                ])->all(),
            ],
        ]);
    }

    public function aprobar(Request $request, CambioPrecio $cambio)
    {
        $this->precios->aprobar($cambio, $request->user());

        return back()->with('exito', 'Cambio aprobado: los precios nuevos ya rigen.');
    }

    public function rechazar(Request $request, CambioPrecio $cambio)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->precios->rechazar($cambio, $request->user(), $d['motivo']);

        return back()->with('exito', 'Cambio rechazado: los precios no cambian.');
    }

    private function resultado(CambioPrecio $c)
    {
        return to_route('inventario.precios.show', $c)->with('exito', $c->estado === 'aplicado'
            ? "Solicitud N° {$c->numero} aplicada: los precios nuevos ya rigen."
            : "Solicitud N° {$c->numero} creada: espera la aprobación de otra persona.");
    }
}
