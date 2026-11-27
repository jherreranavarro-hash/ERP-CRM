<?php

namespace App\Modules\Integraciones\Mapas;

/**
 * Proveedor de mapas. Solo recibe dirección y comuna (nunca nombre, RUT ni
 * teléfono), y debe tener acuerdo de tratamiento de datos antes de activarse.
 * La optimización del orden se hace en el propio servidor con las coordenadas.
 */
interface Mapas
{
    /** @return array{0: float, 1: float}|null [lat, lng] */
    public function geocodificar(string $direccion, ?string $comuna): ?array;

    public function simulado(): bool;
}
