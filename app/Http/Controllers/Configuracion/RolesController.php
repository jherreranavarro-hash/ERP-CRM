<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Perfil;
use App\Modules\Core\Models\Permiso;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Configuración → Perfiles: roles y su matriz de permisos (módulo × submódulo × acción). */
class RolesController extends Controller
{
    public function index()
    {
        return Inertia::render('Configuracion/Roles/Index', [
            'roles' => Rol::with('perfil')->withCount('permisos')->orderBy('nombre')->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'nombre' => $r->nombre, 'descripcion' => $r->descripcion,
                    'perfil' => $r->perfil->nombre, 'activo' => $r->activo, 'permisos' => $r->permisos_count,
                    'usuarios' => DB::table('usuario_empresa')->where('rol_id', $r->id)->where('activo', true)->count(),
                ]),
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
        $rol = DB::transaction(function () use ($data) {
            $rol = Rol::create($data);
            $this->sincronizar($rol, $data['permisos']);

            return $rol;
        });

        return redirect()->route('configuracion.roles.index')->with('exito', "Rol {$rol->nombre} creado.");
    }

    public function update(Request $request, Rol $rol)
    {
        $data = $this->validar($request, $rol);
        DB::transaction(function () use ($rol, $data) {
            $rol->update($data);
            $this->sincronizar($rol, $data['permisos']);
        });

        return redirect()->route('configuracion.roles.index')->with('exito', 'Rol actualizado.');
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
            'rol' => $rol ? $rol->only(['id', 'nombre', 'descripcion', 'perfil_id', 'activo']) + ['permisos' => $rol->permisos()->pluck('clave')] : null,
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
        ]);
    }

    private function sincronizar(Rol $rol, array $claves): void
    {
        $antes = $rol->permisos()->pluck('clave')->sort()->values()->all();
        $rol->permisos()->sync(Permiso::whereIn('clave', $claves)->pluck('id'));
        $despues = collect($claves)->unique()->sort()->values()->all();
        if ($antes !== $despues) {
            app(Auditoria::class)->registrar('rol.permisos', $rol, ['permisos' => $antes], ['permisos' => $despues], global: true);
        }
    }
}
