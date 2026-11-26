<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Auth\Sesiones;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mantenedor "Sesiones": cierre por inactividad (más corto en Caja y Finanzas),
 * duración máxima, sesiones simultáneas y caducidad de contraseña para cuentas
 * administrativas.
 */
class ControlSesion
{
    private const PERFILES_CRITICOS = ['caja', 'finanzas'];

    private const PERFILES_ADMIN = ['superadmin', 'finanzas', 'rrhh', 'caja', 'gerencia'];

    public function __construct(private Parametros $parametros, private Sesiones $sesiones) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $s = $request->session();
        $perfil = $user->perfilActual();
        $local = $user->membresiaActual()?->local_id;

        $inactividad = in_array($perfil, self::PERFILES_CRITICOS, true)
            ? $this->parametros->valor('sesiones.inactividad_perfiles_criticos', $local)
            : $this->parametros->valor('sesiones.inactividad_minutos', $local);
        $maxHoras = $this->parametros->valor('sesiones.duracion_maxima_horas');

        $ultima = (int) $s->get('sesion.actividad', now()->timestamp);
        $inicio = (int) $s->get('sesion.inicio', now()->timestamp);

        $motivo = match (true) {
            ! $user->activo => 'Tu usuario fue desactivado.',
            now()->timestamp - $ultima > $inactividad * 60 => 'Tu sesión se cerró por inactividad.',
            now()->timestamp - $inicio > $maxHoras * 3600 => 'Tu sesión alcanzó la duración máxima. Vuelve a ingresar.',
            ! $this->sesiones->vigente($user, $s->get('sesion.token')) => 'Tu sesión se cerró porque ingresaste desde otro dispositivo.',
            default => null,
        };

        if ($motivo) {
            app(Auditoria::class)->registrar('sesion.cerrada', null, null, ['motivo' => $motivo], userId: $user->id, global: true);
            Auth::guard('web')->logout();
            $s->invalidate();
            $s->regenerateToken();

            return redirect()->route('login')->with('status', $motivo);
        }

        $s->put('sesion.actividad', now()->timestamp);

        // Caducidad de contraseña en cuentas administrativas.
        if (in_array($perfil, self::PERFILES_ADMIN, true) && ! $request->routeIs('perfil.*', 'user-password.update', 'logout', 'password.confirm*')) {
            $dias = (int) $this->parametros->valor('contrasenas.caducidad_admin_dias');
            $cambio = $user->password_changed_at ?? $user->created_at;
            if ($cambio && $cambio->lt(now()->subDays($dias))) {
                return redirect()->route('perfil.seguridad')->with('aviso', "Tu contraseña tiene más de {$dias} días. Debes cambiarla para continuar.");
            }
        }

        return $next($request);
    }
}
