<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Finanzas\Models\CentroCosto;
use App\Support\Exportacion\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Finanzas → Centro de Costos: mantenedor y resultado (ingresos y gastos) por centro en un período. */
class CentrosCostoController extends Controller
{
    public function index(Request $request)
    {
        $desde = CarbonImmutable::parse($request->input('desde', now()->startOfMonth()->toDateString()))->toDateString();
        $hasta = CarbonImmutable::parse($request->input('hasta', now()->toDateString()))->toDateString();

        // Ingresos y gastos por centro (las líneas sin centro quedan en "Sin asignar").
        $filas = DB::table('asiento_lineas as l')->join('asientos as a', 'a.id', '=', 'l.asiento_id')->join('cuentas_contables as c', 'c.id', '=', 'l.cuenta_id')
            ->where('l.empresa_id', app(Tenancy::class)->id())->whereBetween('a.fecha', [$desde, $hasta])->whereIn('c.tipo', ['ingreso', 'gasto'])
            ->groupBy('l.centro_costo_id', 'c.tipo')->selectRaw('l.centro_costo_id, c.tipo, sum(l.haber - l.debe) as neto')->get();
        $centros = CentroCosto::with('local:id,nombre')->orderBy('codigo')->get();
        $resultado = $centros->map(fn ($c) => ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre, 'local_id' => $c->local_id, 'local' => $c->local?->nombre, 'activo' => $c->activo])
            ->push(['id' => null, 'codigo' => '—', 'nombre' => 'Sin asignar', 'local_id' => null, 'local' => null, 'activo' => true])
            ->map(function ($c) use ($filas) {
                $ingresos = (int) $filas->where('centro_costo_id', $c['id'])->where('tipo', 'ingreso')->sum('neto');
                $gastos = (int) -$filas->where('centro_costo_id', $c['id'])->where('tipo', 'gasto')->sum('neto');

                return $c + ['ingresos' => $ingresos, 'gastos' => $gastos, 'resultado' => $ingresos - $gastos];
            });

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.centro-de-costos.exportar'), 403);

            return Csv::descargar("centros-de-costo-{$desde}-{$hasta}", ['Código', 'Centro', 'Ingresos', 'Gastos', 'Resultado'], $resultado->map(fn ($c) => [$c['codigo'], $c['nombre'], $c['ingresos'], $c['gastos'], $c['resultado']]));
        }

        return Inertia::render('Finanzas/CentrosCosto', [
            'centros' => $resultado->values(),
            'filtros' => ['desde' => $desde, 'hasta' => $hasta],
            'locales' => Local::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request)
    {
        CentroCosto::create($this->validar($request));

        return back()->with('exito', 'Centro de costo creado.');
    }

    public function update(Request $request, CentroCosto $centro)
    {
        $centro->update($this->validar($request, $centro));

        return back()->with('exito', 'Centro de costo actualizado.');
    }

    private function validar(Request $request, ?CentroCosto $c = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:20', Rule::unique('centros_costo')->where('empresa_id', app(Tenancy::class)->id())->ignore($c?->id)],
            'nombre' => ['required', 'string', 'max:100'],
            'local_id' => ['nullable', 'integer', 'exists:locales,id'],
            'activo' => ['boolean'],
        ]);
    }
}
