<?php

namespace App\Http\Controllers;

use App\Modules\Core\Models\Local;
use App\Modules\Core\Models\ParametroCambio;
use App\Modules\Core\Models\Trazabilidad;
use App\Modules\Core\Models\UsuarioEmpresa;
use Inertia\Inertia;
use Inertia\Response;

class InicioController extends Controller
{
    public function __invoke(): Response
    {
        $user = request()->user();

        return Inertia::render('Inicio', [
            'resumen' => [
                'locales' => Local::where('activo', true)->count(),
                'usuarios' => UsuarioEmpresa::where('empresa_id', app(\App\Modules\Core\Tenancy\Tenancy::class)->id())->where('activo', true)->count(),
                'por_aprobar' => $user->tienePermiso('configuracion.parametros.aprobar')
                    ? ParametroCambio::where('estado', 'propuesto')->where('propuesto_por', '!=', $user->id)->count() : null,
            ],
            'actividad' => $user->tienePermiso('configuracion.tracking-de-actividades.ver')
                ? Trazabilidad::with('user:id,name')->latest('id')->limit(8)->get()
                    ->map(fn ($t) => ['id' => $t->id, 'accion' => $t->accion, 'usuario' => $t->user?->name, 'fecha' => $t->created_at->toIso8601String()])
                : [],
        ]);
    }
}
