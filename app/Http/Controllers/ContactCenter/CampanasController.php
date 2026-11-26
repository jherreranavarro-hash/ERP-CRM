<?php

namespace App\Http\Controllers\ContactCenter;

use App\Http\Controllers\Controller;
use App\Modules\ContactCenter\Asignador;
use App\Modules\ContactCenter\Models\Agente;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Cola;
use App\Modules\ContactCenter\Models\Guion;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\Llamada;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** VentasCRM → Campaña: metas, horario, guion, agentes y prospectos (con carga por CSV). */
class CampanasController extends Controller
{
    public function index()
    {
        $campanas = Campana::withCount(['leads', 'agentes', 'leads as ganados_count' => fn ($q) => $q->where('estado', 'ganado'),
            'leads as abiertos_count' => fn ($q) => $q->whereIn('estado', Lead::ABIERTOS)])->orderByRaw("estado = 'activa' desc")->orderByDesc('fecha_inicio')->get();
        $contactos = Llamada::whereNotNull('contestada_at')->whereNotNull('campana_id')->groupBy('campana_id')->selectRaw('campana_id, count(distinct lead_id) as n')->pluck('n', 'campana_id');

        return Inertia::render('ContactCenter/Campanas/Index', [
            'campanas' => $campanas->map(fn (Campana $c) => ['id' => $c->id, 'nombre' => $c->nombre, 'tipo' => $c->tipo, 'estado' => $c->estado, 'inicio' => $c->fecha_inicio->toDateString(),
                'fin' => $c->fecha_fin?->toDateString(), 'leads' => $c->leads_count, 'abiertos' => $c->abiertos_count, 'ganados' => $c->ganados_count, 'agentes' => $c->agentes_count,
                'contactos' => (int) ($contactos[$c->id] ?? 0), 'meta_contactos' => $c->meta_contactos]),
            'tipos' => Campana::TIPOS, 'estados' => Campana::ESTADOS,
        ]);
    }

    public function create()
    {
        return Inertia::render('ContactCenter/Campanas/Form', $this->opciones() + ['campana' => null]);
    }

    public function edit(Campana $campana)
    {
        return Inertia::render('ContactCenter/Campanas/Form', $this->opciones() + ['campana' => $campana->only(['id', 'nombre', 'tipo', 'descripcion', 'meta_contactos', 'meta_cotizaciones',
            'meta_ventas', 'horario', 'max_intentos', 'asignacion', 'guion_id', 'cola_id']) + ['fecha_inicio' => $campana->fecha_inicio->toDateString(), 'fecha_fin' => $campana->fecha_fin?->toDateString(),
                'agentes' => $campana->agentes()->pluck('agentes.id')]]);
    }

    public function store(Request $request, Auditoria $auditoria)
    {
        $c = $this->guardar($request, new Campana(['creado_por' => $request->user()->id, 'estado' => 'borrador']));

        return to_route('ventas.campanas.show', $c)->with('exito', 'Campaña creada en borrador: carga los prospectos y actívala.');
    }

    public function update(Request $request, Campana $campana)
    {
        $this->guardar($request, $campana);

        return to_route('ventas.campanas.show', $campana)->with('exito', 'Campaña actualizada.');
    }

    public function show(Request $request, Campana $campana)
    {
        $campana->load('guion:id,nombre', 'cola:id,nombre', 'agentes.user:id,name');
        $q = Lead::with('agente.user:id,name')->where('campana_id', $campana->id)->orderBy('id');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($w) => $w->where('nombre', 'ilike', "%{$b}%")->orWhere('telefono', 'like', '%'.preg_replace('/\D/', '', $b).'%'));
        }
        $porEstado = Lead::where('campana_id', $campana->id)->groupBy('estado')->selectRaw('estado, count(*) as n')->pluck('n', 'estado');
        $llamadas = Llamada::where('campana_id', $campana->id);
        $cotizaciones = Lead::where('campana_id', $campana->id)->whereNotNull('cotizacion_id')->count() + (clone $llamadas)->where('resultado', 'cotizacion')->count();
        $ventas = (int) DB::table('leads as l')->join('cotizaciones as c', 'c.id', '=', 'l.cotizacion_id')->join('documentos as d', 'd.id', '=', 'c.documento_id')
            ->where('l.campana_id', $campana->id)->where('l.empresa_id', app(Tenancy::class)->id())->sum(DB::raw('d.monto_neto + d.monto_exento'));

        return Inertia::render('ContactCenter/Campanas/Show', [
            'campana' => ['id' => $campana->id, 'nombre' => $campana->nombre, 'tipo' => $campana->tipo, 'estado' => $campana->estado, 'descripcion' => $campana->descripcion,
                'inicio' => $campana->fecha_inicio->toDateString(), 'fin' => $campana->fecha_fin?->toDateString(), 'horario' => $campana->horario, 'max_intentos' => $campana->max_intentos,
                'asignacion' => $campana->asignacion, 'guion' => $campana->guion?->nombre, 'cola' => $campana->cola?->nombre,
                'agentes' => $campana->agentes->map(fn ($a) => ['id' => $a->id, 'nombre' => $a->nombre()])],
            'avance' => [
                'contactos' => (int) (clone $llamadas)->whereNotNull('contestada_at')->distinct()->count('lead_id'), 'meta_contactos' => $campana->meta_contactos,
                'cotizaciones' => $cotizaciones, 'meta_cotizaciones' => $campana->meta_cotizaciones,
                'ventas' => $ventas, 'meta_ventas' => $campana->meta_ventas,
                'llamadas' => (clone $llamadas)->count(),
            ],
            'porEstado' => $porEstado,
            'leads' => $q->paginate(50)->withQueryString()->through(fn (Lead $l) => ['id' => $l->id, 'nombre' => $l->nombre, 'telefono' => $l->telefono, 'comuna' => $l->comuna,
                'estado' => $l->estado, 'agente' => $l->agente?->nombre(), 'intentos' => $l->intentos, 'ultimo' => $l->ultimo_contacto_at?->toIso8601String()]),
            'filtros' => $request->only('estado', 'buscar'),
            'estadosLead' => Lead::ESTADOS, 'estados' => Campana::ESTADOS, 'tipos' => Campana::TIPOS, 'asignaciones' => Campana::ASIGNACION,
        ]);
    }

    public function estado(Request $request, Campana $campana, Auditoria $auditoria)
    {
        $d = $request->validate(['estado' => ['required', Rule::in(['activa', 'pausada', 'terminada'])]]);
        $permitidos = ['borrador' => ['activa', 'terminada'], 'activa' => ['pausada', 'terminada'], 'pausada' => ['activa', 'terminada'], 'terminada' => []];
        if (! in_array($d['estado'], $permitidos[$campana->estado], true)) {
            throw ValidationException::withMessages(['estado' => 'No se puede pasar de '.Campana::ESTADOS[$campana->estado].' a '.Campana::ESTADOS[$d['estado']].'.']);
        }
        if ($d['estado'] === 'activa' && $campana->tipo === 'saliente' && ! $campana->agentes()->exists()) {
            throw ValidationException::withMessages(['estado' => 'Asigna al menos un agente antes de activar.']);
        }
        $antes = $campana->estado;
        $campana->forceFill(['estado' => $d['estado']])->save();
        $auditoria->registrar('campana.estado', $campana, ['estado' => $antes], ['estado' => $d['estado']]);

        return back()->with('exito', 'Campaña '.mb_strtolower(Campana::ESTADOS[$d['estado']]).'.');
    }

    public function importar(Request $request, Campana $campana, Asignador $asignador)
    {
        $request->validate(['archivo' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);
        $r = $asignador->importar($campana, (string) file_get_contents($request->file('archivo')->getRealPath()), $request->user());
        $msg = "{$r['creados']} prospectos cargados";
        $msg .= $r['duplicados'] ? ", {$r['duplicados']} repetidos omitidos" : '';
        $msg .= $r['bloqueados'] ? ", {$r['bloqueados']} en lista negra (no se llamarán)" : '';

        if ($r['errores']) {
            $msg .= '. '.count($r['errores']).' filas con error; la primera: '.$r['errores'][0];
        }

        return back()->with('exito', rtrim($msg, '.').'.');
    }

    private function opciones(): array
    {
        return [
            'tipos' => Campana::TIPOS, 'asignaciones' => Campana::ASIGNACION,
            'guiones' => Guion::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'colas' => Cola::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'agentes' => Agente::with('user:id,name')->where('activo', true)->orderBy('extension')->get()->map(fn ($a) => ['id' => $a->id, 'nombre' => $a->nombre(), 'extension' => $a->extension]),
        ];
    }

    private function guardar(Request $request, Campana $c): Campana
    {
        $e = app(Tenancy::class)->id();
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('campanas')->where('empresa_id', $e)->ignore($c->id)],
            'tipo' => ['required', Rule::in(array_keys(Campana::TIPOS))],
            'descripcion' => ['nullable', 'string', 'max:2000'],
            'fecha_inicio' => ['required', 'date'], 'fecha_fin' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'meta_contactos' => ['required', 'integer', 'min:0'], 'meta_cotizaciones' => ['required', 'integer', 'min:0'], 'meta_ventas' => ['required', 'integer', 'min:0'],
            'horario.dias' => ['required', 'array', 'min:1'], 'horario.dias.*' => ['integer', 'between:1,7'],
            'horario.desde' => ['required', 'date_format:H:i'], 'horario.hasta' => ['required', 'date_format:H:i', 'after:horario.desde'],
            'max_intentos' => ['required', 'integer', 'min:1', 'max:20'],
            'asignacion' => ['required', Rule::in(array_keys(Campana::ASIGNACION))],
            'guion_id' => ['nullable', 'integer', Rule::exists('guiones', 'id')->where('empresa_id', $e)],
            'cola_id' => ['nullable', 'integer', Rule::exists('colas', 'id')->where('empresa_id', $e)],
            'agentes' => ['array'], 'agentes.*' => ['integer', Rule::exists('agentes', 'id')->where('empresa_id', $e)],
        ]);
        if ($c->exists && $c->estado === 'terminada') {
            throw ValidationException::withMessages(['nombre' => 'Una campaña terminada no se edita.']);
        }
        $d['horario']['dias'] = array_values(array_unique(array_map('intval', $d['horario']['dias'])));
        sort($d['horario']['dias']);
        $c->fill(collect($d)->except('agentes')->all())->save();
        $c->agentes()->sync(collect($d['agentes'] ?? [])->mapWithKeys(fn ($id) => [$id => ['empresa_id' => $e]])->all());

        return $c;
    }
}
