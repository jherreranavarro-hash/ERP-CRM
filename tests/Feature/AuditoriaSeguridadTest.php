<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Invitacion;
use App\Modules\Core\Models\Perfil;
use App\Modules\Core\Models\Permiso;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Providers\FortifyServiceProvider;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Regresiones de la auditoría de seguridad (fase 6). */
class AuditoriaSeguridadTest extends TestCase
{
    private Empresa $e;

    protected function setUp(): void
    {
        parent::setUp();
        $this->e = $this->empresa();
    }

    private function tenant(): void
    {
        app(Tenancy::class)->establecer($this->e);
    }

    /** Rol acotado que administra usuarios pero no tiene más permisos. */
    private function adminUsuarios(): User
    {
        $rol = Rol::create(['perfil_id' => Perfil::where('clave', 'rrhh')->value('id'), 'nombre' => 'Admin usuarios '.Str::random(4), 'activo' => true]);
        $rol->permisos()->sync(Permiso::whereIn('clave', ['configuracion.usuarios.ver', 'configuracion.usuarios.crear', 'configuracion.usuarios.editar'])->pluck('id'));
        $u = $this->usuario($this->e, 'Cajera');
        UsuarioEmpresa::where('user_id', $u->id)->update(['rol_id' => $rol->id]);

        return $u->fresh();
    }

    private function membresia(User $u): UsuarioEmpresa
    {
        return UsuarioEmpresa::where('user_id', $u->id)->where('empresa_id', $this->e->id)->firstOrFail();
    }

    public function test_quien_administra_usuarios_no_entrega_mas_permisos_que_los_suyos(): void
    {
        $actor = $this->adminUsuarios();
        $gerente = Rol::where('nombre', 'Gerente')->value('id');
        $superadmin = Rol::whereHas('perfil', fn ($q) => $q->where('clave', 'superadmin'))->value('id');

        $this->entrar($actor)->post('/configuracion/usuarios/invitar', ['email' => 'nuevo@obramax.cl', 'nombre' => 'Nuevo', 'rol_id' => $gerente])
            ->assertSessionHasErrors('rol_id');
        $this->entrar($actor)->post('/configuracion/usuarios/invitar', ['email' => 'nuevo@obramax.cl', 'nombre' => 'Nuevo', 'rol_id' => $superadmin])
            ->assertSessionHasErrors('rol_id');

        $cajera = $this->usuario($this->e, 'Cajera');
        $this->entrar($actor)->put('/configuracion/usuarios/'.$this->membresia($cajera)->id, ['rol_id' => $gerente, 'activo' => true])
            ->assertSessionHasErrors('rol_id');
    }

    public function test_quien_administra_usuarios_no_toca_a_un_superadmin_ni_a_alguien_mas_poderoso(): void
    {
        $actor = $this->adminUsuarios();
        $su = $this->usuario($this->e, 'Cajera', superadmin: true);
        $gerente = $this->usuario($this->e, 'Gerente');
        $cajera = Rol::where('nombre', 'Cajera')->value('id');

        $this->entrar($actor)->put('/configuracion/usuarios/'.$this->membresia($su)->id, ['rol_id' => $this->membresia($su)->rol_id, 'activo' => false])
            ->assertForbidden();
        $this->entrar($actor)->post('/configuracion/usuarios/'.$this->membresia($su)->id.'/cerrar-sesiones')->assertForbidden();
        $this->entrar($actor)->put('/configuracion/usuarios/'.$this->membresia($gerente)->id, ['rol_id' => $cajera, 'activo' => false])
            ->assertSessionHasErrors('rol_id');
        $this->assertTrue($this->membresia($su)->activo);
        $this->assertTrue($this->membresia($gerente)->activo);
    }

    private function invitar(string $email): string
    {
        $admin = $this->usuario($this->e, 'Administrador del sistema', superadmin: true);
        $token = Str::random(64);
        Invitacion::create([
            'email' => $email, 'nombre' => 'Invitada', 'token_hash' => hash('sha256', $token),
            'empresa_id' => $this->e->id, 'rol_id' => Rol::where('nombre', 'Cajera')->value('id'),
            'invitado_por' => $admin->id, 'expira_at' => now()->addDay(),
        ]);

        return $token;
    }

    public function test_la_invitacion_a_una_cuenta_existente_no_cambia_la_contrasena_ni_inicia_sesion(): void
    {
        $otra = $this->empresa();
        $u = $this->usuario($otra);
        $hash = $u->password;
        $token = $this->invitar($u->email);

        $this->post("/invitacion/{$token}", ['nombre' => 'X', 'password' => 'OtraClave123456', 'password_confirmation' => 'OtraClave123456'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertSame($hash, $u->fresh()->password);
        $this->assertTrue(UsuarioEmpresa::where('user_id', $u->id)->where('empresa_id', $this->e->id)->where('activo', true)->exists());
    }

    public function test_la_invitacion_no_reactiva_una_cuenta_desactivada(): void
    {
        $u = $this->usuario($this->empresa());
        $u->forceFill(['activo' => false])->save();
        $token = $this->invitar($u->email);

        $this->post("/invitacion/{$token}", [])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertFalse($u->fresh()->activo);
        $this->assertFalse(UsuarioEmpresa::where('user_id', $u->id)->where('empresa_id', $this->e->id)->exists());
    }

    public function test_el_auditor_no_ve_eventos_globales_de_personas_de_otra_empresa(): void
    {
        $auditor = $this->usuario($this->e, 'Auditor');
        $ajeno = $this->usuario($this->empresa());
        $propio = $this->usuario($this->e);
        app(Tenancy::class)->limpiar();
        app(Auditoria::class)->registrar('prueba.global.ajeno', userId: $ajeno->id, global: true);
        app(Auditoria::class)->registrar('prueba.global.propio', userId: $propio->id, global: true);

        $acciones = collect($this->entrar($auditor)->get('/configuracion/auditoria?accion=prueba.global')->assertOk()
            ->viewData('page')['props']['registros']['data'])->pluck('accion');

        $this->assertContains('prueba.global.propio', $acciones);
        $this->assertNotContains('prueba.global.ajeno', $acciones);
    }

    public function test_no_se_asigna_una_meta_a_un_usuario_de_otra_empresa(): void
    {
        $admin = $this->usuario($this->e, 'Administrador del sistema', superadmin: true);
        $ajeno = $this->usuario($this->empresa(), 'Ejecutiva comercial');

        $this->entrar($admin)->post('/configuracion/metas', ['periodo' => now()->format('Y-m'), 'indicador' => array_key_first(\App\Modules\Comercial\Models\Meta::INDICADORES), 'alcance' => 'vendedor', 'user_id' => $ajeno->id, 'objetivo' => 100])
            ->assertSessionHasErrors('user_id');
    }

    public function test_restablecer_contrasena_responde_igual_exista_o_no_el_correo(): void
    {
        $u = $this->usuario($this->e);

        $this->post('/forgot-password', ['email' => $u->email])->assertSessionHas('status', FortifyServiceProvider::AVISO_RESTABLECER)->assertSessionHasNoErrors();
        $this->post('/forgot-password', ['email' => 'nadie@example.com'])->assertSessionHas('status', FortifyServiceProvider::AVISO_RESTABLECER)->assertSessionHasNoErrors();
    }
}
