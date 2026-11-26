<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: ->middleware('permiso:configuracion.usuarios.ver') */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $user = $request->user();
        foreach ($permisos as $p) {
            if (! $user || ! $user->tienePermiso($p)) {
                abort(403, 'No tienes permiso para esta acción.');
            }
        }

        return $next($request);
    }
}
