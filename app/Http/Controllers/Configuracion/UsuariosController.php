<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Sesiones;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Invitacion;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Operacion\Models\SolicitudPrivilegio;
use App\Modules\Operacion\Privilegios;
use App\Notifications\InvitacionUsuario;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Configuración → Usuarios. Alta solo por invitación; se administran los
 * usuarios de la empresa activa (su membresía, rol y local).
 */
class UsuariosController extends Controller
{
    public function __construct(private Tenancy $tenancy) {}

    public function index(Request $request)
    {
        $q = UsuarioEmpresa::query()
            ->with(['user' => fn ($q) => $q->withTrashed(), 'rol.perfil', 'local'])
            ->where('empresa_id', $this->tenancy->id())
            ->join('users', 'users.id', '=', 'usuario_empresa.user_id')
            ->select('usuario_empresa.*')
            ->orderBy('users.name');

        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => $q->where('users.name', 'ilike', "%{$b}%")->orWhere('users.email', 'ilike', "%{$b}%"));
        }
        if ($request->filled('rol_id')) {
            $q->where('usuario_empresa.rol_id', $request->integer('rol_id'));
        }
        match ($request->input('estado')) {
            'inactivos' => $q->where(fn ($q) => $q->where('usuario_empresa.activo', false)->orWhere('users.activo', false)),
            'todos' => null,
            default => $q->where('usuario_empresa.activo', true)->where('users.activo', true),
        };

        $pendientesSa = SolicitudPrivilegio::where('tipo', 'superadmin')->where('estado', 'pendiente')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $fila = fn (UsuarioEmpresa $m) => [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'nombre' => $m->user->name,
            'email' => $m->user->email,
            'iniciales' => $m->user->iniciales(),
            'rol_id' => $m->rol_id,
            'rol' => $m->rol?->nombre,
            'perfil' => $m->rol?->perfil?->nombre,
            'local_id' => $m->local_id,
            'local' => $m->local?->nombre,
            'activo' => $m->activo && $m->user->activo,
            'tiene_2fa' => $m->user->tiene2fa(),
            'es_superadmin' => $m->user->es_superadmin,
            'superadmin_pendiente' => in_array($m->user_id, $pendientesSa, true),
            'ultimo_acceso' => $m->user->ultimo_acceso_at?->toIso8601String(),
        ];

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('configuracion.usuarios.exportar'), 403);

            return Csv::descargar('usuarios', ['Nombre', 'Correo', 'Rol', 'Perfil', 'Local', 'Activo', '2FA', 'Último acceso'],
                $q->get()->map($fila)->map(fn ($f) => [$f['nombre'], $f['email'], $f['rol'], $f['perfil'], $f['local'], $f['activo'], $f['tiene_2fa'], $f['ultimo_acceso']]));
        }

        return Inertia::render('Configuracion/Usuarios/Index', [
            'usuarios' => $q->paginate(25)->withQueryString()->through($fila),
            'invitaciones' => Invitacion::with('rol')->where('empresa_id', $this->tenancy->id())
                ->whereNull('aceptada_at')->whereNull('revocada_at')->latest()->get()
                ->map(fn ($i) => ['id' => $i->id, 'email' => $i->email, 'nombre' => $i->nombre, 'rol' => $i->rol->nombre, 'expira' => $i->expira_at->toIso8601String(), 'vencida' => $i->expira_at->isPast()]),
            'roles' => Rol::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'locales' => Local::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'filtros' => $request->only('buscar', 'estado', 'rol_id'),
            'soySuperadmin' => (bool) $request->user()->es_superadmin,
        ]);
    }

    public function invitar(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:150'],
            'nombre' => ['required', 'string', 'max:120'],
            'rol_id' => ['required', Rule::exists('roles', 'id')->where('activo', true)],
            'local_id' => ['nullable', Rule::exists('locales', 'id')],
        ]);
        $data['email'] = Str::lower($data['email']);

        $dominios = app(Parametros::class)->valor('usuarios.dominios_permitidos');
        if ($dominios && ! in_array(Str::after($data['email'], '@'), $dominios, true)) {
            throw ValidationException::withMessages(['email' => 'El dominio no está permitido. Dominios aceptados: '.implode(', ', $dominios).'.']);
        }
        $this->exigirRolAsignable($request->user(), (int) $data['rol_id']);

        $existente = User::where('email', $data['email'])->first();
        if ($existente && UsuarioEmpresa::where('user_id', $existente->id)->where('empresa_id', $this->tenancy->id())->where('activo', true)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ese usuario ya pertenece a esta empresa.']);
        }

        $token = Str::random(64);
        $inv = Invitacion::create($data + [
            'token_hash' => hash('sha256', $token),
            'empresa_id' => $this->tenancy->id(),
            'invitado_por' => $request->user()->id,
            'expira_at' => now()->addHours((int) app(Parametros::class)->valor('usuarios.invitacion_horas')),
        ]);
        app(Auditoria::class)->registrar('invitacion.enviada', $inv, null, ['email' => $inv->email, 'rol_id' => $inv->rol_id]);

        Notification::route('mail', $inv->email)->notify(new InvitacionUsuario($inv, $token));

        return back()->with('exito', "Invitación enviada a {$inv->email}.");
    }

    public function revocarInvitacion(Invitacion $invitacion)
    {
        abort_unless($invitacion->empresa_id === $this->tenancy->id(), 404);
        $invitacion->update(['revocada_at' => now()]);
        app(Auditoria::class)->registrar('invitacion.revocada', $invitacion);

        return back()->with('exito', 'Invitación revocada.');
    }

    public function update(Request $request, UsuarioEmpresa $membresia)
    {
        abort_unless($membresia->empresa_id === $this->tenancy->id(), 404);
        $data = $request->validate([
            'rol_id' => ['required', Rule::exists('roles', 'id')->where('activo', true)],
            'local_id' => ['nullable', Rule::exists('locales', 'id')],
            'activo' => ['required', 'boolean'],
        ]);
        $this->exigirGestionable($request->user(), $membresia);
        $this->exigirRolAsignable($request->user(), (int) $data['rol_id']);

        if ($membresia->user_id === $request->user()->id && ! $data['activo']) {
            throw ValidationException::withMessages(['activo' => 'No puedes desactivarte a ti mismo.']);
        }
        // Separación de funciones: nadie cambia su propio rol, lo hace otra persona.
        if ($membresia->user_id === $request->user()->id && (int) $data['rol_id'] !== (int) $membresia->rol_id) {
            throw ValidationException::withMessages(['rol_id' => 'No puedes cambiar tu propio rol: pídeselo a otro administrador.']);
        }

        $membresia->update($data);
        if (! $data['activo']) {
            app(Sesiones::class)->cerrarTodas($membresia->user);
        }

        return back()->with('exito', 'Usuario actualizado.');
    }

    public function cerrarSesiones(Request $request, UsuarioEmpresa $membresia)
    {
        abort_unless($membresia->empresa_id === $this->tenancy->id(), 404);
        $this->exigirGestionable($request->user(), $membresia);
        app(Sesiones::class)->cerrarTodas($membresia->user);
        app(Auditoria::class)->registrar('sesion.cierre_forzado', $membresia->user);

        return back()->with('exito', 'Se cerraron todas las sesiones del usuario.');
    }

    public function solicitarSuperadmin(Request $request, UsuarioEmpresa $membresia)
    {
        abort_unless($membresia->empresa_id === $this->tenancy->id(), 404);
        $d = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);
        app(Privilegios::class)->solicitarSuperadmin($membresia->user, $request->user(), $d['motivo']);

        return back()->with('exito', 'Solicitud creada: otro SuperAdmin debe aprobarla en Configuración → Perfiles.');
    }

    public function revocarSuperadmin(Request $request, UsuarioEmpresa $membresia)
    {
        abort_unless($membresia->empresa_id === $this->tenancy->id(), 404);
        $d = $request->validate(['motivo' => ['required', 'string', 'min:10', 'max:500']]);
        app(Privilegios::class)->revocarSuperadmin($membresia->user, $request->user(), $d['motivo']);

        return back()->with('exito', 'Se revocó la marca SuperAdmin y se cerraron sus sesiones.');
    }

    /** Quien no es SuperAdmin no modifica a un SuperAdmin ni a alguien con más permisos que él. */
    private function exigirGestionable(User $actor, UsuarioEmpresa $m): void
    {
        if ($actor->es_superadmin || $m->user_id === $actor->id) {
            return;
        }
        abort_if($m->loadMissing('user')->user->es_superadmin, 403, 'Solo un SuperAdmin modifica a otro SuperAdmin.');
        $this->exigirRolAsignable($actor, (int) $m->rol_id);
    }

    /**
     * Solo un SuperAdmin asigna roles del perfil SuperAdmin. Y nadie que no sea
     * SuperAdmin entrega un rol con permisos que él mismo no tiene: si no, quien
     * administra usuarios podría crear (o invitar) una cuenta más poderosa que la suya.
     */
    private function exigirRolAsignable(User $actor, int $rolId): void
    {
        $rol = Rol::with('perfil')->find($rolId);
        if ($actor->es_superadmin) {
            return;
        }
        if ($rol?->perfil?->clave === 'superadmin') {
            throw ValidationException::withMessages(['rol_id' => 'Solo un SuperAdmin puede asignar ese rol.']);
        }
        $propios = app(\App\Modules\Core\Rbac\Rbac::class)->permisosDe($actor);
        $exceso = $rol ? $rol->permisos()->pluck('clave')->diff($propios) : collect();
        if ($exceso->isNotEmpty()) {
            throw ValidationException::withMessages(['rol_id' => 'Ese rol tiene permisos que tú no tienes ('.$exceso->take(3)->implode(', ').($exceso->count() > 3 ? '…' : '').'): debe asignarlo un SuperAdmin.']);
        }
    }
}
