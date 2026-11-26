<?php

namespace App\Modules\Facturacion\Sii;

final class ResultadoEnvio
{
    /** @param 'pendiente'|'aceptado'|'rechazado' $estado */
    public function __construct(
        public readonly string $estado,
        public readonly ?string $trackId = null,
        public readonly array $detalle = [],
    ) {}
}
