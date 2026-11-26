<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Finanzas\FlujoCaja;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Finanzas → Flujos de Caja: proyección a 30/60/90 días desde cuentas por cobrar y por pagar reales. */
class FlujoCajaController extends Controller
{
    public function index(Request $request, FlujoCaja $flujo)
    {
        $p = $flujo->proyectar();

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.flujos-de-caja.exportar'), 403);

            return Csv::descargar('flujo-de-caja', ['Fecha', 'Tipo', 'Detalle', 'Monto'], collect($p['proximos'])->map(fn ($m) => [$m['fecha'], $m['tipo'] === 'cobro' ? 'Cobro' : 'Pago', $m['detalle'], $m['monto']]));
        }

        return Inertia::render('Finanzas/FlujoCaja', $p);
    }
}
