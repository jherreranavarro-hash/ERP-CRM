<?php

namespace App\Modules\Auth;

use App\Modules\Core\Parametros\Parametros;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Throttling de login con bloqueo progresivo (parámetros del mantenedor
 * "Política de inicio de sesión"):
 *  - Cuenta los fallos por cuenta+IP dentro de la ventana.
 *  - Al llegar al máximo bloquea por el siguiente escalón (1, 5, 30 min…).
 *  - Exige captcha desde el intento N.
 *  - Además, un contador solo por IP (4× el máximo) frena el "password spraying".
 */
class ControlIntentos
{
    public function __construct(private Parametros $parametros) {}

    public function clave(Request $request): string
    {
        return 'login:'.sha1(Str::lower((string) $request->input('email')).'|'.$request->ip());
    }

    private function claveIp(Request $request): string
    {
        return 'login-ip:'.sha1((string) $request->ip());
    }

    /** Segundos de bloqueo restantes (0 si no está bloqueado). */
    public function bloqueoRestante(Request $request): int
    {
        $hasta = max((int) Cache::get($this->clave($request).':hasta', 0), (int) Cache::get($this->claveIp($request).':hasta', 0));

        return max(0, $hasta - now()->timestamp);
    }

    public function fallos(Request $request): int
    {
        return (int) Cache::get($this->clave($request).':fallos', 0);
    }

    public function requiereCaptcha(Request $request): bool
    {
        // Tras un bloqueo, todo intento siguiente lleva captcha.
        return (int) Cache::get($this->clave($request).':nivel', 0) > 0
            || $this->fallos($request) >= ((int) $this->parametros->valor('login.captcha_desde_intento')) - 1;
    }

    /** Registra un fallo; devuelve los minutos de bloqueo aplicados (0 si no se bloqueó). */
    public function registrarFallo(Request $request): int
    {
        $ventana = (int) $this->parametros->valor('login.ventana_minutos') * 60;
        $max = (int) $this->parametros->valor('login.intentos_maximos');

        $bloqueo = $this->contar($this->clave($request), $ventana, $max);
        $bloqueoIp = $this->contar($this->claveIp($request), $ventana, $max * 4);

        return max($bloqueo, $bloqueoIp);
    }

    private function contar(string $k, int $ventana, int $max): int
    {
        Cache::add($k.':fallos', 0, $ventana);
        $n = Cache::increment($k.':fallos');

        if ($n < $max) {
            return 0;
        }

        $escalones = $this->parametros->valor('login.escalones_bloqueo');
        $nivel = (int) Cache::get($k.':nivel', 0);
        $minutos = $escalones[min($nivel, count($escalones) - 1)];

        Cache::put($k.':hasta', now()->addMinutes($minutos)->timestamp, $minutos * 60);
        Cache::put($k.':nivel', $nivel + 1, now()->addDay());
        Cache::forget($k.':fallos');

        return $minutos;
    }

    public function limpiar(Request $request): void
    {
        foreach ([':fallos', ':hasta', ':nivel'] as $s) {
            Cache::forget($this->clave($request).$s);
        }
    }
}
