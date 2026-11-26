<?php

namespace App\Modules\Inventario\Transporte;

use App\Modules\Inventario\Models\Despacho;

/** Transportista simulado: entrega un número de seguimiento sin llamar a nadie. */
class TransportistaSimulado implements Transportista
{
    public function codigo(): string
    {
        return 'simulado';
    }

    public function nombre(): string
    {
        return 'Transportes Demo (simulado)';
    }

    public function crearEnvio(Despacho $despacho): string
    {
        return 'SIM-'.str_pad((string) $despacho->numero, 6, '0', STR_PAD_LEFT).'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }
}
