<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Uso: ->middleware('scope:informe-ventas:read'). Un token solo hace lo que su scope permite. */
class RequiereScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $token = $request->user()?->currentAccessToken();
        foreach ($scopes as $s) {
            if (! $token || ! in_array($s, $token->abilities ?? [], true)) {
                return response()->json(['message' => "El token no tiene el scope {$s}."], 403);
            }
        }

        return $next($request);
    }
}
