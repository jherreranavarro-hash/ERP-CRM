<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Rbac\Rbac;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Operacion\Models\Alerta;
use App\Modules\Operacion\Models\SolicitudPrivilegio;
use App\Modules\Operacion\Privilegios;
use App\Modules\Operacion\Retencion;
use App\Modules\Operacion\Vigilancia;
use App\Notifications\AlertaSistema;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperacionContinuidadTest extends TestCase
{
    private Empresa $empresa;

    private User $a;

    private User $b;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('archivo');
        $this->empresa = $this->empresa();
        $this->a = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        $this->b = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        $this->tenant();
    }

    private function tenant(): void
    {
        app(Tenancy::class)->establecer($this->empresa);
    }

    /** Fija un parámetro con cuatro ojos (a propone, b aprueba). */
    private function parametro(string $clave, mixed $valor, string $alcance = 'empresa'): void
    {
        $p = app(Parametros::class);
        $p->aprobar($p->proponer($clave, $valor, $alcance, 'Prueba', $this->a), $this->b, 'Ok');
    }

    /** @return array{lead:int, conversacion:int, mensaje:int, llamada:int, api:int} */
    private function datosAntiguos(): array
    {
        $viejo = now()->subYears(3);
        $campana = Campana::create(['nombre' => 'Antigua', 'tipo' => 'saliente', 'estado' => 'terminada', 'fecha_inicio' => $viejo->toDateString(), 'asignacion' => 'round_robin',
            'horario' => ['dias' => [1], 'desde' => '09:00', 'hasta' => '18:00'], 'creado_por' => $this->a->id]);
        $e = $this->empresa->id;
        $lead = DB::table('leads')->insertGetId(['empresa_id' => $e, 'campana_id' => $campana->id, 'nombre' => 'Juana Pérez', 'rut' => '11111111-1', 'telefono' => '56911112222',
            'email' => 'juana@correo.cl', 'direccion' => 'Calle 1', 'estado' => 'perdido', 'created_at' => $viejo, 'updated_at' => $viejo]);
        $conv = DB::table('wa_conversaciones')->insertGetId(['empresa_id' => $e, 'telefono' => '56911112222', 'nombre' => 'Juana', 'lead_id' => $lead, 'created_at' => $viejo, 'updated_at' => $viejo]);
        $msg = DB::table('wa_mensajes')->insertGetId(['empresa_id' => $e, 'conversacion_id' => $conv, 'direccion' => 'entrante', 'cuerpo' => 'Hola, mi RUT es 11111111-1', 'estado' => 'recibido', 'created_at' => $viejo, 'updated_at' => $viejo]);
        $nuevo = DB::table('wa_mensajes')->insertGetId(['empresa_id' => $e, 'conversacion_id' => $conv, 'direccion' => 'entrante', 'cuerpo' => 'Mensaje reciente', 'estado' => 'recibido', 'created_at' => now(), 'updated_at' => now()]);
        $llamada = DB::table('llamadas')->insertGetId(['empresa_id' => $e, 'lead_id' => $lead, 'direccion' => 'saliente', 'numero' => '56911112222', 'estado' => 'terminada', 'inicio' => $viejo,
            'grabacion_ref' => 'grab/2023/abc.wav', 'created_at' => $viejo, 'updated_at' => $viejo]);
        $api = DB::table('api_registros')->insertGetId(['empresa_id' => $e, 'token_id' => 1, 'metodo' => 'GET', 'ruta' => 'api/v1/ventas', 'estado_http' => 200, 'duracion_ms' => 5, 'created_at' => $viejo]);

        return compact('lead', 'conv', 'msg', 'nuevo', 'llamada', 'api');
    }

    public function test_la_retencion_anonimiza_mensajes_prospectos_y_grabaciones_vencidas(): void
    {
        $d = $this->datosAntiguos();

        $hechas = app(Retencion::class)->aplicar();
        $this->tenant();

        $lead = DB::table('leads')->find($d['lead']);
        $this->assertSame(Retencion::ANONIMO, $lead->nombre);
        $this->assertNull($lead->rut);
        $this->assertNull($lead->email);
        $this->assertSame(Retencion::TEXTO_RETIRADO, DB::table('wa_mensajes')->find($d['msg'])->cuerpo);
        $this->assertSame('Mensaje reciente', DB::table('wa_mensajes')->find($d['nuevo'])->cuerpo, 'Lo vigente no se toca');
        $this->assertNull(DB::table('llamadas')->find($d['llamada'])->grabacion_ref);
        $this->assertNull(DB::table('api_registros')->find($d['api']), 'Log de API vencido se archiva y borra');

        // Manifiesto de grabaciones para que la central purgue el audio, y archivo del log de API.
        $grab = collect($hechas)->firstWhere('categoria', 'grabaciones');
        Storage::disk('archivo')->assertExists($grab->archivo);
        $this->assertStringContainsString('grab/2023/abc.wav', Storage::disk('archivo')->get($grab->archivo));
        Storage::disk('archivo')->assertExists(collect($hechas)->firstWhere('categoria', 'logs_api')->archivo);

        // La segunda pasada no encuentra nada más.
        $otra = collect(app(Retencion::class)->aplicar());
        $this->assertSame(0, $otra->where('empresa_id', $this->empresa->id)->sum('registros'));
    }

    public function test_eliminar_borra_y_la_retencion_legal_suspende_todo(): void
    {
        $d = $this->datosAntiguos();
        $this->parametro('retencion.retencion_legal', true);

        $hechas = collect(app(Retencion::class)->aplicar());
        $this->tenant();
        $this->assertSame('suspendida', $hechas->firstWhere('empresa_id', $this->empresa->id)->accion);
        $this->assertSame('Juana Pérez', DB::table('leads')->find($d['lead'])->nombre);
        $this->assertNotNull(DB::table('wa_mensajes')->find($d['msg']));
        $this->assertNotNull(DB::table('api_registros')->find($d['api']), 'Tampoco se borran sus logs de API');

        $this->parametro('retencion.retencion_legal', false);
        $this->parametro('retencion.accion_al_vencer', 'eliminar');
        app(Retencion::class)->aplicar();
        $this->tenant();
        $this->assertNull(DB::table('wa_mensajes')->find($d['msg']));
        $this->assertNotNull(DB::table('wa_mensajes')->find($d['nuevo']));
        $this->assertSame(Retencion::ANONIMO, DB::table('leads')->find($d['lead'])->nombre, 'Prospectos se anonimizan, nunca se borran');
    }

    public function test_uso_anomalo_de_api_emite_una_alerta_de_seguridad_sin_repetirla(): void
    {
        $filas = [];
        // Historia: 1 llamada por día durante la semana; última hora: 60.
        for ($i = 1; $i <= 7; $i++) {
            $filas[] = ['empresa_id' => $this->empresa->id, 'token_id' => 77, 'metodo' => 'GET', 'ruta' => 'api/v1/ventas', 'estado_http' => 200, 'duracion_ms' => 3, 'created_at' => now()->subDays($i)];
        }
        for ($i = 0; $i < 60; $i++) {
            $filas[] = ['empresa_id' => $this->empresa->id, 'token_id' => 77, 'metodo' => 'GET', 'ruta' => 'api/v1/ventas', 'estado_http' => $i < 25 ? 429 : 200, 'duracion_ms' => 3, 'created_at' => now()->subMinutes(30)];
        }
        DB::table('api_registros')->insert($filas);

        $alertas = app(Vigilancia::class)->api();
        $this->tenant();
        $this->assertEqualsCanonicalizing(['api_anomalia', 'api_errores'], collect($alertas)->pluck('codigo')->all());
        $anomalia = Alerta::where('codigo', 'api_anomalia')->firstOrFail();
        $this->assertSame('seguridad', $anomalia->tipo);
        $this->assertSame($this->empresa->id, $anomalia->empresa_id);
        Notification::assertSentTo(new AnonymousNotifiable, AlertaSistema::class, fn ($n, $c, $notifiable) => in_array('seguridad@obramax.cl', (array) $notifiable->routes['mail'], true));

        $this->assertSame([], array_filter(app(Vigilancia::class)->api()), 'La misma alerta no se repite dentro de la ventana');
    }

    public function test_respaldos_atrasados_alertan_y_el_registro_del_servidor_los_normaliza(): void
    {
        $v = app(Vigilancia::class);
        $this->assertTrue($v->estadoRespaldos()['respaldo_atrasado']);
        $this->assertContains('respaldo_vencido', collect($v->respaldos())->filter()->pluck('codigo')->all());

        $this->artisan('respaldos:registrar', ['tipo' => 'respaldo', 'resultado' => 'ok', '--archivo' => '/var/backups/erp-20261127.dump', '--bytes' => 1048576, '--duracion' => 42])->assertSuccessful();
        $this->artisan('respaldos:registrar', ['tipo' => 'restauracion', 'resultado' => 'ok', '--detalle' => 'Restaurado en base temporal; 312 tablas'])->assertSuccessful();
        $estado = $v->estadoRespaldos();
        $this->assertFalse($estado['respaldo_atrasado']);
        $this->assertFalse($estado['prueba_atrasada']);

        $this->artisan('respaldos:registrar', ['tipo' => 'restauracion', 'resultado' => 'fallo', '--detalle' => 'pg_restore: error'])->assertSuccessful();
        $this->assertTrue(Alerta::where('codigo', 'restauracion_fallida')->whereNull('empresa_id')->exists());
        $this->artisan('respaldos:registrar', ['tipo' => 'x', 'resultado' => 'ok'])->assertFailed();
    }

    public function test_dar_permisos_a_un_rol_requiere_cuatro_ojos_y_quitar_rige_al_tiro(): void
    {
        $rol = Rol::where('nombre', 'Cajera')->firstOrFail();
        $antes = $rol->permisos()->pluck('clave')->all();
        $quitado = $antes[0];
        $nuevo = 'compras.orden-de-compra.ver';
        $this->assertNotContains($nuevo, $antes);

        $deseados = array_values(array_diff([...$antes, $nuevo], [$quitado]));
        $this->entrar($this->a)->put("/configuracion/roles/{$rol->id}", [
            'nombre' => $rol->nombre, 'perfil_id' => $rol->perfil_id, 'activo' => true, 'permisos' => $deseados, 'motivo' => 'Apoyo en bodega',
        ])->assertRedirect()->assertSessionHas('exito', fn ($m) => str_contains($m, 'pendientes de aprobación'));
        $this->tenant();

        $actuales = $rol->permisos()->pluck('clave')->all();
        $this->assertNotContains($quitado, $actuales, 'Quitar rige de inmediato');
        $this->assertNotContains($nuevo, $actuales, 'Agregar espera aprobación');
        $s = SolicitudPrivilegio::where('rol_id', $rol->id)->where('estado', 'pendiente')->firstOrFail();
        $this->assertSame([$nuevo], $s->permisos);

        $this->entrar($this->a)->post("/configuracion/privilegios/{$s->id}/aprobar")->assertSessionHasErrors('solicitud');
        $this->esperarRechazoBd(fn () => SolicitudPrivilegio::whereKey($s->id)->update(['estado' => 'aprobada', 'resuelto_por' => $this->a->id]));

        $this->entrar($this->b)->post("/configuracion/privilegios/{$s->id}/aprobar")->assertSessionHasNoErrors();
        $this->tenant();
        $this->assertContains($nuevo, $rol->permisos()->pluck('clave')->all());
        $this->assertSame('aprobada', $s->fresh()->estado);
    }

    public function test_superadmin_se_otorga_con_otro_superadmin_y_siempre_queda_uno(): void
    {
        $u = $this->usuario($this->empresa, 'Gerente');
        $p = app(Privilegios::class);
        $m = UsuarioEmpresa::where('user_id', $u->id)->firstOrFail();

        $this->entrar($this->a)->post("/configuracion/usuarios/{$m->id}/superadmin", ['motivo' => 'Reemplazo en vacaciones'])->assertSessionHasNoErrors();
        $this->tenant();
        $s = SolicitudPrivilegio::where('user_id', $u->id)->firstOrFail();
        $this->assertFalse($u->fresh()->es_superadmin);

        try {
            $p->aprobar($s, $this->a);
            $this->fail('Quien pide no aprueba');
        } catch (ValidationException) {
        }
        try {
            $p->aprobar($s, $u);
            $this->fail('El beneficiado no aprueba (ni es SuperAdmin)');
        } catch (ValidationException) {
        }
        $p->aprobar($s, $this->b);
        $this->assertTrue($u->fresh()->es_superadmin);

        // Nadie se propone a sí mismo, ni siquiera saltándose la app.
        $this->esperarRechazoBd(fn () => SolicitudPrivilegio::create(['tipo' => 'superadmin', 'user_id' => $this->a->id, 'otorgar' => true, 'motivo' => 'x', 'solicitado_por' => $this->a->id]));

        // Revocar rige al tiro, pero nunca deja el sistema sin SuperAdmin con 2FA.
        $p->revocarSuperadmin($u->fresh(), $this->a, 'Fin del reemplazo');
        $p->revocarSuperadmin($this->b, $this->a, 'Rotación de cargo');
        User::where('es_superadmin', true)->where('id', '<>', $this->a->id)->get()->each(fn ($x) => $x->forceFill(['es_superadmin' => false])->save());
        try {
            $p->revocarSuperadmin($this->a, $this->a, 'Me quito la marca');
            $this->fail('Debe quedar al menos un SuperAdmin');
        } catch (ValidationException) {
        }
        $this->assertTrue($this->a->fresh()->es_superadmin);
        $this->assertTrue(Alerta::where('codigo', 'superadmin_revocado')->exists());
    }

    public function test_nadie_cambia_su_propio_rol(): void
    {
        $m = UsuarioEmpresa::where('user_id', $this->a->id)->firstOrFail();
        $otro = Rol::where('nombre', 'Gerente')->value('id');

        $this->entrar($this->a)->put("/configuracion/usuarios/{$m->id}", ['rol_id' => $otro, 'activo' => true])->assertSessionHasErrors('rol_id');
        $this->entrar($this->b)->put("/configuracion/usuarios/{$m->id}", ['rol_id' => $otro, 'activo' => true])->assertSessionHasNoErrors();
    }

    public function test_alertas_operativas_y_panel(): void
    {
        DB::table('folio_rangos')->insert(['empresa_id' => $this->empresa->id, 'tipo_dte' => 39, 'desde' => 1, 'hasta' => 100, 'siguiente' => 95, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('integraciones')->insert(['empresa_id' => $this->empresa->id, 'tipo' => 'linkedin', 'activa' => true, 'salud' => 'caida', 'salud_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        Artisan::call('operacion:vigilar');
        $this->tenant();
        $codigos = Alerta::pluck('codigo')->all();
        foreach (['folios_por_agotarse', 'integracion_caida', 'respaldo_vencido', 'restauracion_vencida'] as $c) {
            $this->assertContains($c, $codigos);
        }

        $this->entrar($this->a)->get('/configuracion/operacion')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Configuracion/Operacion')->has('alertas', 4)->where('respaldos.respaldo_atrasado', true));
        $this->tenant();
        $alerta = Alerta::where('codigo', 'folios_por_agotarse')->firstOrFail();
        $this->entrar($this->a)->post("/configuracion/operacion/alertas/{$alerta->id}/atender")->assertRedirect();
        $this->tenant();
        $this->tenant();
        $this->assertNotNull($alerta->fresh()->atendida_at);

        $cajera = $this->usuario($this->empresa, 'Cajera');
        $this->entrar($cajera)->get('/configuracion/operacion')->assertForbidden();
        $this->tenant();
        $this->assertTrue(app(Rbac::class)->permite(User::find($this->a->id), 'configuracion.operacion.ver'));
    }

    public function test_comandos_de_instalacion_y_verificacion(): void
    {
        // Con empresas ya creadas la instalación inicial no se repite.
        $this->artisan('sistema:instalar', ['--rut' => '76086428-5', '--razon-social' => 'X', '--nombre-fantasia' => 'X', '--admin-nombre' => 'X', '--admin-email' => 'x@obramax.cl'])->assertFailed();

        // En pruebas no es producción: la verificación debe fallar (y no romperse).
        $this->artisan('sistema:verificar-produccion')->assertFailed();
        $this->artisan('datos:aplicar-retencion')->assertSuccessful();
        $this->artisan('respaldos:retencion-dias')->expectsOutput('30')->assertSuccessful();
    }
}
