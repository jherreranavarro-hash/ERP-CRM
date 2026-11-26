<?php

namespace App\Modules\Inventario\Escuchas;

use App\Modules\Facturacion\Eventos\DocumentoEmitido;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Inventario\Inventario;
use App\Modules\Inventario\Models\MovimientoStock;
use App\Modules\Ventas\Models\OrdenVenta;

/**
 * Al emitir una venta (factura o boleta) descuenta el stock del local; al
 * emitir una nota de crédito que anula, devuelve exactamente lo que salió.
 * Corre dentro de la transacción de emisión: si no hay stock, no se emite.
 */
class MoverStockAlEmitir
{
    public const VENTAS = [33, 34, 39, 41];

    public function __construct(private Inventario $inventario) {}

    public function handle(DocumentoEmitido $e): void
    {
        $doc = $e->documento;

        if (in_array($doc->tipo_dte, self::VENTAS, true) && $doc->local_id) {
            $pedido = Inventario::porProducto($doc->lineas);
            if ($pedido) {
                // Lo reservado por la propia orden de venta sí se puede usar.
                $this->inventario->exigirDisponible($pedido, $doc->local_id, $doc->orden_venta_id);
                foreach ($pedido as $pid => $cant) {
                    $this->inventario->mover($pid, $doc->local_id, 'venta', (string) $cant->negated(), $e->usuario, origen: ['documento', $doc->id], referencia: $doc->nombre());
                }
            }
            if ($doc->orden_venta_id) {
                OrdenVenta::whereKey($doc->orden_venta_id)->update(['estado' => 'facturada', 'documento_id' => $doc->id]);
            }

            return;
        }

        // NC que anula (código 1): reingresa lo que salió por la venta original, al mismo costo.
        $ref = $doc->referencias->first();
        if ($doc->tipo_dte === 61 && $ref?->codigo === 1 && $ref->documento_referido_id) {
            $salidas = MovimientoStock::where('origen_tipo', 'documento')->where('origen_id', $ref->documento_referido_id)->where('tipo', 'venta')->get();
            foreach ($salidas as $m) {
                $this->inventario->mover($m->producto_id, $m->local_id, 'anulacion_venta', ltrim((string) $m->cantidad, '-'), $e->usuario, (string) $m->costo_unitario, ['documento', $doc->id], $doc->nombre());
            }
        }
    }
}
