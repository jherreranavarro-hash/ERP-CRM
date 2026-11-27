<?php

namespace App\Http\Controllers\Terreno;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Integraciones\Mapas\Mapas;
use App\Modules\Terreno\Models\Parada;
use App\Modules\Terreno\Models\Recorrido;
use App\Modules\Terreno\Recorridos;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** VentasCRM → Lista de recorrido. Cada ejecutivo ve el suyo; con permiso de edición se planifica el de otros. */
class RecorridosController extends Controller
{
    public function __construct(private Recorridos $recorridos) {}

    private function puedeOtros(Request $r): bool
    {
        return $r->user()->tienePermiso('ventascrm.lista-de-recorrido.editar');
    }

    private function autorizar(Request $r, Recorrido $rec): void
    {
        abort_unless($rec->user_id === $r->user()->id || $this->puedeOtros($r), 403);
    }

    public function index(Request $request, Mapas $mapas)
    {
        $fecha = rescue(fn () => CarbonImmutable::parse((string) $request->input('fecha', today()->toDateString()))->toDateString(), today()->toDateString(), false);
        $ejecutivoId = $this->puedeOtros($request) ? $request->integer('ejecutivo', $request->user()->id) : $request->user()->id;
        $rec = Recorrido::with('paradas')->where('user_id', $ejecutivoId)->whereDate('fecha', $fecha)->first();
        $buscar = trim((string) $request->input('buscar'));

        $clientes = Cliente::where('activo', true)->whereNotNull('direccion')->where('direccion', '!=', '')
            ->when($buscar, fn ($q) => $q->where(fn ($w) => $w->where('razon_social', 'ilike', "%{$buscar}%")->orWhere('comuna', 'ilike', "%{$buscar}%")->orWhere('rut', 'like', "%{$buscar}%")))
            ->orderBy('razon_social')->limit(30)->get(['id', 'razon_social', 'direccion', 'comuna']);
        $leads = Lead::whereIn('estado', Lead::ABIERTOS)->whereNotNull('direccion')->where('direccion', '!=', '')
            ->whereHas('agente', fn ($q) => $q->where('user_id', $ejecutivoId))
            ->when($buscar, fn ($q) => $q->where(fn ($w) => $w->where('nombre', 'ilike', "%{$buscar}%")->orWhere('comuna', 'ilike', "%{$buscar}%")))
            ->orderBy('nombre')->limit(30)->get(['id', 'nombre', 'direccion', 'comuna', 'estado']);

        return Inertia::render('Terreno/Recorrido', [
            'fecha' => $fecha,
            'ejecutivoId' => $ejecutivoId,
            'esMio' => $ejecutivoId === $request->user()->id,
            'ejecutivos' => $this->puedeOtros($request) ? User::whereIn('id', UsuarioEmpresa::where('empresa_id', app(Tenancy::class)->id())->where('activo', true)->pluck('user_id'))
                ->orderBy('name')->get(['id', 'name']) : [],
            'recorrido' => $rec ? [
                'id' => $rec->id, 'estado' => $rec->estado, 'origen' => $rec->origen, 'origen_lat' => $rec->origen_lat, 'origen_lng' => $rec->origen_lng,
                'distancia_km' => $rec->distancia_km, 'duracion_min' => $rec->duracion_min, 'optimizado' => $rec->optimizado_at !== null,
                'paradas' => $rec->paradas->map(fn (Parada $p) => $p->only(['id', 'orden', 'nombre', 'direccion', 'comuna', 'lat', 'lng', 'estado', 'resultado', 'notas', 'cliente_id', 'lead_id'])
                    + ['visitada_at' => $p->visitada_at?->toIso8601String()]),
            ] : null,
            'candidatos' => [
                'clientes' => $clientes->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->razon_social, 'direccion' => $c->direccion, 'comuna' => $c->comuna]),
                'leads' => $leads->map(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre, 'direccion' => $l->direccion, 'comuna' => $l->comuna, 'estado' => $l->estado]),
            ],
            'buscar' => $buscar,
            'origenSugerido' => Local::whereNotNull('direccion')->orderBy('es_bodega')->orderBy('codigo')->first(['direccion', 'comuna']),
            'historial' => Recorrido::withCount(['paradas', 'paradas as visitadas_count' => fn ($q) => $q->where('estado', 'visitada')])->where('user_id', $ejecutivoId)
                ->orderByDesc('fecha')->limit(8)->get()->map(fn ($r) => ['fecha' => $r->fecha->toDateString(), 'estado' => $r->estado, 'paradas' => $r->paradas_count, 'visitadas' => $r->visitadas_count,
                    'km' => $r->distancia_km]),
            'resultados' => Parada::RESULTADOS,
            'maxParadas' => Recorridos::MAX_PARADAS,
            'mapasSimulado' => $mapas->simulado(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'fecha' => ['required', 'date', 'after_or_equal:today'],
            'ejecutivo_id' => ['nullable', 'integer'],
            'origen' => ['nullable', 'string', 'max:160'], 'comuna_origen' => ['nullable', 'string', 'max:60'],
            'paradas' => ['required', 'array', 'min:1', 'max:'.Recorridos::MAX_PARADAS],
            'paradas.*.cliente_id' => ['nullable', 'integer'], 'paradas.*.lead_id' => ['nullable', 'integer'],
        ]);
        $ejecutivoId = (int) ($d['ejecutivo_id'] ?? $request->user()->id);
        abort_unless($ejecutivoId === $request->user()->id || $this->puedeOtros($request), 403);
        abort_unless(UsuarioEmpresa::where('empresa_id', app(Tenancy::class)->id())->where('user_id', $ejecutivoId)->where('activo', true)->exists(), 422);
        $r = $this->recorridos->crear(User::findOrFail($ejecutivoId), $d['fecha'], $d['origen'] ?? null, $d['comuna_origen'] ?? null, $d['paradas'], $request->user());

        return to_route('ventas.recorrido', ['fecha' => $r->fecha->toDateString(), 'ejecutivo' => $r->user_id])
            ->with('exito', 'Recorrido creado y ordenado: '.$r->paradas->count().' paradas, '.number_format((float) $r->distancia_km, 1, ',', '.').' km aprox.');
    }

    public function agregar(Request $request, Recorrido $recorrido)
    {
        $this->autorizar($request, $recorrido);
        $d = $request->validate(['paradas' => ['required', 'array', 'min:1', 'max:'.Recorridos::MAX_PARADAS], 'paradas.*.cliente_id' => ['nullable', 'integer'], 'paradas.*.lead_id' => ['nullable', 'integer']]);
        $this->recorridos->agregar($recorrido, $d['paradas']);
        $this->recorridos->optimizar($recorrido->fresh());

        return back()->with('exito', 'Parada agregada y recorrido reordenado.');
    }

    public function optimizar(Request $request, Recorrido $recorrido)
    {
        $this->autorizar($request, $recorrido);
        $r = $this->recorridos->optimizar($recorrido);

        return back()->with('exito', 'Orden optimizado: '.number_format((float) $r->distancia_km, 1, ',', '.').' km, unos '.$r->duracion_min.' minutos con visitas.');
    }

    public function quitar(Request $request, Parada $parada)
    {
        $this->autorizar($request, Recorrido::findOrFail($parada->recorrido_id));
        $this->recorridos->quitar($parada);

        return back()->with('exito', 'Parada quitada.');
    }

    public function marcar(Request $request, Parada $parada)
    {
        $d = $request->validate(['estado' => ['required', Rule::in(['visitada', 'no_visitada'])], 'resultado' => ['nullable', Rule::in(array_keys(Parada::RESULTADOS))], 'notas' => ['nullable', 'string', 'max:1000']]);
        $this->recorridos->marcar($parada, $d['estado'], $d['resultado'] ?? null, $d['notas'] ?? null, $request->user());

        return back()->with('exito', 'Visita registrada.');
    }

    public function cerrar(Request $request, Recorrido $recorrido)
    {
        $this->autorizar($request, $recorrido);
        $this->recorridos->cerrar($recorrido, $request->user());

        return back()->with('exito', 'Recorrido cerrado. Las paradas pendientes quedaron para reagendar.');
    }
}
