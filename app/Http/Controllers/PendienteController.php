<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

/** Submódulos del catálogo que se entregan en una fase posterior. */
class PendienteController extends Controller
{
    public function __invoke(string $modulo, string $submodulo)
    {
        $def = config("erp.modulos.{$modulo}.submodulos.{$submodulo}") ?? abort(404);
        abort_unless(request()->user()->tienePermiso("{$modulo}.{$submodulo}.ver"), 403);

        return Inertia::render('Pendiente', [
            'modulo' => config("erp.modulos.{$modulo}.nombre"),
            'submodulo' => $def['nombre'],
            'fase' => $def['fase'],
        ]);
    }
}
