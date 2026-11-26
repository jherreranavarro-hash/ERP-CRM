<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Finanzas\Contabilidad;
use App\Modules\Finanzas\Models\CentroCosto;
use App\Modules\Finanzas\Models\CuentaContable;
use App\Support\Exportacion\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Finanzas → Balances y Reportes: siempre calculados desde los asientos, nunca digitados. */
class BalancesController extends Controller
{
    public const VISTAS = ['comprobacion' => 'Balance de comprobación', 'resultados' => 'Estado de resultados', 'general' => 'Balance general', 'mayor' => 'Libro mayor'];

    public function index(Request $request, Contabilidad $contabilidad)
    {
        $vista = array_key_exists($request->input('vista'), self::VISTAS) ? $request->input('vista') : 'comprobacion';
        $hasta = CarbonImmutable::parse($request->input('hasta', now()->toDateString()))->toDateString();
        $desde = CarbonImmutable::parse($request->input('desde', CarbonImmutable::parse($hasta)->startOfYear()->toDateString()))->toDateString();
        $centro = $request->integer('centro_costo_id') ?: null;

        // El balance general es a una fecha (saldos acumulados); el resto, del período.
        $saldos = $contabilidad->saldos($vista === 'general' ? null : $desde, $hasta, $vista === 'resultados' ? $centro : null);
        $con = $saldos->filter(fn ($s) => $s->debe || $s->haber)->values();
        $ingresos = (int) $saldos->where('tipo', 'ingreso')->sum('saldo');
        $gastos = (int) $saldos->where('tipo', 'gasto')->sum('saldo');

        $datos = ['vista' => $vista, 'vistas' => self::VISTAS, 'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'centro_costo_id' => $centro, 'cuenta_id' => $request->integer('cuenta_id') ?: null],
            'centros' => CentroCosto::orderBy('codigo')->get(['id', 'codigo', 'nombre']), 'cuentas' => CuentaContable::orderBy('codigo')->get(['id', 'codigo', 'nombre'])];

        $filas = match ($vista) {
            'comprobacion' => $con->map(fn ($s) => (array) $s + ['deudor' => max($s->debe - $s->haber, 0), 'acreedor' => max($s->haber - $s->debe, 0)])->all(),
            'resultados' => $con->whereIn('tipo', ['ingreso', 'gasto'])->map(fn ($s) => (array) $s)->values()->all(),
            'general' => $con->whereIn('tipo', ['activo', 'pasivo', 'patrimonio'])->map(fn ($s) => (array) $s)->values()->all(),
            'mayor' => $this->mayor($request->integer('cuenta_id'), $desde, $hasta),
        };
        $totales = match ($vista) {
            'comprobacion' => ['debe' => (int) $con->sum('debe'), 'haber' => (int) $con->sum('haber'), 'deudor' => (int) collect($filas)->sum('deudor'), 'acreedor' => (int) collect($filas)->sum('acreedor')],
            'resultados' => ['ingresos' => $ingresos, 'gastos' => $gastos, 'resultado' => $ingresos - $gastos],
            'general' => ['activo' => (int) $saldos->where('tipo', 'activo')->sum('saldo'), 'pasivo' => (int) $saldos->where('tipo', 'pasivo')->sum('saldo'),
                'patrimonio' => (int) $saldos->where('tipo', 'patrimonio')->sum('saldo'), 'resultado' => $ingresos - $gastos],
            'mayor' => null,
        };

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.balances-y-reportes.exportar'), 403);
            [$enc, $map] = match ($vista) {
                'comprobacion' => [['Código', 'Cuenta', 'Debe', 'Haber', 'Deudor', 'Acreedor'], fn ($f) => [$f['codigo'], $f['nombre'], $f['debe'], $f['haber'], $f['deudor'], $f['acreedor']]],
                'mayor' => [['Fecha', 'Asiento', 'Glosa', 'Debe', 'Haber', 'Saldo'], fn ($f) => [$f['fecha'], $f['numero'], $f['glosa'], $f['debe'], $f['haber'], $f['saldo']]],
                default => [['Código', 'Cuenta', 'Tipo', 'Saldo'], fn ($f) => [$f['codigo'], $f['nombre'], CuentaContable::TIPOS[$f['tipo']], $f['saldo']]],
            };

            return Csv::descargar("{$vista}-{$desde}-{$hasta}", $enc, collect($filas)->map($map));
        }

        return Inertia::render('Finanzas/Balances', $datos + ['filas' => $filas, 'totales' => $totales]);
    }

    /** Movimientos de una cuenta con saldo corrido (sentido natural de la cuenta). */
    private function mayor(?int $cuentaId, string $desde, string $hasta): array
    {
        $cuenta = $cuentaId ? CuentaContable::find($cuentaId) : null;
        if (! $cuenta) {
            return [];
        }
        $deudora = in_array($cuenta->tipo, CuentaContable::DEUDORAS, true);
        $previo = DB::table('asiento_lineas as l')->join('asientos as a', 'a.id', '=', 'l.asiento_id')->where('l.cuenta_id', $cuenta->id)->where('a.fecha', '<', $desde)
            ->selectRaw('coalesce(sum(l.debe), 0) as d, coalesce(sum(l.haber), 0) as h')->first();
        $saldo = (int) ($deudora ? $previo->d - $previo->h : $previo->h - $previo->d);
        $filas = [['fecha' => $desde, 'numero' => null, 'asiento_id' => null, 'glosa' => 'Saldo anterior', 'debe' => 0, 'haber' => 0, 'saldo' => $saldo]];
        $lineas = DB::table('asiento_lineas as l')->join('asientos as a', 'a.id', '=', 'l.asiento_id')->where('l.cuenta_id', $cuenta->id)->whereBetween('a.fecha', [$desde, $hasta])
            ->orderBy('a.fecha')->orderBy('a.numero')->limit(2000)->get(['a.id', 'a.fecha', 'a.numero', 'a.glosa', 'l.debe', 'l.haber', 'l.glosa as detalle']);
        foreach ($lineas as $l) {
            $saldo += $deudora ? $l->debe - $l->haber : $l->haber - $l->debe;
            $filas[] = ['fecha' => $l->fecha, 'numero' => $l->numero, 'asiento_id' => $l->id, 'glosa' => $l->detalle ?: $l->glosa, 'debe' => (int) $l->debe, 'haber' => (int) $l->haber, 'saldo' => $saldo];
        }

        return $filas;
    }
}
