<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\PoliticaContrasena;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Invitacion;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Aceptación de invitación (único camino de alta de usuarios: no hay autorregistro). */
class InvitacionController extends Controller
{
    public function mostrar(string $token)
    {
        $inv = $this->buscar($token);

        return Inertia::render('Auth/AceptarInvitacion', [
            'token' => $token,
            'vigente' => (bool) $inv?->vigente(),
            'email' => $inv?->email,
            'nombre' => $inv?->nombre,
            'empresa' => $inv?->empresa?->nombreCorto(),
            // Con cuenta existente solo se suma la empresa: la contraseña y el 2FA no se tocan.
            'cuentaExistente' => $inv ? User::where('email', $inv->email)->exists() : false,
        ]);
    }

    public function aceptar(Request $request, string $token)
    {
        $inv = $this->buscar($token);
        abort_unless($inv && $inv->vigente(), 410, 'La invitación no es válida o ya venció.');

        $existente = User::withTrashed()->where('email', $inv->email)->first();

        // Cuenta existente: la invitación solo agrega la empresa. No cambia la contraseña,
        // no reactiva cuentas desactivadas y no inicia sesión (así nunca se salta el 2FA).
        if ($existente) {
            if ($existente->trashed() || ! $existente->activo) {
                throw ValidationException::withMessages(['email' => 'Tu cuenta está desactivada. Pide a un SuperAdmin que la reactive antes de aceptar la invitación.']);
            }
            app(Tenancy::class)->como($inv->empresa, fn () => DB::transaction(function () use ($inv, $existente) {
                $this->unir($inv, $existente);
            }));

            return redirect()->route('login')->with('status', 'Listo: ya tienes acceso a '.$inv->empresa->nombreCorto().'. Ingresa con tu contraseña de siempre.');
        }

        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'confirmed', new PoliticaContrasena(null)],
        ]);

        $user = app(Tenancy::class)->como($inv->empresa, fn () => DB::transaction(function () use ($inv, $data) {
            $user = new User(['email' => $inv->email]);
            $user->name = $data['nombre'];
            $user->activo = true;
            $user->email_verified_at = now();
            $user->save();
            PoliticaContrasena::registrarCambio($user, $data['password']);
            $this->unir($inv, $user);

            return $user;
        }));

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('empresa_id', $inv->empresa_id);

        return redirect()->route('inicio')->with('exito', 'Bienvenido. Tu cuenta quedó activa.');
    }

    private function unir(Invitacion $inv, User $user): void
    {
        // La invitación se consume una sola vez, aunque lleguen dos envíos a la vez.
        $vigente = Invitacion::whereKey($inv->id)->lockForUpdate()->first();
        if (! $vigente?->vigente()) {
            throw ValidationException::withMessages(['email' => 'La invitación ya se usó o venció.']);
        }
        UsuarioEmpresa::updateOrCreate(
            ['user_id' => $user->id, 'empresa_id' => $inv->empresa_id],
            ['rol_id' => $inv->rol_id, 'local_id' => $inv->local_id, 'activo' => true],
        );
        $vigente->update(['aceptada_at' => now()]);
        app(Auditoria::class)->registrar('invitacion.aceptada', $inv, null, ['user_id' => $user->id], empresaId: $inv->empresa_id, userId: $user->id);
    }

    private function buscar(string $token): ?Invitacion
    {
        return Invitacion::with('empresa')->where('token_hash', hash('sha256', $token))->first();
    }
}
