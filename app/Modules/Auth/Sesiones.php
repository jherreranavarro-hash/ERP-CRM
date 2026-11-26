<?php

namespace App\Modules\Auth;

use App\Models\User;
use App\Modules\Core\Parametros\Parametros;
use Illuminate\Support\Facades\Cache;

/**
 * Sesiones simultáneas por usuario (mantenedor "Sesiones"): al iniciar una
 * sesión nueva que supera el máximo, la más antigua queda invalidada y se cierra
 * en su siguiente petición. Permite también el cierre forzado de todas las sesiones.
 */
class Sesiones
{
    public function __construct(private Parametros $parametros) {}

    private function clave(int $userId): string
    {
        return "sesiones:usuario:{$userId}";
    }

    public function registrarInicio(User $user): void
    {
        if (! request()->hasSession()) {
            return;
        }
        // Se registra un token propio (no el ID de sesión, que Laravel regenera al autenticar).
        $id = \Illuminate\Support\Str::random(40);
        request()->session()->put('sesion.token', $id);
        request()->session()->put('sesion.inicio', now()->timestamp);
        request()->session()->put('sesion.actividad', now()->timestamp);

        $lista = array_values(array_filter(Cache::get($this->clave($user->id), []), fn ($s) => $s !== $id));
        $lista[] = $id;
        $max = (int) $this->parametros->valor('sesiones.simultaneas');
        $lista = array_slice($lista, -$max);
        Cache::put($this->clave($user->id), $lista, now()->addDay());
    }

    /** La sesión sigue entre las permitidas. */
    public function vigente(User $user, ?string $sessionId): bool
    {
        $lista = Cache::get($this->clave($user->id));

        if ($sessionId === null) {
            return false;
        }
        // Sin registro (p. ej. caché reiniciada) se acepta y se vuelve a registrar.
        if ($lista === null) {
            Cache::put($this->clave($user->id), [$sessionId], now()->addDay());

            return true;
        }

        return in_array($sessionId, $lista, true);
    }


    public function cerrarTodas(User $user): void
    {
        Cache::put($this->clave($user->id), [], now()->addDay());
    }
}
