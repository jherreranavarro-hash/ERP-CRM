<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Models\Empresa;
use Tests\TestCase;

/** La API solo acepta tokens con scope, vencimiento, IP y empresa; nunca la sesión del navegador. */
class ApiTokensTest extends TestCase
{
    private function token(User $u, Empresa $e, array $scopes = ['empresa:read'], array $extra = []): string
    {
        $nuevo = $u->createToken('Integración de prueba', $scopes, now()->addDays(30));
        $nuevo->accessToken->forceFill(['empresa_id' => $e->id, 'creado_por' => $u->id] + $extra)->save();

        return $nuevo->plainTextToken;
    }

    public function test_token_valido_lee_su_empresa(): void
    {
        $e = $this->empresa();
        $u = $this->usuario($e, 'Administrador del sistema', superadmin: true);

        $this->withToken($this->token($u, $e))->getJson('/api/v1/empresa')
            ->assertOk()->assertJsonPath('data.rut', $e->rut);
        $this->assertDatabaseHas('api_registros', ['ruta' => 'api/v1/empresa']);
    }

    public function test_la_sesion_del_navegador_no_sirve_para_la_api(): void
    {
        $e = $this->empresa();
        $u = $this->usuario($e, 'Administrador del sistema', superadmin: true);

        $this->actingAs($u)->getJson('/api/v1/ping')->assertUnauthorized();
    }

    public function test_sin_el_scope_se_rechaza(): void
    {
        $e = $this->empresa();
        $u = $this->usuario($e, 'Administrador del sistema', superadmin: true);

        $this->withToken($this->token($u, $e, ['ventas:read']))->getJson('/api/v1/empresa')->assertForbidden();
    }

    public function test_token_revocado_o_vencido_se_rechaza(): void
    {
        $e = $this->empresa();
        $u = $this->usuario($e, 'Administrador del sistema', superadmin: true);

        $this->withToken($this->token($u, $e, extra: ['revocado_at' => now()]))->getJson('/api/v1/ping')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($u, $e, extra: ['expires_at' => now()->subMinute()]))->getJson('/api/v1/ping')->assertUnauthorized();
    }

    public function test_ip_no_permitida_se_rechaza(): void
    {
        $e = $this->empresa();
        $u = $this->usuario($e, 'Administrador del sistema', superadmin: true);

        $this->withToken($this->token($u, $e, extra: ['ips_permitidas' => ['10.0.0.0/8']]))->getJson('/api/v1/ping')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token($u, $e, extra: ['ips_permitidas' => ['127.0.0.0/24']]))->getJson('/api/v1/ping')->assertOk();
    }
}
