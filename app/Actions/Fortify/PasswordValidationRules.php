<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Modules\Auth\PoliticaContrasena;

trait PasswordValidationRules
{
    protected function passwordRules(?User $user = null): array
    {
        return ['required', 'string', 'confirmed', new PoliticaContrasena($user)];
    }
}
