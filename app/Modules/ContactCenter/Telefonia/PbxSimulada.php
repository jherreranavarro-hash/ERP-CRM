<?php

namespace App\Modules\ContactCenter\Telefonia;

use Illuminate\Support\Str;

/**
 * Central simulada para desarrollo y demostración: no marca a nadie. Los
 * eventos (contesta, no contesta, ocupado) se disparan desde la consola del
 * agente y siguen el mismo camino que los de una central real.
 */
class PbxSimulada implements Pbx
{
    public function originar(string $extension, string $numero, string $referencia): string
    {
        return 'sim-'.Str::uuid();
    }

    public function colgar(string $pbxId): void {}

    public function grabacion(string $ref): ?string
    {
        // Un segundo de tono de 440 Hz, 8 kHz mono 16 bits: suficiente para probar el reproductor.
        $muestras = '';
        for ($i = 0; $i < 8000; $i++) {
            $muestras .= pack('v', (int) (3000 * sin(2 * M_PI * 440 * $i / 8000)) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($muestras)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($muestras)).$muestras;
    }

    public function probar(string $host, int $puerto, string $usuario, ?string $secreto): bool
    {
        return $host !== '' && $usuario !== '' && $secreto !== null && $secreto !== '';
    }

    public function simulada(): bool
    {
        return true;
    }
}
