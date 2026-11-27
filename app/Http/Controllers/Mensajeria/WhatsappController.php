<?php

namespace App\Http\Controllers\Mensajeria;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Integraciones\Ia\Asistente;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Integraciones\Whatsapp\Whatsapp;
use App\Modules\Mensajeria\Bandeja;
use App\Modules\Mensajeria\Models\Conversacion;
use App\Modules\Mensajeria\Models\Plantilla;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Bandeja de WhatsApp. Cada ejecutivo ve sus conversaciones y las sin
 * asignar; con permiso de edición se ven todas y se reasignan.
 */
class WhatsappController extends Controller
{
    public function __construct(private Bandeja $bandeja) {}

    private function todas(Request $r): bool
    {
        return $r->user()->tienePermiso('ventascrm.whatsapp.editar');
    }

    private function visibles(Request $r)
    {
        return Conversacion::query()->when(! $this->todas($r), fn ($q) => $q->where(fn ($w) => $w->whereNull('asignado_a')->orWhere('asignado_a', $r->user()->id)));
    }

    private function autorizar(Request $r, Conversacion $c): void
    {
        abort_unless($this->todas($r) || $c->asignado_a === null || $c->asignado_a === $r->user()->id, 403);
    }

    public function index(Request $request, Whatsapp $wa)
    {
        $filtro = $request->input('filtro', 'abiertas');
        $buscar = trim((string) $request->input('buscar'));
        $lista = $this->visibles($request)->with('asignado:id,name')
            ->when($filtro === 'abiertas', fn ($q) => $q->where('estado', 'abierta'))
            ->when($filtro === 'no_leidas', fn ($q) => $q->where('no_leidos', '>', 0))
            ->when($filtro === 'mias', fn ($q) => $q->where('asignado_a', $request->user()->id))
            ->when($buscar, fn ($q) => $q->where(fn ($w) => $w->where('nombre', 'ilike', "%{$buscar}%")->orWhere('telefono', 'like', '%'.preg_replace('/\D/', '', $buscar).'%')))
            ->orderByDesc('ultimo_mensaje_at')->limit(100)->get();
        $actual = null;
        if ($id = $request->integer('c')) {
            $c = Conversacion::with(['cliente:id,razon_social,rut', 'lead:id,nombre,estado,campana_id', 'asignado:id,name'])->findOrFail($id);
            $this->autorizar($request, $c);
            $this->bandeja->leer($c);
            $actual = [
                'id' => $c->id, 'nombre' => $c->nombre, 'telefono' => $c->telefono, 'estado' => $c->estado, 'en_ventana' => $c->enVentana(), 'ventana_hasta' => $c->ventana_hasta?->toIso8601String(),
                'asignado_a' => $c->asignado_a, 'asignado' => $c->asignado?->name,
                'cliente' => $c->cliente ? ['id' => $c->cliente->id, 'nombre' => $c->cliente->razon_social] : null,
                'lead' => $c->lead ? ['id' => $c->lead->id, 'nombre' => $c->lead->nombre, 'estado' => $c->lead->estado, 'campana_id' => $c->lead->campana_id] : null,
                'mensajes' => $c->mensajes()->with('user:id,name')->latest('id')->limit(200)->get()->reverse()->values()->map(fn ($m) => ['id' => $m->id, 'direccion' => $m->direccion,
                    'tipo' => $m->tipo, 'cuerpo' => $m->cuerpo, 'estado' => $m->estado, 'error' => $m->error, 'autor' => $m->user?->name, 'fecha' => $m->created_at->toIso8601String()]),
            ];
        }
        $r = config('reverb.apps.apps.0') ?? [];

        return Inertia::render('Mensajeria/Whatsapp', [
            'conversaciones' => $lista->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'telefono' => $c->telefono, 'estado' => $c->estado, 'no_leidos' => $c->no_leidos,
                'ultimo' => $c->ultimo_mensaje_at?->toIso8601String(), 'asignado' => $c->asignado?->name, 'en_ventana' => $c->enVentana()]),
            'actual' => $actual,
            'filtros' => ['filtro' => $filtro, 'buscar' => $buscar],
            'plantillas' => Plantilla::where('estado', 'aprobada')->orderBy('nombre')->get(['id', 'nombre', 'idioma', 'cuerpo'])->map(fn ($p) => $p->only(['id', 'nombre', 'idioma', 'cuerpo']) + ['variables' => $p->variables()]),
            'activa' => Integracion::activa('whatsapp'),
            'iaActiva' => Integracion::activa('ia'),
            'simulado' => $wa->simulado(),
            'puedeEscribir' => $request->user()->tienePermiso('ventascrm.whatsapp.crear'),
            'puedeTodas' => $this->todas($request),
            'canal' => 'whatsapp.'.app(Tenancy::class)->id(),
            'conexion' => config('broadcasting.default') === 'reverb' && $r ? ['key' => $r['key'], 'host' => $r['options']['host'], 'port' => (int) $r['options']['port'], 'tls' => $r['options']['scheme'] === 'https'] : null,
        ]);
    }

    public function iniciar(Request $request)
    {
        $d = $request->validate(['cliente_id' => ['nullable', 'integer', 'required_without_all:lead_id,telefono'], 'lead_id' => ['nullable', 'integer'], 'telefono' => ['nullable', 'string', 'max:20'], 'nombre' => ['nullable', 'string', 'max:120']]);
        $cliente = ! empty($d['cliente_id']) ? Cliente::findOrFail($d['cliente_id']) : null;
        $lead = ! empty($d['lead_id']) ? Lead::findOrFail($d['lead_id']) : null;
        $telefono = $d['telefono'] ?? $lead?->telefono ?? $cliente?->telefono;
        abort_if(! $telefono, 422, 'El contacto no tiene teléfono.');
        $c = $this->bandeja->iniciar($telefono, $d['nombre'] ?? $lead?->nombre ?? $cliente?->razon_social, $cliente?->id, $lead?->id, $request->user());

        return to_route('ventas.whatsapp', ['c' => $c->id]);
    }

    public function texto(Request $request, Conversacion $conversacion)
    {
        $this->autorizar($request, $conversacion);
        $d = $request->validate(['texto' => ['required', 'string', 'max:4096']]);
        $this->bandeja->enviarTexto($conversacion, $d['texto'], $request->user());

        return back();
    }

    public function plantilla(Request $request, Conversacion $conversacion)
    {
        $this->autorizar($request, $conversacion);
        $d = $request->validate(['plantilla_id' => ['required', 'integer'], 'variables' => ['array', 'max:10'], 'variables.*' => ['nullable', 'string', 'max:200']]);
        $this->bandeja->enviarPlantilla($conversacion, Plantilla::findOrFail($d['plantilla_id']), $d['variables'] ?? [], $request->user());

        return back()->with('exito', 'Plantilla enviada.');
    }

    /** Cerrar, reabrir, tomar o reasignar. */
    public function estado(Request $request, Conversacion $conversacion)
    {
        $this->autorizar($request, $conversacion);
        $d = $request->validate(['estado' => ['nullable', 'in:abierta,cerrada'], 'asignado_a' => ['nullable', 'integer']]);
        if ($request->has('asignado_a')) {
            $nuevo = $d['asignado_a'] ?? null;
            abort_unless($nuevo === $request->user()->id || $this->todas($request), 403);
            $conversacion->asignado_a = $nuevo;
        }
        if (! empty($d['estado'])) {
            $conversacion->estado = $d['estado'];
        }
        $conversacion->save();
        $this->bandeja->avisar($conversacion);

        return back();
    }

    public function ia(Request $request, Conversacion $conversacion, string $accion, Asistente $asistente)
    {
        $this->autorizar($request, $conversacion);
        $texto = $accion === 'sugerir' ? $asistente->sugerir($conversacion, $request->user()) : $asistente->resumir($conversacion, $request->user());

        return response()->json(['texto' => $texto]);
    }
}
