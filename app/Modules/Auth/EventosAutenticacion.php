<?php

namespace App\Modules\Auth;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/** Auditoría y contador de intentos ante eventos de autenticación (mínimo auditado no desactivable). */
class EventosAutenticacion
{
    public function __construct(private ControlIntentos $intentos, private Auditoria $auditoria, private Sesiones $sesiones) {}

    public function fallo(Failed $e): void
    {
        $minutos = $this->intentos->registrarFallo(request());
        if (request()->hasSession() && $this->intentos->requiereCaptcha(request())) {
            request()->session()->put('login.captcha', true);
        }
        $this->auditoria->registrar('login.fallido', null, null, [
            'email' => request()->input('email'),
            'bloqueo_min' => $minutos,
        ], userId: $e->user?->getAuthIdentifier(), global: true);
    }

    public function login(Login $e): void
    {
        $this->intentos->limpiar(request());
        if (request()->hasSession()) {
            request()->session()->forget('login.captcha');
        }
        /** @var User $u */
        $u = $e->user;
        $u->forceFill(['ultimo_acceso_at' => now()])->saveQuietly();
        $this->sesiones->registrarInicio($u);
        $this->auditoria->registrar('login.exitoso', null, userId: $u->id, global: true);
    }

    public function logout(Logout $e): void
    {
        if ($e->user) {
            $this->auditoria->registrar('logout', null, userId: $e->user->getAuthIdentifier(), global: true);
        }
    }

    public function reset(PasswordReset $e): void
    {
        $this->auditoria->registrar('password.restablecida', null, userId: $e->user->getAuthIdentifier(), global: true);
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            Failed::class => 'fallo',
            Login::class => 'login',
            Logout::class => 'logout',
            PasswordReset::class => 'reset',
        ];
    }
}
