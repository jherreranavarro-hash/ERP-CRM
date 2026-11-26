<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Modules\Auth\PoliticaContrasena;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules($user),
        ], [
            'current_password.current_password' => 'La contraseña actual no es correcta.',
        ])->validateWithBag('updatePassword');

        PoliticaContrasena::registrarCambio($user, $input['password']);
        request()->session()->forget('sesion.debe_cambiar_password');
    }
}
