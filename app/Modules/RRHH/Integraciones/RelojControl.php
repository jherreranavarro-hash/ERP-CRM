<?php

namespace App\Modules\RRHH\Integraciones;

/**
 * Reloj control (biométrico o app de marcación). Cada marca trae el RUT de la
 * persona, el momento y si es entrada o salida.
 */
interface RelojControl
{
    /** @return list<array{rut:string, momento:string, tipo:string}> */
    public function leer(string $contenido): array;
}
