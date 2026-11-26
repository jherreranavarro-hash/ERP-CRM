<?php

namespace App\Modules\Ventas;

use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\Facturacion\Models\Documento;

/**
 * Lo común al pasar una cotización u orden de venta a un documento: las líneas
 * conservan el precio pactado (en boleta, con IVA incluido) y la aprobación de
 * descuentos se hereda, salvo el cupo de crédito, que se vuelve a revisar.
 */
class HaciaDocumento
{
    public function __construct(private Calculadora $calc, private Auditoria $auditoria) {}

    /** @param iterable<object> $lineas líneas con producto_id, codigo, descripcion, cantidad, unidad, precio_unitario, descuento_pct, descuento_monto, exento */
    public function lineas(iterable $lineas, int $tipo, string $tasa): array
    {
        $r = [];
        foreach ($lineas as $l) {
            $r[] = [
                'producto_id' => $l->producto_id,
                'codigo' => $l->codigo,
                'descripcion' => $l->descripcion,
                'cantidad' => (string) $l->cantidad,
                'unidad' => $l->unidad,
                'precio_unitario' => $tipo === 39 && ! $l->exento ? (string) $this->calc->precioConIva((string) $l->precio_unitario, $tasa) : (string) $l->precio_unitario,
                'precio_manual' => true,
                'descuento_pct' => (string) $l->descuento_pct,
                'descuento_monto' => (float) $l->descuento_pct > 0 ? null : ($l->descuento_monto ?: null),
                'exento' => $l->exento,
            ];
        }

        return $r;
    }

    public function heredarAprobacion(Documento $doc, ?int $aprobadoPor, string $origen): void
    {
        $cupo = array_filter($doc->aprobacion_motivos ?? [], fn ($m) => str_starts_with($m, 'Cupo'));
        if ($doc->estado === 'por_aprobar' && $aprobadoPor && ! $cupo) {
            $doc->forceFill(['estado' => 'borrador', 'aprobado_por' => $aprobadoPor])->save();
            $this->auditoria->registrar('documento.aprobacion_heredada', $doc, null, ['origen' => $origen, 'motivos' => $doc->aprobacion_motivos]);
        }
    }
}
