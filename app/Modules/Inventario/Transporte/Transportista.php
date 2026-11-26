<?php

namespace App\Modules\Inventario\Transporte;

use App\Modules\Inventario\Models\Despacho;

/**
 * Integración con un transportista externo. La real (Starken, Chilexpress,
 * Blue Express…) se conecta en la fase de integraciones; aquí hay una
 * implementación simulada para operar y probar el flujo completo.
 */
interface Transportista
{
    public function codigo(): string;

    public function nombre(): string;

    /** Crea el envío y devuelve el número de seguimiento. */
    public function crearEnvio(Despacho $despacho): string;
}
