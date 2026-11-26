<?php

namespace App\Modules\RRHH\Integraciones;

/**
 * Proveedor de firma electrónica avanzada para contratos y finiquitos.
 * El proveedor real se elige en la fase de integraciones; mientras tanto se usa el simulado.
 */
interface FirmaElectronica
{
    /** Envía el documento a firmar y devuelve el identificador de la solicitud en el proveedor. */
    public function solicitar(string $titulo, string $texto, array $firmantes): string;

    /** Estado de la solicitud: pendiente, firmado o rechazado. */
    public function estado(string $id): string;
}
