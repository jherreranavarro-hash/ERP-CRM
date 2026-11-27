<?php

namespace App\Http\Controllers\Mensajeria;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Mensajeria\Linkedin\BandejaLinkedin;
use App\Modules\Mensajeria\Models\LinkedinConversacion;
use App\Modules\Mensajeria\Trabajos\SincronizarLinkedin;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** VentasCRM → LinkedIn: bandeja de prospección B2B. Mismas reglas de visibilidad que WhatsApp. */
class LinkedinController extends Controller
{
    public function __construct(private BandejaLinkedin $bandeja) {}

    private function todas(Request $r): bool
    {
        return $r->user()->tienePermiso('ventascrm.linkedin.editar');
    }

    private function autorizar(Request $r, LinkedinConversacion $c): void
    {
        abort_unless($this->todas($r) || $c->asignado_a === null || $c->asignado_a === $r->user()->id, 403);
    }

    public function index(Request $request)
    {
        $filtro = $request->input('filtro', 'abiertas');
        $lista = LinkedinConversacion::with('asignado:id,name')
            ->when(! $this->todas($request), fn ($q) => $q->where(fn ($w) => $w->whereNull('asignado_a')->orWhere('asignado_a', $request->user()->id)))
            ->when($filtro === 'abiertas', fn ($q) => $q->where('estado', 'abierta'))
            ->when($filtro === 'no_leidas', fn ($q) => $q->where('no_leidos', '>', 0))
            ->when($filtro === 'mias', fn ($q) => $q->where('asignado_a', $request->user()->id))
            ->orderByDesc('ultimo_mensaje_at')->limit(100)->get();

        $actual = null;
        if ($id = $request->integer('c')) {
            $c = LinkedinConversacion::with(['lead:id,nombre,estado', 'asignado:id,name', 'mensajes'])->findOrFail($id);
            $this->autorizar($request, $c);
            $this->bandeja->leer($c);
            $actual = $c->only(['id', 'nombre', 'cargo', 'organizacion', 'perfil_url', 'estado', 'asignado_a']) + [
                'asignado' => $c->asignado?->name, 'lead' => $c->lead?->only(['id', 'nombre', 'estado']),
                'mensajes' => $c->mensajes->map(fn ($m) => $m->only(['id', 'direccion', 'cuerpo', 'estado', 'error']) + ['fecha' => $m->created_at->toIso8601String()]),
            ];
        }

        return Inertia::render('Mensajeria/Linkedin', [
            'activa' => Integracion::activa('linkedin'),
            'filtro' => $filtro,
            'conversaciones' => $lista->map(fn ($c) => $c->only(['id', 'nombre', 'cargo', 'organizacion', 'estado', 'no_leidos', 'asignado_a']) + [
                'asignado' => $c->asignado?->name, 'ultimo' => $c->ultimo_mensaje_at?->toIso8601String(), 'con_lead' => $c->lead_id !== null,
            ]),
            'actual' => $actual,
            'puedeTodas' => $this->todas($request),
            'campanas' => Campana::whereIn('estado', ['borrador', 'activa', 'pausada'])->orderBy('nombre')->get(['id', 'nombre']),
            'usuarios' => $this->todas($request) ? User::whereIn('id', \App\Modules\Core\Models\UsuarioEmpresa::where('activo', true)->select('user_id'))->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    /** Sincroniza ahora (en cola si hay worker; la pantalla se recarga sola). */
    public function sincronizar(Tenancy $tenancy)
    {
        SincronizarLinkedin::dispatchSync($tenancy->id());

        return back()->with('exito', 'Bandeja de LinkedIn sincronizada.');
    }

    public function texto(Request $request, LinkedinConversacion $conversacion)
    {
        $this->autorizar($request, $conversacion);
        $d = $request->validate(['texto' => ['required', 'string', 'max:3000']]);
        $this->bandeja->enviar($conversacion, trim($d['texto']), $request->user());

        return back();
    }

    public function lead(Request $request, LinkedinConversacion $conversacion)
    {
        $this->autorizar($request, $conversacion);
        $d = $request->validate(['campana_id' => ['required', 'integer', Rule::exists('campanas', 'id')], 'telefono' => ['required', 'string', 'max:20'], 'comuna' => ['nullable', 'string', 'max:60']]);
        $lead = $this->bandeja->crearLead($conversacion, Campana::findOrFail($d['campana_id']), $d['telefono'], $d['comuna'] ?? null, $request->user());

        return back()->with('exito', "Prospecto {$lead->nombre} creado en la campaña.");
    }

    public function estado(Request $request, LinkedinConversacion $conversacion)
    {
        $this->autorizar($request, $conversacion);
        $d = $request->validate(['accion' => ['required', Rule::in(['cerrar', 'abrir', 'tomar', 'asignar'])], 'user_id' => ['nullable', 'integer']]);
        match ($d['accion']) {
            'cerrar' => $conversacion->update(['estado' => 'cerrada']),
            'abrir' => $conversacion->update(['estado' => 'abierta']),
            'tomar' => $conversacion->update(['asignado_a' => $request->user()->id]),
            'asignar' => $this->todas($request) ? $conversacion->update(['asignado_a' => $d['user_id']]) : abort(403),
        };

        return back();
    }
}
