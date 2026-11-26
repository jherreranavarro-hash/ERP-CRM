<?php

namespace Tests\Feature;

use App\Modules\Auth\Captcha\VerificadorCaptcha;
use Illuminate\Http\Request;
use Tests\TestCase;

class LoginTest extends TestCase
{
    private function sinCaptcha(): void
    {
        $this->app->instance(VerificadorCaptcha::class, new class implements VerificadorCaptcha
        {
            public function desafio(Request $request): array
            {
                return [];
            }

            public function verificar(Request $request): bool
            {
                return true;
            }
        });
    }

    public function test_la_pantalla_de_login_carga_y_no_hay_registro_publico(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/register')->assertNotFound();
    }

    public function test_credenciales_correctas_inician_sesion(): void
    {
        $u = $this->usuario($this->empresa(), 'Ejecutiva comercial', con2fa: false);

        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
    }

    public function test_usuario_desactivado_no_entra(): void
    {
        $u = $this->usuario($this->empresa(), 'Ejecutiva comercial', con2fa: false);
        $u->forceFill(['activo' => false])->save();

        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_bloqueo_progresivo_tras_cinco_fallos(): void
    {
        $this->sinCaptcha();
        $u = $this->usuario($this->empresa(), 'Ejecutiva comercial', con2fa: false);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $u->email, 'password' => 'incorrecta']);
        }

        // Bloqueado: ni la contraseña correcta sirve hasta que pase el escalón.
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Demasiados intentos. Intenta de nuevo en 1 min.']);
        $this->postJson('/login', ['email' => $u->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();

        $this->travel(2)->minutes();
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
    }

    public function test_captcha_obligatorio_desde_el_tercer_intento(): void
    {
        $u = $this->usuario($this->empresa(), 'Ejecutiva comercial', con2fa: false);

        $this->post('/login', ['email' => $u->email, 'password' => 'mala']);
        $this->post('/login', ['email' => $u->email, 'password' => 'mala']);

        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertSessionHasErrors('captcha');
        $this->assertGuest();
    }

    public function test_los_fallos_quedan_en_la_trazabilidad(): void
    {
        $this->post('/login', ['email' => 'nadie@ejemplo.cl', 'password' => 'mala']);

        $this->assertDatabaseHas('trazabilidad', ['accion' => 'login.fallido']);
    }
}
