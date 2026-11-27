<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\FolioRango;
use Tests\TestCase;

class ApiVentasTest extends TestCase
{
    private Empresa $e;

    private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e = $this->empresa();
        $this->u = $this->usuario($this->e, 'Cajera');
        app(Tenancy::class)->como($this->e, function () {
            app(Parametros::class)->proponer('modulos.facturacion', true, 'empresa', 'Pruebas', $this->u);
            FolioRango::create(['tipo_dte' => 39, 'desde' => 1, 'hasta' => 500, 'siguiente' => 1]);
            FolioRango::create(['tipo_dte' => 61, 'desde' => 1, 'hasta' => 500, 'siguiente' => 1]);
            $p = Producto::create(['sku' => 'A', 'nombre' => 'Arena m3', 'precio_neto' => '10000']);
            $docs = app(Documentos::class);
            foreach ([1, 2, 3] as $n) {
                $b = $docs->emitir($docs->guardar(['tipo_dte' => 39, 'lineas' => [['producto_id' => $p->id, 'cantidad' => (string) $n]]], $this->u), $this->u);
            }
            $docs->emitir($docs->prepararNota($b, 61, 1, 'Anula', $this->u), $this->u);
            Cliente::create(['rut' => '76543210-3', 'razon_social' => 'Constructora Andes SpA']);
        });
    }

    private function token(array $scopes, ?Empresa $e = null): string
    {
        $t = $this->u->createToken('BI', $scopes, now()->addDays(30));
        $t->accessToken->forceFill(['empresa_id' => ($e ?? $this->e)->id])->save();

        return $t->plainTextToken;
    }

    public function test_resumen_resta_notas_de_credito(): void
    {
        $hoy = now()->toDateString();
        $r = $this->withToken($this->token(['informe-ventas:read']))->getJson("/api/v1/informe-ventas/resumen?desde={$hoy}&hasta={$hoy}")->assertOk();

        // Boletas por 1, 2 y 3 m3 a $11.900 con IVA; la de 3 se anula con NC.
        $this->assertSame(4, $r->json('data.cantidad'));
        $this->assertSame(11900 + 23800, $r->json('data.total'));
    }

    public function test_dtes_paginados_con_metadatos_y_tope_de_200(): void
    {
        $hoy = now()->toDateString();
        $t = $this->token(['informe-ventas:read']);

        $this->withToken($t)->getJson("/api/v1/informe-ventas/dtes?desde={$hoy}&hasta={$hoy}&per_page=2")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4)->assertJsonPath('meta.last_page', 2);
        $this->app['auth']->forgetGuards();
        $this->withToken($t)->getJson("/api/v1/informe-ventas/dtes?desde={$hoy}&hasta={$hoy}&per_page=500")->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($t)->getJson('/api/v1/informe-ventas/dtes')->assertUnprocessable();
    }

    public function test_cada_endpoint_exige_su_scope(): void
    {
        $t = $this->token(['clientes:read']);
        $this->withToken($t)->getJson('/api/v1/clientes')->assertOk()->assertJsonPath('data.0.rut', '76543210-3');
        $this->app['auth']->forgetGuards();
        $this->withToken($t)->getJson('/api/v1/informe-ventas/resumen?desde=2026-11-01&hasta=2026-11-30')->assertForbidden();
    }

    public function test_un_token_no_ve_datos_de_otra_empresa(): void
    {
        $otra = $this->empresa();
        $hoy = now()->toDateString();
        $url = "/api/v1/informe-ventas/resumen?desde={$hoy}&hasta={$hoy}";

        // Sin membresía en la empresa del token: se rechaza.
        $this->withToken($this->token(['informe-ventas:read'], $otra))->getJson($url)->assertForbidden();

        // Miembro de ambas: el token solo ve la empresa a la que pertenece.
        app(Tenancy::class)->como($otra, fn () => UsuarioEmpresa::create([
            'user_id' => $this->u->id, 'empresa_id' => $otra->id,
            'rol_id' => Rol::where('nombre', 'Cajera')->value('id'), 'activo' => true,
        ]));
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token(['informe-ventas:read'], $otra))->getJson($url)
            ->assertOk()->assertJsonPath('data.cantidad', 0);
    }

    public function test_token_de_usuario_desactivado_o_sin_membresia_se_rechaza(): void
    {
        $hoy = now()->toDateString();
        $url = "/api/v1/informe-ventas/resumen?desde={$hoy}&hasta={$hoy}";
        $t = $this->token(['informe-ventas:read']);
        $this->withToken($t)->getJson($url)->assertOk();

        UsuarioEmpresa::where('user_id', $this->u->id)->update(['activo' => false]);
        $this->app['auth']->forgetGuards();
        $this->withToken($t)->getJson($url)->assertForbidden();

        UsuarioEmpresa::where('user_id', $this->u->id)->update(['activo' => true]);
        $this->u->forceFill(['activo' => false])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($t)->getJson($url)->assertForbidden();
    }

    public function test_crear_y_emitir_por_api(): void
    {
        $pid = app(Tenancy::class)->como($this->e, fn () => Producto::value('id'));

        $this->withToken($this->token(['dtes:write']))->postJson('/api/v1/dtes', ['tipo_dte' => 39, 'emitir' => true, 'lineas' => [['producto_id' => $pid, 'cantidad' => 1]]])
            ->assertCreated()->assertJsonPath('data.estado', 'emitido')->assertJsonPath('data.folio', 4)->assertJsonPath('data.total', 11900);
    }

    public function test_el_scope_no_basta_si_el_usuario_no_tiene_permiso(): void
    {
        $this->u = $this->usuario($this->e, 'Bodeguero');

        $this->withToken($this->token(['dtes:write']))->postJson('/api/v1/dtes', ['tipo_dte' => 39, 'lineas' => [['descripcion' => 'X', 'cantidad' => 1, 'precio_unitario' => 100]]])
            ->assertForbidden();
    }
}
