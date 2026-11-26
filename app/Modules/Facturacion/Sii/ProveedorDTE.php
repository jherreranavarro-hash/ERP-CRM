<?php

namespace App\Modules\Facturacion\Sii;

use App\Modules\Facturacion\Models\Documento;

/**
 * Proveedor de facturación electrónica certificado: firma, CAF, timbre (PDF417),
 * envío y consulta al SII. La app nunca firma ni habla con el SII directamente.
 * Implementación real pendiente de elegir proveedor (ver supuestos del prompt).
 */
interface ProveedorDTE
{
    /** @throws ProveedorNoDisponible si el proveedor no responde (se reintenta por cola). */
    public function enviar(Documento $documento): ResultadoEnvio;

    /** @throws ProveedorNoDisponible */
    public function consultar(Documento $documento): ResultadoEnvio;

    /** PDF timbrado; devuelve el contenido binario. */
    public function pdf(Documento $documento): string;
}
