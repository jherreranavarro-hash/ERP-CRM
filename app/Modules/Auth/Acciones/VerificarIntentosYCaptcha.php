<?php

namespace App\Modules\Auth\Acciones;

use App\Modules\Auth\Captcha\VerificadorCaptcha;
use App\Modules\Auth\ControlIntentos;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Primer paso del pipeline de login de Fortify: bloqueo progresivo y captcha. */
class VerificarIntentosYCaptcha
{
    public function __construct(private ControlIntentos $intentos, private VerificadorCaptcha $captcha) {}

    public function handle(Request $request, $next)
    {
        if ($seg = $this->intentos->bloqueoRestante($request)) {
            throw ValidationException::withMessages([
                'email' => 'Demasiados intentos. Intenta de nuevo en '.ceil($seg / 60).' min.',
            ])->status(429);
        }

        if ($this->intentos->requiereCaptcha($request) && ! $this->captcha->verificar($request)) {
            $request->session()->put('login.captcha', true);
            throw ValidationException::withMessages(['captcha' => 'Responde la verificación para continuar.']);
        }

        return $next($request);
    }
}
