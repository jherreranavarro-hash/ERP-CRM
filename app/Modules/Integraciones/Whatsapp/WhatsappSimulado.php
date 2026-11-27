<?php

namespace App\Modules\Integraciones\Whatsapp;

use Illuminate\Support\Str;

/** Envío simulado: no sale a internet. Un número que termina en 0000 simula un rechazo de Meta. */
class WhatsappSimulado implements Whatsapp
{
    public function enviarTexto(string $telefono, string $texto): string
    {
        return $this->id($telefono);
    }

    public function enviarPlantilla(string $telefono, string $plantilla, string $idioma, array $variables): string
    {
        return $this->id($telefono);
    }

    public function simulado(): bool
    {
        return true;
    }

    private function id(string $telefono): string
    {
        if (str_ends_with($telefono, '0000')) {
            throw new \RuntimeException('El destinatario no tiene WhatsApp (simulado).');
        }

        return 'wamid.sim.'.Str::lower(Str::random(24));
    }
}
