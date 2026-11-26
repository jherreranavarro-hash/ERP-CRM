<?php

namespace App\Modules\Core\Rbac;

use App\Models\User;
use App\Modules\Core\Models\Permiso;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Tenancy\Tenancy;

/**
 * Verificación de permisos en el backend (Gate::before delega aquí).
 * Permiso = "modulo.submodulo.accion". El rol se toma de la membresía del
 * usuario en la empresa activa. SuperAdmin tiene todo salvo los permisos
 * restringidos (por ejemplo, escuchar grabaciones), que deben asignarse.
 */
class Rbac
{
    /** @var array<string, UsuarioEmpresa|null> */
    private array $membresias = [];

    /** @var array<int, array<string, true>> */
    private array $permisosPorRol = [];

    public function membresia(User $user, int $empresaId): ?UsuarioEmpresa
    {
        $k = $user->id.':'.$empresaId;

        return $this->membresias[$k] ??= UsuarioEmpresa::with('rol.perfil')
            ->where('user_id', $user->id)
            ->where('empresa_id', $empresaId)
            ->where('activo', true)
            ->first();
    }

    public function permite(User $user, string $permiso): bool
    {
        if (! $user->activo) {
            return false;
        }

        $restringido = in_array($permiso, config('erp.permisos_restringidos'), true);

        if ($user->es_superadmin && ! $restringido) {
            return true;
        }

        $empresaId = app(Tenancy::class)->id();
        if (! $empresaId) {
            return false;
        }

        $m = $this->membresia($user, $empresaId);
        if (! $m || ! $m->rol || ! $m->rol->activo) {
            return false;
        }

        return isset($this->permisosDelRol($m->rol_id)[$permiso]);
    }

    /** @return array<string, true> */
    public function permisosDelRol(int $rolId): array
    {
        return $this->permisosPorRol[$rolId] ??= Permiso::query()
            ->join('rol_permiso', 'rol_permiso.permiso_id', '=', 'permisos.id')
            ->where('rol_permiso.rol_id', $rolId)
            ->pluck('permisos.clave')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /** Lista de permisos efectivos (para el menú y el frontend). */
    public function permisosDe(User $user): array
    {
        if ($user->es_superadmin) {
            return Permiso::where('restringido', false)->pluck('clave')
                ->merge($this->permisosRestringidosAsignados($user))->unique()->values()->all();
        }
        $m = ($id = app(Tenancy::class)->id()) ? $this->membresia($user, $id) : null;

        return $m ? array_keys($this->permisosDelRol($m->rol_id)) : [];
    }

    private function permisosRestringidosAsignados(User $user): array
    {
        $m = ($id = app(Tenancy::class)->id()) ? $this->membresia($user, $id) : null;

        return $m ? array_keys(array_intersect_key($this->permisosDelRol($m->rol_id), array_flip(config('erp.permisos_restringidos')))) : [];
    }

    public function olvidar(): void
    {
        $this->membresias = [];
        $this->permisosPorRol = [];
    }

    /**
     * Genera la tabla de permisos desde config/erp.php (idempotente).
     *
     * @return int cantidad de permisos
     */
    public static function sincronizarPermisos(): int
    {
        $acciones = config('erp.acciones');
        $restringidos = config('erp.permisos_restringidos');
        $n = 0;
        foreach (self::submodulos() as $modulo => $subs) {
            foreach ($subs as $sub => $def) {
                foreach ($def['acciones'] as $accion) {
                    $clave = "{$modulo}.{$sub}.{$accion}";
                    Permiso::updateOrCreate(['clave' => $clave], [
                        'modulo' => $modulo,
                        'submodulo' => $sub,
                        'accion' => $accion,
                        'descripcion' => ($acciones[$accion] ?? $accion).' · '.$def['nombre'],
                        'restringido' => in_array($clave, $restringidos, true),
                    ]);
                    $n++;
                }
            }
        }

        return $n;
    }

    /** Submódulos del catálogo + extras, agrupados por módulo. */
    public static function submodulos(): array
    {
        $r = [];
        foreach (config('erp.modulos') as $clave => $m) {
            $r[$clave] = $m['submodulos'] + (config("erp.extras.{$clave}") ?? []);
        }

        return $r;
    }
}
