<?php

namespace App\Modules\Auth;

use App\Models\User;
use App\Modules\Core\Parametros\Parametros;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Política de contraseñas leída del mantenedor: largo mínimo (piso 12),
 * verificación contra contraseñas filtradas (HIBP, k-anonimato), palabras
 * prohibidas e historial sin repetir.
 */
class PoliticaContrasena implements ValidationRule
{
    public function __construct(private ?User $user = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $p = app(Parametros::class);
        $value = (string) $value;

        $regla = Password::min((int) $p->valor('contrasenas.largo_minimo'))->letters()->numbers();
        if ($p->valor('contrasenas.verificar_filtradas') && ! app()->runningUnitTests()) {
            $regla->uncompromised();
        }
        $v = Validator::make([$attribute => $value], [$attribute => [$regla]]);
        if ($v->fails()) {
            foreach ($v->errors()->get($attribute) as $m) {
                $fail($m);
            }

            return;
        }

        $normal = mb_strtolower($value);
        foreach ($p->valor('contrasenas.palabras_prohibidas') as $palabra) {
            if ($palabra !== '' && str_contains($normal, mb_strtolower($palabra))) {
                $fail('La contraseña no puede contener palabras obvias como "'.$palabra.'".');

                return;
            }
        }

        if ($this->user) {
            if ($this->user->email && str_contains($normal, mb_strtolower(strtok($this->user->email, '@')))) {
                $fail('La contraseña no puede contener tu usuario de correo.');

                return;
            }
            $previas = DB::table('password_historial')->where('user_id', $this->user->id)
                ->orderByDesc('id')->limit((int) $p->valor('contrasenas.historial'))->pluck('password');
            foreach ([$this->user->password, ...$previas] as $hash) {
                if ($hash && Hash::check($value, $hash)) {
                    $fail('No puedes repetir una de tus últimas contraseñas.');

                    return;
                }
            }
        }
    }

    /** Guarda el hash actual en el historial antes de reemplazarlo. */
    public static function registrarCambio(User $user, string $nueva): void
    {
        if ($user->password) {
            DB::table('password_historial')->insert(['user_id' => $user->id, 'password' => $user->password, 'created_at' => now()]);
        }
        $user->forceFill(['password' => $nueva, 'password_changed_at' => now()])->save();
    }
}
