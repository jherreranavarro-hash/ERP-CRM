<?php

namespace App\Modules\Finanzas;

use App\Models\User;
use App\Modules\Compras\Models\FacturaProveedor;
use App\Modules\Compras\Models\PagoProveedor;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\Pago;
use App\Modules\Finanzas\Models\Honorario;
use App\Modules\Inventario\Models\MovimientoStock;
use Brick\Math\BigDecimal;

/**
 * Traduce los hechos operativos a asientos de partida doble. Cada origen
 * genera un solo asiento automático (idempotente) dentro de la misma
 * transacción que el hecho: si el asiento falla, el hecho tampoco ocurre.
 */
class AsientosAutomaticos
{
    /** Documentos que contabilizan como venta (la guía de despacho no). */
    public const VENTAS = [33, 34, 39, 41, 56];

    public function __construct(private Contabilidad $contabilidad) {}

    /** Venta o nota de débito: clientes contra ventas e IVA débito, y costo de lo vendido. NC: al revés. */
    public function documento(Documento $doc, User $user): void
    {
        $signo = match (true) {
            in_array($doc->tipo_dte, self::VENTAS, true) => 1,
            $doc->tipo_dte === 61 => -1,
            default => 0,
        };
        if ($signo === 0 || $doc->monto_total <= 0) {
            return;
        }
        $base = $doc->monto_neto + $doc->monto_exento;
        $lineas = [
            ['clientes', $signo > 0 ? $doc->monto_total : 0, $signo < 0 ? $doc->monto_total : 0],
            ['ventas', $signo < 0 ? $base : 0, $signo > 0 ? $base : 0],
            ['iva_debito', $signo < 0 ? $doc->monto_iva : 0, $signo > 0 ? $doc->monto_iva : 0],
        ];

        // Costo de lo vendido o reingresado, según el kardex de este documento (inventario permanente).
        $valor = MovimientoStock::where('origen_tipo', 'documento')->where('origen_id', $doc->id)->whereNotNull('costo_unitario')->get()
            ->reduce(fn (BigDecimal $s, $m) => $s->plus(BigDecimal::of((string) $m->cantidad)->multipliedBy((string) $m->costo_unitario)), BigDecimal::zero());
        $costo = \App\Modules\Facturacion\Calculo\Calculadora::red($valor);   // negativo si salió mercadería
        if ($costo !== 0) {
            $lineas[] = ['costo_ventas', max(-$costo, 0), max($costo, 0)];
            $lineas[] = ['inventario', max($costo, 0), max(-$costo, 0)];
        }

        $this->contabilidad->automatico('documento', $doc->id, ($doc->fecha_emision ?? now())->toDateString(), $doc->nombre().($doc->receptor_razon_social ? ' · '.$doc->receptor_razon_social : ''), $lineas, $user);
    }

    /** Cobro de un documento: caja o banco contra clientes. Pagar con una NC no mueve dinero (la NC ya se contabilizó). */
    public function pagoCliente(Pago $pago, User $user): void
    {
        if ($pago->metodo === 'nota_credito') {
            return;
        }
        $destino = $pago->metodo === 'efectivo' ? 'caja' : 'banco';
        $doc = $pago->documento;
        $this->contabilidad->automatico('pago', $pago->id, $pago->fecha->toDateString(), 'Cobro '.($doc?->nombre() ?? ''),
            [[$destino, $pago->monto, 0], ['clientes', 0, $pago->monto]], $user);
    }

    /** Factura de proveedor aceptada: mercadería (si viene de una OC) o gasto, IVA crédito, contra proveedores. */
    public function facturaProveedor(FacturaProveedor $f, User $user): void
    {
        $f->loadMissing('proveedor');
        $this->contabilidad->automatico('factura_proveedor', $f->id, $f->fecha_emision->toDateString(), $f->nombre().' · '.$f->proveedor?->razon_social, [
            [$f->orden_compra_id ? 'inventario' : 'gastos_generales', $f->monto_neto + $f->monto_exento, 0],
            ['iva_credito', $f->monto_iva, 0],
            ['proveedores', 0, $f->monto_total],
        ], $user);
    }

    public function pagoProveedor(PagoProveedor $p, User $user): void
    {
        $p->loadMissing('factura.proveedor');
        $this->contabilidad->automatico('pago_proveedor', $p->id, $p->fecha->toDateString(), 'Pago '.$p->factura?->nombre().' · '.$p->factura?->proveedor?->razon_social, [
            ['proveedores', $p->monto, 0],
            [$p->metodo === 'efectivo' ? 'caja' : 'banco', 0, $p->monto],
        ], $user);
    }

    public function honorario(Honorario $h, User $user): void
    {
        $this->contabilidad->automatico('honorario', $h->id, $h->fecha->toDateString(), "Boleta de honorarios N° {$h->folio} · {$h->nombre}", [
            ['honorarios', $h->bruto, 0],
            ['retencion_honorarios', 0, $h->retencion],
            ['honorarios_por_pagar', 0, $h->liquido],
        ], $user, $h->centro_costo_id);
    }

    public function pagoHonorario(Honorario $h, User $user): void
    {
        $this->contabilidad->automatico('honorario_pago', $h->id, $h->fecha_pago->toDateString(), "Pago boleta de honorarios N° {$h->folio} · {$h->nombre}", [
            ['honorarios_por_pagar', $h->liquido, 0],
            ['banco', 0, $h->liquido],
        ], $user);
    }
}
