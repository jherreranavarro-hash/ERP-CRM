<?php

namespace App\Modules\Finanzas;

use App\Modules\Compras\Models\FacturaProveedor;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Finanzas\Models\Honorario;
use Carbon\CarbonImmutable;

/**
 * Proyección de caja a 30/60/90 días desde lo real: el disponible contable
 * (caja y banco), lo que falta cobrar a clientes y lo que falta pagar a
 * proveedores y prestadores, cada uno en su fecha de vencimiento.
 */
class FlujoCaja
{
    public const HORIZONTES = [30, 60, 90];

    public function __construct(private Contabilidad $contabilidad) {}

    /** @return array<string, mixed> */
    public function proyectar(?CarbonImmutable $hoy = null): array
    {
        $hoy ??= CarbonImmutable::today();
        $saldos = $this->contabilidad->saldos(null, $hoy->toDateString())->keyBy('codigo');
        $claves = \App\Modules\Finanzas\Models\CuentaContable::whereIn('clave', ['caja', 'banco'])->pluck('codigo');
        $disponible = (int) $claves->sum(fn ($c) => $saldos[$c]->saldo ?? 0);

        $movs = collect();
        foreach (Documento::where('estado', 'emitido')->where('saldo', '>', 0)->whereNotIn('tipo_dte', [52, 61])->get(['id', 'tipo_dte', 'folio', 'receptor_razon_social', 'fecha_emision', 'fecha_vencimiento', 'saldo']) as $d) {
            $movs->push(['fecha' => ($d->fecha_vencimiento ?? $d->fecha_emision)->toDateString(), 'monto' => $d->saldo, 'tipo' => 'cobro', 'detalle' => $d->nombre().' · '.($d->receptor_razon_social ?? 'Consumidor final'), 'url' => "/caja/documentos/{$d->id}"]);
        }
        foreach (FacturaProveedor::with('proveedor:id,razon_social')->where('estado', 'por_pagar')->where('saldo', '>', 0)->get() as $f) {
            $movs->push(['fecha' => $f->fecha_vencimiento->toDateString(), 'monto' => -$f->saldo, 'tipo' => 'pago', 'detalle' => $f->nombre().' · '.$f->proveedor?->razon_social, 'url' => "/compras/facturas/{$f->id}"]);
        }
        foreach (Honorario::where('estado', 'por_pagar')->get() as $h) {
            $movs->push(['fecha' => $h->fecha->toDateString(), 'monto' => -$h->liquido, 'tipo' => 'pago', 'detalle' => "Honorarios N° {$h->folio} · {$h->nombre}", 'url' => '/finanzas/honorarios']);
        }

        $h = $hoy->toDateString();
        $vencidoCobrar = (int) $movs->where('fecha', '<', $h)->where('monto', '>', 0)->sum('monto');
        $vencidoPagar = (int) -$movs->where('fecha', '<', $h)->where('monto', '<', 0)->sum('monto');

        // Lo vencido se proyecta en los primeros días: se cobra y se paga apenas se pueda.
        $tramos = [];
        $acumulado = $disponible;
        $desde = null;
        foreach (self::HORIZONTES as $dias) {
            $hasta = $hoy->addDays($dias)->toDateString();
            $en = $movs->filter(fn ($m) => ($desde === null || $m['fecha'] > $desde) && $m['fecha'] <= $hasta);
            $entradas = (int) $en->where('monto', '>', 0)->sum('monto');
            $salidas = (int) -$en->where('monto', '<', 0)->sum('monto');
            $acumulado += $entradas - $salidas;
            $tramos[] = ['dias' => $dias, 'hasta' => $hasta, 'entradas' => $entradas, 'salidas' => $salidas, 'neto' => $entradas - $salidas, 'saldo' => $acumulado];
            $desde = $hasta;
        }

        // Serie semanal (13 semanas) para el gráfico.
        $semanas = [];
        $saldo = $disponible;
        $inicio = $hoy;
        for ($i = 0; $i < 13; $i++) {
            $fin = $inicio->addDays(6);
            $en = $movs->filter(fn ($m) => ($i === 0 ? true : $m['fecha'] >= $inicio->toDateString()) && $m['fecha'] <= $fin->toDateString());
            $e = (int) $en->where('monto', '>', 0)->sum('monto');
            $s = (int) -$en->where('monto', '<', 0)->sum('monto');
            $saldo += $e - $s;
            $semanas[] = ['desde' => $inicio->toDateString(), 'hasta' => $fin->toDateString(), 'entradas' => $e, 'salidas' => $s, 'saldo' => $saldo];
            $inicio = $fin->addDay();
        }

        return [
            'hoy' => $h, 'disponible' => $disponible, 'vencido_cobrar' => $vencidoCobrar, 'vencido_pagar' => $vencidoPagar,
            'tramos' => $tramos, 'semanas' => $semanas,
            'proximos' => $movs->filter(fn ($m) => $m['fecha'] <= $hoy->addDays(90)->toDateString())->sortBy('fecha')->values()->take(100)->all(),
        ];
    }
}
