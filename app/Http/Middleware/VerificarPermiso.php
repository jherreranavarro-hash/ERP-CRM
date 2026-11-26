<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso: ->middleware('permiso:configuracion.usuarios.ver'). Varios parámetros se
 * exigen todos; "a|b" en un mismo parámetro basta con uno de ellos.
 */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $user = $request->user();
        foreach ($permisos as $p) {
            if (! $user || ! collect(explode('|', $p))->contains(fn ($uno) => $user->tienePermiso($uno))) {
                abort(403, 'No tienes permiso para esta acción.');
            }
        }

        return $next($request);
    }
}
