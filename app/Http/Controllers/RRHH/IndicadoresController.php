<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\RRHH\Models\Ausencia;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Liquidacion;
use App\Modules\RRHH\Models\Nomina;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** RRHH → Indicadores y Reportes: dotación, rotación, ausentismo, horas extra y costo de personal por centro. */
class IndicadoresController extends Controller
{
    public function index(Request $request)
    {
        $anio = (int) $request->input('anio', now()->year);
        $inicio = CarbonImmutable::create($anio, 1, 1);
        $fin = $inicio->endOfYear()->min(CarbonImmutable::today());
        $activos = Empleado::where('estado', 'activo')->with('local:id,nombre')->get();
        $altas = Empleado::whereBetween('fecha_ingreso', [$inicio->toDateString(), $fin->toDateString()])->count();
        $bajas = Empleado::whereBetween('fecha_egreso', [$inicio->toDateString(), $fin->toDateString()])->count();
        $dotacionMedia = max(1, ($activos->count() + ($activos->count() - $altas + $bajas)) / 2);

        // Ausentismo: días hábiles de licencias y permisos sin goce sobre los días hábiles disponibles.
        $habiles = \App\Modules\RRHH\Ausencias::habiles($inicio, $fin);
        $ausentes = (int) Ausencia::where('estado', 'aprobada')->whereIn('tipo', Ausencia::NO_PAGADAS)->whereBetween('desde', [$inicio->toDateString(), $fin->toDateString()])->sum('dias_habiles');

        $nominas = Nomina::whereIn('estado', ['aprobada', 'pagada'])->where('periodo', 'like', $anio.'-%')->orderBy('periodo')->get();
        $costoPorCentro = DB::table('liquidaciones as l')->join('nominas as n', 'n.id', '=', 'l.nomina_id')->join('empleados as e', 'e.id', '=', 'l.empleado_id')
            ->leftJoin('centros_costo as c', 'c.id', '=', 'e.centro_costo_id')->whereIn('n.estado', ['aprobada', 'pagada'])->where('n.periodo', 'like', $anio.'-%')
            ->where('l.empresa_id', app(\App\Modules\Core\Tenancy\Tenancy::class)->id())
            ->groupBy('c.codigo', 'c.nombre')->selectRaw("coalesce(c.codigo || ' · ' || c.nombre, 'Sin centro') as centro, sum(l.costo_empleador) as costo, count(distinct l.empleado_id) as personas")
            ->orderByDesc('costo')->get();

        return Inertia::render('RRHH/Indicadores', [
            'anio' => $anio,
            'kpis' => [
                'dotacion' => $activos->count(), 'altas' => $altas, 'bajas' => $bajas,
                'rotacion' => round($bajas / $dotacionMedia * 100, 1),
                'ausentismo' => $activos->count() && $habiles ? round($ausentes / ($activos->count() * $habiles) * 100, 2) : 0,
                'antiguedad' => round($activos->avg(fn ($e) => $e->fecha_ingreso->diffInMonths(now()) / 12) ?? 0, 1),
                'costo_anual' => (int) $nominas->sum(fn ($n) => $n->total_haberes + $n->total_empleador),
                'horas_extra' => (float) Liquidacion::whereIn('nomina_id', $nominas->pluck('id'))->sum('horas_extra'),
            ],
            'porLocal' => $activos->groupBy(fn ($e) => $e->local?->nombre ?? 'Sin local')->map(fn ($g, $k) => ['local' => $k, 'personas' => $g->count()])->values(),
            'porMes' => $nominas->map(fn ($n) => ['periodo' => $n->periodo, 'liquido' => $n->total_liquido, 'costo' => $n->total_haberes + $n->total_empleador]),
            'costoPorCentro' => $costoPorCentro->map(fn ($r) => ['centro' => $r->centro, 'costo' => (int) $r->costo, 'personas' => (int) $r->personas]),
        ]);
    }
}
