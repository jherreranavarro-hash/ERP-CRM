<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Rbac\Rbac;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Las props que dependen de la empresa activa son closures: se evalúan al
     * renderizar, cuando ResolverEmpresa ya fijó la empresa.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'app' => ['nombre' => config('app.name')],
            'auth' => fn () => $this->auth($request->user()),
            'empresa' => fn () => $this->empresa($request->user()),
            'menu' => fn () => $request->user() ? $this->menu($request->user()) : [],
            // Aviso flotante de WhatsApp: solo el conteo de no leídos visibles para el usuario.
            'whatsapp' => fn () => $this->whatsapp($request),
            'flash' => fn () => [
                'exito' => $request->session()->get('exito'),
                'error' => $request->session()->get('error'),
                'aviso' => $request->session()->get('aviso'),
                'status' => $request->session()->get('status'),
            ],
        ];
    }

    private function auth(?User $user): array
    {
        if (! $user) {
            return ['user' => null, 'permisos' => []];
        }

        return [
            'user' => [
                'id' => $user->id,
                'nombre' => $user->name,
                'email' => $user->email,
                'iniciales' => $user->iniciales(),
                'es_superadmin' => $user->es_superadmin,
                'tiene_2fa' => $user->tiene2fa(),
                'perfil' => $user->perfilActual(),
            ],
            'permisos' => app(Rbac::class)->permisosDe($user),
        ];
    }

    private function empresa(?User $user): ?array
    {
        $e = app(Tenancy::class)->empresa();
        if (! $user || ! $e) {
            return null;
        }
        $disponibles = $user->es_superadmin
            ? \App\Modules\Core\Models\Empresa::where('activo', true)->orderBy('razon_social')->get()
            : $user->empresas()->orderBy('razon_social')->get();

        return [
            'actual' => ['id' => $e->id, 'nombre' => $e->nombreCorto(), 'rut' => $e->rut],
            'disponibles' => $disponibles->map(fn ($d) => ['id' => $d->id, 'nombre' => $d->nombreCorto()])->values(),
        ];
    }

    /** Menú lateral: módulos activos de la empresa y submódulos con permiso "ver". */
    private function menu(User $user): array
    {
        if (! app(Tenancy::class)->activa()) {
            return [];
        }
        $activos = app(Parametros::class)->valor('modulos.activos');
        $menu = [];

        foreach (Rbac::submodulos() as $clave => $subs) {
            if (! in_array($clave, $activos, true)) {
                continue;
            }
            $items = [];
            foreach ($subs as $sub => $def) {
                if (! $user->tienePermiso("{$clave}.{$sub}.ver")) {
                    continue;
                }
                $items[] = [
                    'clave' => $sub,
                    'nombre' => $def['nombre'],
                    'grupo' => $def['grupo'] ?? null,
                    'fase' => $def['fase'],
                    'disponible' => ! empty($def['ruta']),
                    'href' => ! empty($def['ruta']) ? route($def['ruta'], absolute: false) : route('pendiente', [$clave, $sub], absolute: false),
                ];
            }
            if ($items) {
                $menu[] = [
                    'clave' => $clave,
                    'nombre' => config("erp.modulos.{$clave}.nombre"),
                    'icono' => config("erp.modulos.{$clave}.icono"),
                    'items' => $items,
                ];
            }
        }

        return $menu;
    }

    private function whatsapp(Request $request): ?int
    {
        $u = $request->user();
        if (! $u || ! app(\App\Modules\Core\Tenancy\Tenancy::class)->id() || ! $u->tienePermiso('ventascrm.whatsapp.ver')) {
            return null;
        }

        return (int) \App\Modules\Mensajeria\Models\Conversacion::where('no_leidos', '>', 0)
            ->when(! $u->tienePermiso('ventascrm.whatsapp.editar'), fn ($q) => $q->where(fn ($w) => $w->whereNull('asignado_a')->orWhere('asignado_a', $u->id)))
            ->count();
    }
}
