<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Core\Tenancy\Tenancy;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * VentasCRM → Cuentas corrientes por cliente: lo facturado, lo abonado
 * (pagos y notas de crédito) y el saldo, con la cartola de movimientos.
 */
class CuentasCorrientesController extends Controller
{
    public function index(Request $request, Tenancy $tenancy)
    {
        $e = $tenancy->id();
        $q = DB::table('clientes as c')->where('c.empresa_id', $e)->whereNull('c.deleted_at')
            ->join('documentos as d', fn ($j) => $j->on('d.cliente_id', '=', 'c.id')->whereIn('d.estado', ['emitido', 'pagado'])->whereIn('d.tipo_dte', [33, 34, 39, 41, 56, 61]))
            ->groupBy('c.id', 'c.rut', 'c.razon_social', 'c.cupo_credito', 'c.tiene_credito')
            ->selectRaw("c.id, c.rut, c.razon_social, c.cupo_credito, c.tiene_credito,
                sum(case when d.tipo_dte = 61 then 0 else d.monto_total end) as facturado,
                sum(case when d.tipo_dte = 61 then d.monto_total else 0 end) as notas_credito,
                sum(case when d.tipo_dte = 61 then 0 else d.saldo end) as saldo,
                sum(case when d.tipo_dte <> 61 and d.saldo > 0 and coalesce(d.fecha_vencimiento, d.fecha_emision) < current_date then d.saldo else 0 end) as vencido,
                max(d.fecha_emision)::text as ultima");
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($w) => $w->where('c.razon_social', 'ilike', "%{$b}%")->orWhere('c.rut', 'ilike', '%'.preg_replace('/[^0-9kK]/', '', $b).'%'));
        }
        if ($request->input('filtro') === 'con_saldo') {
            $q->havingRaw('sum(case when d.tipo_dte = 61 then 0 else d.saldo end) > 0');
        }
        $q->orderByRaw('saldo desc, facturado desc');
        $fila = fn ($r) => ['id' => $r->id, 'rut' => $r->rut, 'nombre' => $r->razon_social, 'facturado' => (int) $r->facturado, 'notas_credito' => (int) $r->notas_credito,
            'saldo' => (int) $r->saldo, 'vencido' => (int) $r->vencido, 'cupo' => $r->tiene_credito ? (int) $r->cupo_credito : null, 'ultima' => $r->ultima];

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('ventascrm.cuentas-corrientes.exportar') || $request->user()->tienePermiso('configuracion.cuentas-corrientes.exportar'), 403);

            return Csv::descargar('cuentas-corrientes', ['RUT', 'Cliente', 'Facturado', 'Notas de crédito', 'Saldo', 'Vencido', 'Cupo', 'Última compra'],
                $q->get()->map(fn ($r) => array_values($fila($r))));
        }

        return Inertia::render('Ventas/CuentasCorrientes/Index', [
            'clientes' => $q->paginate(30)->withQueryString()->through($fila),
            'filtros' => $request->only('buscar', 'filtro'),
        ]);
    }

    public function show(Cliente $cliente, Tenancy $tenancy)
    {
        $e = $tenancy->id();
        $docs = DB::table('documentos')->where('empresa_id', $e)->where('cliente_id', $cliente->id)->whereIn('estado', ['emitido', 'pagado'])->whereIn('tipo_dte', [33, 34, 39, 41, 56, 61])
            ->get(['id', 'tipo_dte', 'folio', 'fecha_emision', 'fecha_vencimiento', 'monto_total', 'saldo']);
        $pagos = DB::table('pagos as p')->join('documentos as d', 'd.id', '=', 'p.documento_id')->where('p.empresa_id', $e)->where('d.cliente_id', $cliente->id)
            ->where('p.metodo', '<>', 'nota_credito') // la nota de crédito ya figura como abono propio
            ->get(['p.id', 'p.fecha', 'p.monto', 'p.metodo', 'p.referencia', 'd.tipo_dte', 'd.folio', 'd.id as documento_id']);
        $nombre = fn ($t, $f) => (\App\Modules\Facturacion\Models\Documento::TIPOS[$t] ?? "DTE {$t}").' N° '.$f;

        $movs = $docs->map(fn ($d) => ['fecha' => (string) $d->fecha_emision, 'orden' => 0, 'sec' => $d->id, 'documento_id' => $d->id, 'detalle' => $nombre($d->tipo_dte, $d->folio),
            'cargo' => $d->tipo_dte === 61 ? 0 : (int) $d->monto_total, 'abono' => $d->tipo_dte === 61 ? (int) $d->monto_total : 0, 'vence' => $d->tipo_dte === 61 ? null : $d->fecha_vencimiento])
            ->merge($pagos->map(fn ($p) => ['fecha' => substr((string) $p->fecha, 0, 10), 'orden' => 1, 'sec' => $p->id, 'documento_id' => $p->documento_id,
                'detalle' => 'Pago '.mb_strtolower(\App\Modules\Facturacion\Models\Pago::METODOS[$p->metodo] ?? $p->metodo).' · '.$nombre($p->tipo_dte, $p->folio).($p->referencia ? ' · '.$p->referencia : ''), 'cargo' => 0, 'abono' => (int) $p->monto, 'vence' => null]))
            ->sortBy([['fecha', 'asc'], ['orden', 'asc'], ['sec', 'asc']])->values();
        $saldo = 0;
        $movs = $movs->map(function ($m) use (&$saldo) {
            $saldo += $m['cargo'] - $m['abono'];

            return $m + ['saldo' => $saldo];
        });
        $ventas = $docs->where('tipo_dte', '!=', 61);

        return Inertia::render('Ventas/CuentasCorrientes/Show', [
            'cliente' => ['id' => $cliente->id, 'rut' => $cliente->rut, 'nombre' => $cliente->razon_social, 'cupo' => $cliente->tiene_credito ? $cliente->cupo_credito : null, 'plazo' => $cliente->plazo_dias],
            'resumen' => ['facturado' => (int) $ventas->sum('monto_total'), 'abonado' => (int) $movs->sum('abono'), 'saldo' => (int) $ventas->sum('saldo'),
                'vencido' => (int) $ventas->filter(fn ($d) => $d->saldo > 0 && ($d->fecha_vencimiento ?? $d->fecha_emision) < now()->toDateString())->sum('saldo')],
            'movimientos' => $movs->reverse()->values()->take(300),
        ]);
    }
}
