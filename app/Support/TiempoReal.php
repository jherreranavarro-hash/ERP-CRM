<?php

namespace App\Support;

/** Datos de conexión de Reverb para el navegador (clave pública y dirección pública). */
final class TiempoReal
{
    /** @return array{key: string, host: string, port: int, tls: bool}|null */
    public static function conexion(): ?array
    {
        $r = config('broadcasting.connections.reverb');
        if (config('broadcasting.default') !== 'reverb' || ! $r || ! ($r['publico']['host'] ?? null)) {
            return null;
        }

        return [
            'key' => (string) $r['key'],
            'host' => (string) $r['publico']['host'],
            'port' => (int) $r['publico']['port'],
            'tls' => $r['publico']['scheme'] === 'https',
        ];
    }

    /** Origen WebSocket para la CSP (connect-src). */
    public static function origenCsp(): ?string
    {
        $c = self::conexion();

        return $c ? ($c['tls'] ? 'wss://' : 'ws://').$c['host'].':'.$c['port'] : null;
    }
}
