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

    public function solicitarPlantilla(string $nombre, string $idioma, string $categoria, string $cuerpo): void {}

    /**
     * Revisión simulada con las causas de rechazo más comunes de Meta: acortadores de
     * enlaces, contenido promocional en categoría utility y variables al inicio o final.
     */
    public function estadoPlantilla(string $nombre, string $idioma, string $categoria, string $cuerpo): array
    {
        $t = mb_strtolower($cuerpo);
        $motivo = match (true) {
            (bool) preg_match('/\b(bit\.ly|tinyurl|goo\.gl)\b/', $t) => 'No se permiten enlaces acortados.',
            $categoria === 'utility' && (bool) preg_match('/\b(oferta|descuento|promoci[oó]n|gratis|cyber)\b/u', $t) => 'Contenido promocional en una plantilla de categoría utility: usa marketing.',
            (bool) preg_match('/^\s*\{\{\d+\}\}|\{\{\d+\}\}\s*[.!?]?\s*$/', $cuerpo) => 'La plantilla no puede empezar ni terminar con una variable.',
            default => null,
        };

        return ['estado' => $motivo ? 'rechazada' : 'aprobada', 'motivo' => $motivo];
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
