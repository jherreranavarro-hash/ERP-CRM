<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Modules\Auth\PoliticaContrasena;
use App\Modules\Auth\Sesiones;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => $this->passwordRules($user)])->validate();

        PoliticaContrasena::registrarCambio($user, $input['password']);
        app(Sesiones::class)->cerrarTodas($user);
    }
}
