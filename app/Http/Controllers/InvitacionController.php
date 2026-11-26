<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\PoliticaContrasena;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Invitacion;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
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
        ]);
    }

    public function aceptar(Request $request, string $token)
    {
        $inv = $this->buscar($token);
        abort_unless($inv && $inv->vigente(), 410, 'La invitación no es válida o ya venció.');

        $existente = User::withTrashed()->where('email', $inv->email)->first();
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'confirmed', new PoliticaContrasena($existente)],
        ]);

        $user = app(Tenancy::class)->como($inv->empresa, fn () => DB::transaction(function () use ($inv, $data, $existente) {
            $user = $existente ?? new User(['email' => $inv->email]);
            $user->name = $data['nombre'];
            $user->activo = true;
            $user->email_verified_at = now();
            $user->deleted_at = null;
            $user->save();
            PoliticaContrasena::registrarCambio($user, $data['password']);

            UsuarioEmpresa::updateOrCreate(
                ['user_id' => $user->id, 'empresa_id' => $inv->empresa_id],
                ['rol_id' => $inv->rol_id, 'local_id' => $inv->local_id, 'activo' => true],
            );
            $inv->update(['aceptada_at' => now()]);
            app(Auditoria::class)->registrar('invitacion.aceptada', $inv, null, ['user_id' => $user->id], empresaId: $inv->empresa_id, userId: $user->id);

            return $user;
        }));

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('empresa_id', $inv->empresa_id);

        return redirect()->route('inicio')->with('exito', 'Bienvenido. Tu cuenta quedó activa.');
    }

    private function buscar(string $token): ?Invitacion
    {
        return Invitacion::with('empresa')->where('token_hash', hash('sha256', $token))->first();
    }
}
