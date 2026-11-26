<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Tablero;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Dashboard → Dashboard CRM y VentasCRM → Dashboard comercial por ejecutivo. */
class DashboardController extends Controller
{
    public function __construct(private Tablero $tablero) {}

    public function crm(Request $request)
    {
        [$desde, $hasta] = Tablero::periodo($request->input('desde'), $request->input('hasta'));

        return Inertia::render('Dashboard/Crm', ['filtros' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()]] + $this->tablero->crm($desde, $hasta));
    }

    public function comercial(Request $request)
    {
        [$desde, $hasta] = Tablero::periodo($request->input('desde'), $request->input('hasta'));

        return Inertia::render('Dashboard/Comercial', ['filtros' => ['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString()]] + $this->tablero->comercial($desde, $hasta));
    }
}
