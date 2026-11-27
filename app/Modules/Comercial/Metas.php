<?php

namespace App\Modules\Comercial;

use App\Modules\Comercial\Models\Meta;
use App\Modules\Dashboard\Tablero;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Avance de metas. Venta neta y documentos salen de los documentos emitidos del mes
 * (la NC resta y no cuenta como documento), igual que en los dashboards; cotizaciones,
 * de las creadas en el mes. Vendedor = quien creó el documento.
 */
class Metas
{
    /**
     * @param  Collection<int, Meta>  $metas  del mismo período
     * @return Collection<int, array{logrado:int, pct:float, proyeccion:?int}>
     */
    public function avance(Collection $metas, Carbon $periodo): Collection
    {
        $desde = $periodo->copy()->startOfMonth();
        $hasta = $periodo->copy()->endOfMonth();
        $rango = [$desde->toDateString(), $hasta->toDateString()];
        $signo = 'case when tipo_dte = 61 then -1 else 1 end';

        $docs = DB::table('documentos')->whereIn('estado', ['emitido', 'pagado'])->whereIn('tipo_dte', Tablero::VENTAS)->whereBetween('fecha_emision', $rango);
        $ventas = fn (?string $col) => (clone $docs)->when($col, fn ($q) => $q->groupBy($col)->selectRaw("{$col} as k"), fn ($q) => $q->selectRaw('0 as k'))
            ->selectRaw("sum({$signo} * (monto_neto + monto_exento)) as neto, sum(case when tipo_dte = 61 then 0 else 1 end) as n")->get()->keyBy('k');
        $cots = fn (?string $col) => DB::table('cotizaciones')->whereBetween('fecha', $rango)
            ->when($col, fn ($q) => $q->groupBy($col)->selectRaw("{$col} as k"), fn ($q) => $q->selectRaw('0 as k'))->selectRaw('count(*) as n')->get()->keyBy('k');

        $datos = [
            'empresa' => ['v' => $ventas(null), 'c' => $cots(null)],
            'local' => ['v' => $ventas('local_id'), 'c' => $cots('local_id')],
            'vendedor' => ['v' => $ventas('creado_por'), 'c' => $cots('creado_por')],
        ];

        $hoy = now()->startOfDay();
        $dias = $desde->daysInMonth;
        $transcurridos = $hoy->lt($desde) ? 0 : ($hoy->gt($hasta) ? $dias : $hoy->day);

        return $metas->mapWithKeys(function (Meta $m) use ($datos, $dias, $transcurridos) {
            $k = match ($m->alcance) { 'local' => $m->local_id, 'vendedor' => $m->user_id, default => 0 };
            $fila = $m->indicador === 'cotizaciones' ? ($datos[$m->alcance]['c'][$k] ?? null) : ($datos[$m->alcance]['v'][$k] ?? null);
            $logrado = (int) match ($m->indicador) { 'venta_neta' => $fila->neto ?? 0, default => $fila->n ?? 0 };
            $proyeccion = $transcurridos > 0 && $transcurridos < $dias ? (int) round($logrado / $transcurridos * $dias) : null;

            return [$m->id => ['logrado' => $logrado, 'pct' => round($logrado * 100 / max(1, $m->objetivo), 1), 'proyeccion' => $proyeccion]];
        });
    }
}
