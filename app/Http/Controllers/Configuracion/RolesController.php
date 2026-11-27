<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Perfil;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Rbac\Rbac;
use App\Modules\Operacion\Models\SolicitudPrivilegio;
use App\Modules\Operacion\Privilegios;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Configuración → Perfiles: roles y su matriz de permisos (módulo × submódulo × acción).
 * RBAC es mantenedor sensible: quitar permisos rige al tiro; agregarlos queda como
 * solicitud que aprueba otra persona (App\Modules\Operacion\Privilegios).
 */
class RolesController extends Controller
{
    public function __construct(private Privilegios $privilegios) {}

    public function index()
    {
        return Inertia::render('Configuracion/Roles/Index', [
            'roles' => Rol::with('perfil')->withCount('permisos')->orderBy('nombre')->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'nombre' => $r->nombre, 'descripcion' => $r->descripcion,
                    'perfil' => $r->perfil->nombre, 'activo' => $r->activo, 'permisos' => $r->permisos_count,
                    'usuarios' => DB::table('usuario_empresa')->where('rol_id', $r->id)->where('activo', true)->count(),
                ]),
            'solicitudes' => SolicitudPrivilegio::with('rol:id,nombre', 'usuario:id,name,email', 'solicitante:id,name')
                ->where('estado', 'pendiente')->orderBy('id')->get()
                ->map(fn (SolicitudPrivilegio $s) => [
                    'id' => $s->id, 'tipo' => $s->tipo, 'rol' => $s->rol?->nombre, 'usuario' => $s->usuario?->name,
                    'permisos' => $s->permisos ?? [], 'motivo' => $s->motivo, 'solicitante' => $s->solicitante?->name,
                    'propia' => (int) $s->solicitado_por === auth()->id(), 'fecha' => $s->created_at->toIso8601String(),
                ]),
            'puedeAprobar' => app(Rbac::class)->permite(auth()->user(), 'configuracion.perfiles.aprobar'),
        ]);
    }

    public function create()
    {
        return $this->form(null);
    }

    public function edit(Rol $rol)
    {
        return $this->form($rol);
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);
        $rol = Rol::create(Arr::only($data, ['nombre', 'descripcion', 'perfil_id', 'activo']));
        $pendientes = $this->privilegios->cambiarPermisosRol($rol, $data['permisos'] ?? [], $request->user(), $data['motivo'] ?? null);

        return redirect()->route('configuracion.roles.index')->with('exito', "Rol {$rol->nombre} creado.".$this->avisoPendientes($pendientes));
    }

    public function update(Request $request, Rol $rol)
    {
        $data = $this->validar($request, $rol);
        $rol->update(Arr::only($data, ['nombre', 'descripcion', 'perfil_id', 'activo']));
        $pendientes = $this->privilegios->cambiarPermisosRol($rol, $data['permisos'] ?? [], $request->user(), $data['motivo'] ?? null);

        return redirect()->route('configuracion.roles.index')->with('exito', 'Rol actualizado.'.$this->avisoPendientes($pendientes));
    }

    public function aprobar(Request $request, SolicitudPrivilegio $solicitud)
    {
        $this->privilegios->aprobar($solicitud, $request->user(), $request->validate(['comentario' => ['nullable', 'string', 'max:500']])['comentario'] ?? null);

        return back()->with('exito', 'Solicitud aprobada: los permisos ya rigen.');
    }

    public function rechazar(Request $request, SolicitudPrivilegio $solicitud)
    {
        $d = $request->validate(['comentario' => ['required', 'string', 'min:5', 'max:500']]);
        $this->privilegios->rechazar($solicitud, $request->user(), $d['comentario']);

        return back()->with('exito', 'Solicitud rechazada.');
    }

    public function anular(Request $request, SolicitudPrivilegio $solicitud)
    {
        $this->privilegios->anular($solicitud, $request->user());

        return back()->with('exito', 'Solicitud anulada.');
    }

    private function avisoPendientes(array $pendientes): string
    {
        return $pendientes ? ' '.count($pendientes).' permiso(s) nuevo(s) quedan pendientes de aprobación por otra persona.' : '';
    }

    public function destroy(Rol $rol)
    {
        abort_if(DB::table('usuario_empresa')->where('rol_id', $rol->id)->where('activo', true)->exists(), 422, 'El rol tiene usuarios asignados.');
        $rol->delete();

        return back()->with('exito', 'Rol eliminado.');
    }

    private function form(?Rol $rol)
    {
        $acciones = config('erp.acciones');
        $matriz = [];
        foreach (Rbac::submodulos() as $mod => $subs) {
            $filas = [];
            foreach ($subs as $sub => $def) {
                $filas[] = ['clave' => $sub, 'nombre' => $def['nombre'], 'acciones' => $def['acciones']];
            }
            $matriz[] = ['clave' => $mod, 'nombre' => config("erp.modulos.{$mod}.nombre"), 'submodulos' => $filas];
        }

        return Inertia::render('Configuracion/Roles/Form', [
            'rol' => $rol ? $rol->only(['id', 'nombre', 'descripcion', 'perfil_id', 'activo']) + [
                'permisos' => $rol->permisos()->pluck('clave')
                    ->merge(SolicitudPrivilegio::where('tipo', 'rol_permisos')->where('rol_id', $rol->id)->where('estado', 'pendiente')->value('permisos') ?? [])
                    ->unique()->values(),
                'pendientes' => SolicitudPrivilegio::where('tipo', 'rol_permisos')->where('rol_id', $rol->id)->where('estado', 'pendiente')->value('permisos') ?? [],
            ] : null,
            'perfiles' => Perfil::orderBy('nombre')->get(['id', 'nombre', 'clave']),
            'matriz' => $matriz,
            'acciones' => $acciones,
            'restringidos' => config('erp.permisos_restringidos'),
        ]);
    }

    private function validar(Request $request, ?Rol $rol = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:80', Rule::unique('roles')->ignore($rol?->id)],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'perfil_id' => ['required', 'exists:perfiles,id'],
            'activo' => ['boolean'],
            'permisos' => ['array'],
            'permisos.*' => ['string', 'exists:permisos,clave'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
