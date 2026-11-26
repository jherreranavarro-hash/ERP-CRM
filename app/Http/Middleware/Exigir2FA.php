<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Parametros\Parametros;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA obligatorio para los perfiles del mantenedor "Política de 2FA"
 * (SuperAdmin, Finanzas, RRHH y Caja como piso). Sin 2FA confirmado, el usuario
 * solo puede ir a su pantalla de seguridad para enrolarse.
 */
class Exigir2FA
{
    public function __construct(private Parametros $parametros) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user || $user->tiene2fa() || $this->rutaPermitida($request)) {
            return $next($request);
        }

        if (in_array($user->perfilActual(), $this->parametros->valor('2fa.perfiles_obligatorios'), true)) {
            return redirect()->route('perfil.seguridad')->with('aviso', 'Tu perfil exige verificación en dos pasos. Actívala para continuar.');
        }

        return $next($request);
    }

    private function rutaPermitida(Request $request): bool
    {
        return $request->routeIs('perfil.*', 'logout', 'password.confirm', 'password.confirmation', 'two-factor.*', 'user-password.update', 'empresa.cambiar')
            || $request->is('user/two-factor-*', 'user/confirm*', 'user/confirmed-*');
    }
}
