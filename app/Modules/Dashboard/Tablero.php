<?php

namespace App\Modules\Dashboard;

use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Finanzas\Contabilidad;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Agregados de los dashboards. Cada consulta pesada se cachea por empresa y
 * período; la caché se invalida subiendo una versión cuando ocurre un hecho
 * que la altera (documento emitido, cotización o asiento guardado), así que
 * nunca se recalcula todo en cada carga ni se muestra un dato viejo.
 */
class Tablero
{
    /** Documentos que suman a la venta; la nota de crédito (61) resta. */
    public const VENTAS = [33, 34, 39, 41, 56, 61];

    public const EMITIDAS = ['enviada', 'aceptada', 'rechazada', 'convertida', 'vencida'];

    public function __construct(private Tenancy $tenancy, private Parametros $parametros, private Contabilidad $contabilidad) {}

    public function invalidar(?int $empresaId = null): void
    {
        $id = $empresaId ?? $this->tenancy->id();
        if ($id) {
            Cache::increment("dash:v:{$id}");
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function periodo(?string $desde, ?string $hasta): array
    {
        $ok = fn ($f) => is_string($f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $f);
        $d = $ok($desde) ? CarbonImmutable::parse($desde) : CarbonImmutable::today()->startOfMonth();
        $h = $ok($hasta) ? CarbonImmutable::parse($hasta) : CarbonImmutable::today();
        if ($h->lt($d)) {
            [$d, $h] = [$h, $d];
        }
        // Un período de más de un año no se pide en línea: se recorta.
        return [$d->max($h->subYear()->addDay()), $h];
    }

    public function crm(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        return $this->cachear('crm', $desde, $hasta, function () use ($desde, $hasta) {
            $cats = $this->margenPorCategoria($desde, $hasta);
            $ventas = (int) $cats->sum('venta');
            $costo = (int) $cats->sum('costo');
            $dias = $desde->diffInDays($hasta) + 1;
            $antD = $desde->subDays($dias);
            $antH = $desde->subDay();
            $ventasAnt = (int) $this->margenPorCategoria($antD, $antH)->sum('venta');

            $saldos = $this->contabilidad->saldos($desde->toDateString(), $hasta->toDateString());
            $resultado = (int) $saldos->where('tipo', 'ingreso')->sum('saldo') - (int) $saldos->where('tipo', 'gasto')->sum('saldo');

            $cot = $this->cotizaciones($desde, $hasta)->first();
            $activos = (int) DB::table('clientes')->where('empresa_id', $this->empresa())->where('activo', true)->whereNull('deleted_at')->count();
            $compradores = (int) $this->ventasBase($desde, $hasta)->whereNotNull('d.cliente_id')->distinct()->count('d.cliente_id');

            return [
                'kpis' => [
                    'ventas' => $ventas, 'ventas_anterior' => $ventasAnt,
                    'resultado' => $resultado,
                    'margen' => $ventas - $costo, 'margen_pct' => $ventas ? round(($ventas - $costo) / $ventas * 100, 1) : 0.0,
                    'cotizaciones' => (int) ($cot->emitidas ?? 0), 'convertidas' => (int) ($cot->convertidas ?? 0),
                    'conversion' => ($cot->emitidas ?? 0) ? round($cot->convertidas / $cot->emitidas * 100, 1) : 0.0,
                    'clientes_activos' => $activos, 'compradores' => $compradores,
                    'cobertura' => $activos ? round($compradores / $activos * 100, 1) : 0.0,
                ],
                'categorias' => $cats->sortByDesc('venta')->values()->all(),
                'serie' => $this->serieDiaria($desde, $hasta),
                'actividad' => $this->actividad($desde, $hasta),
            ];
        });
    }

    public function comercial(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        return $this->cachear('comercial', $desde, $hasta, function () use ($desde, $hasta) {
            $e = $this->empresa();
            $cot = $this->cotizaciones($desde, $hasta)->addSelect('c.creado_por')->groupBy('c.creado_por')->get()->keyBy('creado_por');
            $ventas = $this->ventasBase($desde, $hasta)->groupBy('d.creado_por')
                ->selectRaw('d.creado_por, sum(case when d.tipo_dte = 61 then -1 else 1 end * (d.monto_neto + d.monto_exento)) as neto, sum(case when d.tipo_dte = 61 then 0 else 1 end) as documentos')
                ->get()->keyBy('creado_por');
            $abiertas = DB::table('cotizaciones')->where('empresa_id', $e)->whereIn('estado', ['enviada', 'aceptada'])->groupBy('creado_por')
                ->selectRaw('creado_por, count(*) as n, sum(monto_neto + monto_exento) as monto')->get()->keyBy('creado_por');
            $comisiones = DB::table('comisiones as k')->join('empleados as em', 'em.id', '=', 'k.empleado_id')->join('documentos as d', 'd.id', '=', 'k.documento_id')
                ->where('k.empresa_id', $e)->whereBetween('d.fecha_emision', [$desde->toDateString(), $hasta->toDateString()])->whereNotNull('em.user_id')
                ->groupBy('em.user_id')->selectRaw('em.user_id, sum(k.monto) as monto')->pluck('monto', 'user_id');

            $ids = $cot->keys()->merge($ventas->keys())->merge($abiertas->keys())->filter()->unique();
            $nombres = DB::table('users')->whereIn('id', $ids)->pluck('name', 'id');
            $filas = $ids->map(function ($id) use ($cot, $ventas, $abiertas, $comisiones, $nombres) {
                $c = $cot->get($id);
                $v = $ventas->get($id);
                $neto = (int) ($v->neto ?? 0);
                $docs = (int) ($v->documentos ?? 0);

                return [
                    'id' => (int) $id, 'nombre' => $nombres[$id] ?? 'Usuario '.$id,
                    'cotizaciones' => (int) ($c->emitidas ?? 0), 'convertidas' => (int) ($c->convertidas ?? 0),
                    'conversion' => ($c->emitidas ?? 0) ? round($c->convertidas / $c->emitidas * 100, 1) : null,
                    'monto_cotizado' => (int) ($c->monto ?? 0),
                    'ventas' => $neto, 'documentos' => $docs, 'ticket' => $docs ? intdiv($neto, $docs) : 0,
                    'pipeline' => (int) ($abiertas->get($id)->monto ?? 0), 'pipeline_n' => (int) ($abiertas->get($id)->n ?? 0),
                    'comisiones' => (int) ($comisiones[$id] ?? 0),
                ];
            })->sortByDesc('ventas')->values();

            $clientes = $this->ventasBase($desde, $hasta)->whereNotNull('d.cliente_id')->join('clientes as cl', 'cl.id', '=', 'd.cliente_id')
                ->groupBy('cl.id', 'cl.razon_social')->selectRaw('cl.id, cl.razon_social, sum(case when d.tipo_dte = 61 then -1 else 1 end * (d.monto_neto + d.monto_exento)) as neto, count(*) as documentos')
                ->orderByDesc('neto')->limit(10)->get()->map(fn ($r) => ['id' => $r->id, 'nombre' => $r->razon_social, 'ventas' => (int) $r->neto, 'documentos' => (int) $r->documentos]);

            return [
                'ejecutivos' => $filas->all(),
                'totales' => [
                    'ventas' => $filas->sum('ventas'), 'cotizaciones' => $filas->sum('cotizaciones'), 'convertidas' => $filas->sum('convertidas'),
                    'pipeline' => $filas->sum('pipeline'), 'comisiones' => $filas->sum('comisiones'),
                ],
                'clientes' => $clientes->all(),
            ];
        });
    }

    private function cachear(string $tipo, CarbonImmutable $desde, CarbonImmutable $hasta, \Closure $fn): array
    {
        $e = $this->empresa();
        $v = (int) Cache::get("dash:v:{$e}", 0);
        $min = max(1, (int) ($this->parametros->valor('rendimiento.cache_dashboard_min') ?? 5));
        $datos = Cache::remember("dash:{$tipo}:{$e}:{$v}:{$desde->toDateString()}:{$hasta->toDateString()}", now()->addMinutes($min), fn () => $fn() + ['calculado' => now()->toIso8601String()]);

        return $datos;
    }

    private function empresa(): int
    {
        return (int) $this->tenancy->id();
    }

    private function ventasBase(CarbonImmutable $desde, CarbonImmutable $hasta)
    {
        return DB::table('documentos as d')->where('d.empresa_id', $this->empresa())->whereIn('d.estado', ['emitido', 'pagado'])
            ->whereIn('d.tipo_dte', self::VENTAS)->whereBetween('d.fecha_emision', [$desde->toDateString(), $hasta->toDateString()]);
    }

    private function cotizaciones(CarbonImmutable $desde, CarbonImmutable $hasta)
    {
        return DB::table('cotizaciones as c')->where('c.empresa_id', $this->empresa())->whereIn('c.estado', self::EMITIDAS)
            ->whereBetween('c.fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw("count(*) as emitidas, count(*) filter (where c.estado = 'convertida' or c.documento_id is not null) as convertidas, sum(c.monto_neto + c.monto_exento) as monto");
    }

    /** Venta neta prorrateada por línea y costo de lo que salió de bodega, por categoría. */
    private function margenPorCategoria(CarbonImmutable $desde, CarbonImmutable $hasta)
    {
        $e = $this->empresa();
        $rango = [$desde->toDateString(), $hasta->toDateString()];
        $venta = DB::table('documento_lineas as l')->join('documentos as d', 'd.id', '=', 'l.documento_id')
            ->joinSub(DB::table('documento_lineas')->where('empresa_id', $e)->groupBy('documento_id')->selectRaw('documento_id, sum(monto_item) as items'), 't', 't.documento_id', '=', 'd.id')
            ->leftJoin('productos as p', 'p.id', '=', 'l.producto_id')->leftJoin('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->where('l.empresa_id', $e)->whereIn('d.estado', ['emitido', 'pagado'])->whereIn('d.tipo_dte', self::VENTAS)->whereBetween('d.fecha_emision', $rango)
            ->groupBy('c.nombre')
            ->selectRaw("coalesce(c.nombre, 'Sin categoría') as categoria, sum(round(l.monto_item::numeric / nullif(t.items, 0) * (d.monto_neto + d.monto_exento)) * case when d.tipo_dte = 61 then -1 else 1 end) as venta")
            ->pluck('venta', 'categoria');
        $costo = DB::table('movimientos_stock as m')->join('documentos as d', function ($j) {
            $j->on('d.id', '=', 'm.origen_id')->where('m.origen_tipo', '=', 'documento');
        })->join('productos as p', 'p.id', '=', 'm.producto_id')->leftJoin('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->where('m.empresa_id', $e)->whereIn('m.tipo', ['venta', 'devolucion'])->whereBetween('d.fecha_emision', $rango)
            ->groupBy('c.nombre')->selectRaw("coalesce(c.nombre, 'Sin categoría') as categoria, sum(-m.cantidad * coalesce(m.costo_unitario, 0)) as costo")
            ->pluck('costo', 'categoria');

        return $venta->keys()->merge($costo->keys())->unique()->map(function ($cat) use ($venta, $costo) {
            $v = (int) round((float) ($venta[$cat] ?? 0));
            $c = (int) round((float) ($costo[$cat] ?? 0));

            return ['categoria' => $cat, 'venta' => $v, 'costo' => $c, 'margen' => $v - $c, 'margen_pct' => $v ? round(($v - $c) / $v * 100, 1) : null];
        })->values();
    }

    private function serieDiaria(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $por = $this->ventasBase($desde, $hasta)->groupBy('d.fecha_emision')
            ->selectRaw('d.fecha_emision::text as dia, sum(case when d.tipo_dte = 61 then -1 else 1 end * (d.monto_neto + d.monto_exento)) as neto')->pluck('neto', 'dia');
        $serie = [];
        for ($d = $desde; $d->lte($hasta); $d = $d->addDay()) {
            $serie[] = ['dia' => $d->toDateString(), 'ventas' => (int) ($por[$d->toDateString()] ?? 0)];
        }

        return $serie;
    }

    /** Documentos emitidos y cotizaciones creadas por ejecutivo y día (los 12 más activos). */
    private function actividad(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $e = $this->empresa();
        $rango = [$desde->startOfDay(), $hasta->endOfDay()];
        $docs = DB::table('documentos')->where('empresa_id', $e)->whereNotNull('emitido_por')->whereBetween('emitido_at', $rango)
            ->selectRaw('emitido_por as usuario, emitido_at::date::text as dia, count(*) as n')->groupByRaw('1, 2');
        $cots = DB::table('cotizaciones')->where('empresa_id', $e)->whereBetween('created_at', $rango)
            ->selectRaw('creado_por as usuario, created_at::date::text as dia, count(*) as n')->groupByRaw('1, 2');
        $filas = DB::query()->fromSub($docs->unionAll($cots), 'x')->groupBy('usuario', 'dia')->selectRaw('usuario, dia, sum(n) as n')->get();
        $porUsuario = $filas->groupBy('usuario')->map(fn ($g) => ['total' => (int) $g->sum('n'), 'dias' => $g->mapWithKeys(fn ($r) => [$r->dia => (int) $r->n])->all()])
            ->sortByDesc('total')->take(12);
        $nombres = DB::table('users')->whereIn('id', $porUsuario->keys())->pluck('name', 'id');

        return [
            'maximo' => (int) ($filas->max('n') ?? 0),
            'ejecutivos' => $porUsuario->map(fn ($u, $id) => ['id' => (int) $id, 'nombre' => $nombres[$id] ?? 'Usuario '.$id] + $u)->values()->all(),
        ];
    }
}
