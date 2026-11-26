<?php

namespace App\Modules\RRHH\Integraciones;

use Illuminate\Support\Str;

/** Firma electrónica simulada: toda solicitud queda firmada al consultarla. */
class FirmaSimulada implements FirmaElectronica
{
    public function solicitar(string $titulo, string $texto, array $firmantes): string
    {
        return 'SIM-'.Str::upper(Str::random(12));
    }

    public function estado(string $id): string
    {
        return str_starts_with($id, 'SIM-') ? 'firmado' : 'pendiente';
    }
}
