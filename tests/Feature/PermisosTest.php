<?php

namespace Tests\Feature;

use App\Modules\Core\Rbac\Rbac;
use App\Modules\Core\Tenancy\Tenancy;
use Tests\TestCase;

class PermisosTest extends TestCase
{
    public function test_sin_sesion_se_redirige_al_login(): void
    {
        $this->get('/configuracion/usuarios')->assertRedirect('/login');
    }

    public function test_la_cajera_no_entra_a_configuracion(): void
    {
        $u = $this->usuario($this->empresa(), 'Cajera');

        $this->entrar($u)->get('/configuracion/usuarios')->assertForbidden();
        $this->entrar($u)->get('/configuracion/parametros')->assertForbidden();
    }

    public function test_el_superadmin_entra_a_configuracion(): void
    {
        $u = $this->usuario($this->empresa(), 'Administrador del sistema', superadmin: true);

        foreach (['/configuracion/usuarios', '/configuracion/roles', '/configuracion/parametros', '/configuracion/auditoria', '/configuracion/tokens', '/configuracion/locales'] as $ruta) {
            $this->entrar($u)->get($ruta)->assertOk();
        }
    }

    public function test_el_auditor_lee_pero_no_crea(): void
    {
        $e = $this->empresa();
        $u = $this->usuario($e, 'Auditor', con2fa: false);

        $this->entrar($u)->get('/configuracion/auditoria')->assertOk();
        $this->entrar($u)->post('/configuracion/locales', ['codigo' => 'X', 'nombre' => 'X'])->assertForbidden();
    }

    public function test_ni_el_superadmin_tiene_permisos_restringidos(): void
    {
        $e = $this->empresa();
        $u = $this->usuario($e, 'Administrador del sistema', superadmin: true);
        app(Tenancy::class)->establecer($e);

        $this->assertTrue(app(Rbac::class)->permite($u, 'configuracion.usuarios.ver'));
        $this->assertFalse(app(Rbac::class)->permite($u, 'ventascrm.grabaciones.escuchar'));
    }

    public function test_perfil_con_2fa_obligatorio_queda_retenido_hasta_activarlo(): void
    {
        $u = $this->usuario($this->empresa(), 'Cajera', con2fa: false);

        $this->entrar($u)->get('/')->assertRedirect(route('perfil.seguridad'));
        $this->entrar($u)->get('/perfil/seguridad')->assertOk();
    }

    public function test_perfil_sin_2fa_obligatorio_entra_directo(): void
    {
        $u = $this->usuario($this->empresa(), 'Ejecutiva comercial', con2fa: false);

        $this->entrar($u)->get('/')->assertOk();
    }

    public function test_usuario_sin_empresa_no_puede_operar(): void
    {
        $u = $this->usuario(null, con2fa: false);

        $this->entrar($u)->get('/')->assertRedirect('/login');
    }
}
