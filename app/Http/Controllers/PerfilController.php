<?php

namespace App\Http\Controllers;

use App\Modules\Auth\Sesiones;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PerfilController extends Controller
{
    public function seguridad(Request $request)
    {
        $user = $request->user();
        $obligatorio = in_array($user->perfilActual(), app(Parametros::class)->valor('2fa.perfiles_obligatorios'), true);

        return Inertia::render('Perfil/Seguridad', [
            'dosFactores' => [
                'activo' => $user->tiene2fa(),
                'pendiente' => $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null,
                'obligatorio' => $obligatorio,
                'qr' => $user->two_factor_secret && ! $user->two_factor_confirmed_at ? $user->twoFactorQrCodeSvg() : null,
                'clave' => $user->two_factor_secret && ! $user->two_factor_confirmed_at ? decrypt($user->two_factor_secret) : null,
                'codigos' => $request->session()->get('mostrar_codigos') && $user->two_factor_recovery_codes ? $user->recoveryCodes() : null,
            ],
            'politica' => [
                'largo_minimo' => app(Parametros::class)->valor('contrasenas.largo_minimo'),
            ],
            'password_cambiada' => $user->password_changed_at?->toIso8601String(),
        ]);
    }

    public function mostrarCodigos(Request $request)
    {
        return back()->with('mostrar_codigos', true);
    }

    public function cerrarOtrasSesiones(Request $request)
    {
        $user = $request->user();
        app(Sesiones::class)->cerrarTodas($user);
        app(Sesiones::class)->registrarInicio($user);
        app(Auditoria::class)->registrar('sesion.cerrar_otras', null, userId: $user->id, global: true);

        return back()->with('exito', 'Cerraste las sesiones abiertas en otros dispositivos.');
    }
}
