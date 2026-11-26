<?php

namespace App\Modules\Auth\Captcha;

use Illuminate\Http\Request;

/**
 * Captcha detrás de una interfaz: la implementación por defecto es un desafío
 * aritmético propio (sin terceros). En producción se puede enlazar un proveedor
 * (Turnstile, hCaptcha) desde el mantenedor de Integraciones.
 */
interface VerificadorCaptcha
{
    /** Genera un desafío nuevo y devuelve lo que la vista debe mostrar. */
    public function desafio(Request $request): array;

    public function verificar(Request $request): bool;
}
