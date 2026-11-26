<?php

namespace App\Modules\Auth\Captcha;

use Illuminate\Http\Request;

class CaptchaAritmetico implements VerificadorCaptcha
{
    public function desafio(Request $request): array
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $request->session()->put('captcha.respuesta', hash('sha256', (string) ($a + $b)));

        return ['tipo' => 'aritmetico', 'pregunta' => "¿Cuánto es {$a} + {$b}?"];
    }

    public function verificar(Request $request): bool
    {
        $esperada = $request->session()->pull('captcha.respuesta');
        $dada = trim((string) $request->input('captcha'));

        return $esperada !== null && $dada !== '' && hash_equals($esperada, hash('sha256', $dada));
    }
}
