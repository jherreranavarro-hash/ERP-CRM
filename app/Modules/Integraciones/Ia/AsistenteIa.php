<?php

namespace App\Modules\Integraciones\Ia;

/**
 * Proveedor externo de IA. Recibe SOLO texto ya seudonimizado (ver
 * Seudonimizador): nunca RUT, teléfonos, correos ni nombres reales.
 */
interface AsistenteIa
{
    /** @param  list<array{rol: 'cliente'|'ejecutivo', texto: string}>  $conversacion */
    public function sugerirRespuesta(array $conversacion): string;

    /** @param  list<array{rol: 'cliente'|'ejecutivo', texto: string}>  $conversacion */
    public function resumir(array $conversacion): string;

    public function simulado(): bool;
}
