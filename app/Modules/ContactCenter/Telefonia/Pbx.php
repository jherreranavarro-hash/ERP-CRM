<?php

namespace App\Modules\ContactCenter\Telefonia;

/**
 * Puerta hacia la central telefónica (Asterisk/FreeSWITCH vía AMI/ARI). Corre
 * como servicio aparte con su propio usuario de servicio: el modelo de usuario
 * nunca guarda credenciales de telefonía. Los eventos de la central (contestó,
 * colgó, grabación lista) entran por el webhook firmado /api/pbx/eventos.
 */
interface Pbx
{
    /** Origina una llamada desde la extensión del agente al número; devuelve el id de la central. */
    public function originar(string $extension, string $numero, string $referencia): string;

    public function colgar(string $pbxId): void;

    /** Audio de la grabación (WAV) o null si ya no existe. */
    public function grabacion(string $ref): ?string;

    /** Prueba de conexión con un proveedor configurado. */
    public function probar(string $host, int $puerto, string $usuario, ?string $secreto): bool;

    /** True cuando no hay central real y los eventos se simulan desde la consola. */
    public function simulada(): bool;
}
