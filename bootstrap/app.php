<?php

use App\Http\Middleware\AutenticarTokenApi;
use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\ControlSesion;
use App\Http\Middleware\Exigir2FA;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequiereScope;
use App\Http\Middleware\ResolverEmpresa;
use App\Http\Middleware\VerificarPermiso;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(CabecerasSeguridad::class);
        $middleware->web(append: [
            HandleInertiaRequests::class,
            ResolverEmpresa::class,
            ControlSesion::class,
            Exigir2FA::class,
        ]);
        // La empresa activa se fija antes de resolver los modelos de la ruta:
        // con RLS, un {documento} buscado sin empresa no existe.
        $middleware->prependToPriorityList(\Illuminate\Routing\Middleware\SubstituteBindings::class, ResolverEmpresa::class);
        $middleware->alias([
            'permiso' => VerificarPermiso::class,
            'token.api' => AutenticarTokenApi::class,
            'scope' => RequiereScope::class,
        ]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Páginas de error con el diseño propio, sin detalles de versión ni de pila.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if ($request->is('api/*') || ! in_array($status, [403, 404, 419, 429, 500, 503], true)) {
                return $response;
            }
            if (app()->hasDebugModeEnabled() && $status === 500) {
                return $response;
            }
            if ($status === 419) {
                return back()->with('error', 'La página expiró. Intenta de nuevo.');
            }

            return Inertia::render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
        });
    })->create();
