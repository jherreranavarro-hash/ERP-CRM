<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use App\Modules\Auth\Acciones\ValidarIpAdministrativa;
use App\Modules\Auth\Acciones\VerificarIntentosYCaptcha;
use App\Modules\Auth\Captcha\VerificadorCaptcha;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // "Olvidé mi contraseña" responde lo mismo exista o no la cuenta (sin enumeración de
        // correos). Si el correo no existe o se pidió hace poco, simplemente no llega nada.
        $this->app->bind(\Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse::class, fn () => new class implements \Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse
        {
            public function toResponse($request)
            {
                return back()->with('status', FortifyServiceProvider::AVISO_RESTABLECER);
            }
        });
        $this->app->bind(\Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse::class, fn () => new class implements \Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse
        {
            public function toResponse($request)
            {
                return back()->with('status', FortifyServiceProvider::AVISO_RESTABLECER);
            }
        });
    }

    public const AVISO_RESTABLECER = 'Si el correo corresponde a una cuenta activa, te enviamos un enlace para crear una contraseña nueva.';

    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        // Solo usuarios activos y, si es SuperAdmin, desde una IP permitida.
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower((string) $request->input('email')))->first();
            if (! $user) {
                // Mismo costo que con una cuenta real: el tiempo de respuesta no revela qué correos existen.
                Hash::check((string) $request->input('password'), '$2y$12$'.str_repeat('a', 53));

                return null;
            }

            if ($user && $user->activo && Hash::check((string) $request->input('password'), $user->password)
                && app(ValidarIpAdministrativa::class)->permite($user, $request)) {
                return $user;
            }

            return null;
        });

        // Bloqueo progresivo y captcha antes de verificar credenciales.
        Fortify::authenticateThrough(fn () => [
            VerificarIntentosYCaptcha::class,
            RedirectIfTwoFactorAuthenticatable::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        // Freno grueso por IP a nivel de ruta; la política fina está en ControlIntentos.
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
        RateLimiter::for('two-factor', fn (Request $r) => Limit::perMinute(5)->by($r->session()->get('login.id').'|'.$r->ip()));

        Fortify::loginView(function (Request $request) {
            $requiere = (bool) $request->session()->get('login.captcha');

            return Inertia::render('Auth/Login', [
                'status' => session('status'),
                'captcha' => $requiere ? app(VerificadorCaptcha::class)->desafio($request) : null,
            ]);
        });
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/DosFactores'));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/OlvideContrasena', ['status' => session('status')]));
        Fortify::resetPasswordView(fn (Request $r) => Inertia::render('Auth/RestablecerContrasena', [
            'email' => $r->input('email'),
            'token' => $r->route('token'),
        ]));
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmarContrasena'));
    }
}
