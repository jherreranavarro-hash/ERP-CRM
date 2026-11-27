<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\ContactCenter\Models\Agente;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\ListaNegra;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Integraciones\Ia\AsistenteIa;
use App\Modules\Integraciones\Ia\AsistenteSimulado;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Mensajeria\Bandeja;
use App\Modules\Mensajeria\Models\Conversacion;
use App\Modules\Mensajeria\Models\Mensaje;
use App\Modules\Mensajeria\Models\Plantilla;
use App\Modules\Terreno\Models\Parada;
use App\Modules\Terreno\Recorridos;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecorridoIntegracionesTest extends TestCase
{
    private Empresa $empresa;

    private User $ejecutiva;

    private User $otro;

    private User $supervisor;

    private Agente $agente;

    private Campana $campana;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.app_secret' => 'secreto-de-prueba', 'services.whatsapp.verify_token' => 'token-verificacion']);
        $this->empresa = $this->empresa();
        $this->ejecutiva = $this->usuario($this->empresa, 'Ejecutiva comercial');
        $this->otro = $this->usuario($this->empresa, 'Ejecutiva comercial');
        $this->supervisor = $this->usuario($this->empresa, 'Supervisor de contact center');
        app(Tenancy::class)->establecer($this->empresa);
        $this->agente = Agente::create(['user_id' => $this->ejecutiva->id, 'extension' => '301', 'estado' => 'desconectado']);
        $this->campana = Campana::create(['nombre' => 'Terreno', 'tipo' => 'saliente', 'estado' => 'activa', 'fecha_inicio' => today(), 'horario' => ['dias' => [1, 2, 3, 4, 5, 6, 7], 'desde' => '00:00', 'hasta' => '23:59'],
            'max_intentos' => 3, 'creado_por' => $this->supervisor->id]);
    }

    private function falla(callable $fn, ?string $campo = null): void
    {
        try {
            $fn();
            $this->fail('Debió rechazarse.');
        } catch (ValidationException $e) {
            if ($campo) {
                $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
            }
            $this->addToAssertionCount(1);
        }
    }

    private function cliente(string $nombre, string $comuna, string $rut, ?string $telefono = null): Cliente
    {
        return Cliente::create(['rut' => $rut, 'razon_social' => $nombre, 'giro' => 'Construcción', 'direccion' => 'Av. '.$nombre.' 100', 'comuna' => $comuna, 'ciudad' => 'Santiago', 'telefono' => $telefono]);
    }

    private function lead(string $nombre, string $comuna, string $telefono, ?Agente $agente = null, string $estado = 'asignado'): Lead
    {
        return Lead::create(['campana_id' => $this->campana->id, 'nombre' => $nombre, 'telefono' => $telefono, 'comuna' => $comuna, 'direccion' => 'Calle '.$nombre.' 50',
            'agente_id' => ($agente ?? $this->agente)->id, 'estado' => $estado]);
    }

    private function activar(string $tipo, array $extra = []): Integracion
    {
        app(Tenancy::class)->establecer($this->empresa);
        $i = Integracion::firstOrNew(['tipo' => $tipo]);
        $i->fill(['activa' => true, 'ambiente' => 'certificacion', 'reintentos' => 0, 'timeout_seg' => 10] + $extra)->save();

        return $i;
    }

    private function webhook(array $cuerpo, ?string $firma = null)
    {
        $json = json_encode($cuerpo);

        return $this->call('POST', '/api/whatsapp/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $firma ?? 'sha256='.hash_hmac('sha256', $json, 'secreto-de-prueba')], $json);
    }

    private function entrante(string $phoneId, string $desde, string $id, string $texto, ?string $nombre = 'Cliente'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'x', 'changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => $phoneId], 'contacts' => [['wa_id' => $desde, 'profile' => ['name' => $nombre]]],
            'messages' => [['from' => $desde, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]],
        ]]]]]];
    }

    public function test_el_orden_optimizado_recorre_menos_que_el_orden_de_ingreso(): void
    {
        $r = app(Recorridos::class);
        // Puntos sobre una línea, ingresados desordenados: el orden óptimo es 0,1,2,3,4 desde el origen.
        $puntos = [[-33.40, -70.60], [-33.44, -70.60], [-33.41, -70.60], [-33.43, -70.60], [-33.42, -70.60]];
        $orden = $r->ordenar([-33.39, -70.60], $puntos);
        $this->assertSame([0, 2, 4, 3, 1], $orden);
        $this->assertEqualsWithDelta(5.56, $r->km([-33.39, -70.60], [-33.44, -70.60]), 0.05);
    }

    public function test_recorrido_planifica_optimiza_y_registra_visitas(): void
    {
        $svc = app(Recorridos::class);
        $c1 = $this->cliente('Constructora Norte', 'Quilicura', '76111111-6');
        $c2 = $this->cliente('Obras Sur', 'San Bernardo', '76222222-1');
        $l1 = $this->lead('Inmobiliaria Centro', 'Santiago', '+56911110001');
        $l2 = $this->lead('Edificios Oriente', 'Las Condes', '+56911110002');
        $ajeno = $this->lead('Ajeno', 'Maipú', '+56911110003', Agente::create(['user_id' => $this->otro->id, 'extension' => '302', 'estado' => 'desconectado']));
        $bloqueado = $this->lead('Bloqueado', 'Maipú', '+56911110004', null, 'no_contactar');

        $this->falla(fn () => $svc->crear($this->ejecutiva, today()->toDateString(), null, null, [['lead_id' => $ajeno->id]], $this->ejecutiva), 'paradas.0');
        $this->falla(fn () => $svc->crear($this->ejecutiva, today()->toDateString(), null, null, [['lead_id' => $bloqueado->id]], $this->ejecutiva), 'paradas.0');

        $rec = $svc->crear($this->ejecutiva, today()->toDateString(), 'Av. Ejemplo 1234', 'Santiago',
            [['cliente_id' => $c2->id], ['lead_id' => $l2->id], ['cliente_id' => $c1->id], ['lead_id' => $l1->id], ['cliente_id' => $c1->id]], $this->ejecutiva);
        $this->assertCount(4, $rec->paradas);                                                     // el repetido no se duplica
        $this->assertNotNull($rec->optimizado_at);
        $this->assertGreaterThan(0, $rec->distancia_km);
        $this->assertTrue($rec->paradas->every(fn ($p) => $p->lat !== null));
        $this->assertSame([1, 2, 3, 4], $rec->paradas->pluck('orden')->all());
        $this->assertSame('Inmobiliaria Centro', $rec->paradas->first()->nombre);              // desde Santiago centro, lo más cerca es Santiago
        $this->falla(fn () => $svc->crear($this->ejecutiva, today()->toDateString(), null, null, [['cliente_id' => $c1->id]], $this->ejecutiva), 'fecha');

        // Solo la ejecutiva registra sus visitas; visitar exige resultado y mueve el prospecto.
        $p = $rec->paradas->firstWhere('lead_id', $l1->id);
        $this->falla(fn () => $svc->marcar($p, 'visitada', 'interesado', null, $this->otro), 'parada');
        $this->falla(fn () => $svc->marcar($p, 'visitada', null, null, $this->ejecutiva), 'resultado');
        $svc->marcar($p, 'visitada', 'cotizacion', 'Pide 200 sacos', $this->ejecutiva);
        $this->assertSame('interesado', $l1->fresh()->estado);
        $this->assertSame('en_curso', $rec->fresh()->estado);
        $this->falla(fn () => $svc->quitar($p->fresh()), 'parada');

        // Reoptimizar deja primero lo visitado.
        $svc->optimizar($rec->fresh());
        $this->assertSame($p->id, $rec->fresh()->paradas->first()->id);

        // Cerrar deja lo pendiente para reagendar.
        $svc->cerrar($rec->fresh(), $this->ejecutiva);
        $this->assertSame(3, Parada::where('recorrido_id', $rec->id)->where('estado', 'no_visitada')->where('resultado', 'reagendar')->count());
        $this->falla(fn () => $svc->agregar($rec->fresh(), [['cliente_id' => $c1->id]]), 'recorrido');

        // Por HTTP: otro ejecutivo no ve ni toca el recorrido ajeno.
        $this->entrar($this->otro)->post("/ventas/recorrido/{$rec->id}/optimizar")->assertForbidden();
        $this->entrar($this->ejecutiva)->get('/ventas/recorrido?fecha='.today()->toDateString())->assertOk()
            ->assertInertia(fn ($pg) => $pg->component('Terreno/Recorrido')->where('recorrido.id', $rec->id)->where('esMio', true));
        $this->entrar($this->otro)->get('/ventas/recorrido?ejecutivo='.$this->ejecutiva->id)->assertInertia(fn ($pg) => $pg->where('ejecutivoId', $this->otro->id)->where('recorrido', null));
    }

    public function test_webhook_de_whatsapp_verifica_firma_y_registra_mensajes_y_estados(): void
    {
        $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=malo&hub_challenge=123')->assertForbidden();
        $this->get('/api/whatsapp/webhook?hub_mode=subscribe&hub_verify_token=token-verificacion&hub_challenge=123')->assertOk()->assertSee('123');

        $this->activar('whatsapp', ['identificador' => 'PHONE-1']);
        $lead = $this->lead('Pedro Pérez', 'Maipú', '+56 9 8765 4321');
        $cuerpo = $this->entrante('PHONE-1', '56987654321', 'wamid.A', '¿Tienen stock de cemento?', 'Pedro');
        $this->webhook($cuerpo, 'sha256=falsa')->assertStatus(401);
        $this->assertSame(0, Mensaje::count());

        $this->webhook($cuerpo)->assertOk()->assertJson(['procesados' => 1]);
        $this->webhook($cuerpo)->assertOk();                                                        // reintento de Meta: no duplica
        app(Tenancy::class)->establecer($this->empresa);
        $c = Conversacion::firstOrFail();
        $this->assertSame(1, Mensaje::count());
        $this->assertSame([$lead->id, $this->ejecutiva->id, 1, 'Pedro'], [$c->lead_id, $c->asignado_a, $c->no_leidos, $c->nombre]);
        $this->assertTrue($c->enVentana());

        // Un número de otra empresa (o no configurado) no cae en esta.
        $this->webhook($this->entrante('PHONE-OTRA', '56911112222', 'wamid.B', 'hola'))->assertOk()->assertJson(['procesados' => 0]);
        app(Tenancy::class)->establecer($this->empresa);

        // Estados: avanzan, nunca retroceden.
        $m = app(Bandeja::class)->enviarTexto($c, 'Sí, tenemos.', $this->ejecutiva);
        $wa = $m->fresh()->wa_id;
        $this->assertStringStartsWith('wamid.sim.', $wa);
        $estado = fn (string $s) => ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => 'PHONE-1'], 'statuses' => [['id' => $wa, 'status' => $s]]]]]]]];
        $this->webhook($estado('read'))->assertOk();
        $this->webhook($estado('delivered'))->assertOk();
        app(Tenancy::class)->establecer($this->empresa);
        $this->assertSame('leido', $m->fresh()->estado);
    }

    public function test_envio_respeta_ventana_plantillas_lista_negra_e_integracion(): void
    {
        $bandeja = app(Bandeja::class);
        $c = $bandeja->iniciar('+56 9 5555 1234', 'María', null, null, $this->ejecutiva);
        $this->assertSame('56955551234', $c->telefono);
        $this->falla(fn () => $bandeja->enviarTexto($c, 'Hola', $this->ejecutiva), 'texto');      // integración inactiva

        $this->activar('whatsapp');
        $this->falla(fn () => $bandeja->enviarTexto($c, 'Hola', $this->ejecutiva), 'texto');      // sin ventana de 24 h
        $p = Plantilla::create(['nombre' => 'seguimiento', 'cuerpo' => 'Hola {{1}}, le escribimos de Obramax por su cotización {{2}}.']);
        $this->falla(fn () => $bandeja->enviarPlantilla($c, $p, ['María'], $this->ejecutiva), 'variables');
        $m = $bandeja->enviarPlantilla($c, $p, ['María', 'N° 120'], $this->ejecutiva);
        $this->assertSame(['plantilla', 'enviado', 'Hola María, le escribimos de Obramax por su cotización N° 120.'], [$m->fresh()->tipo, $m->fresh()->estado, $m->cuerpo]);

        // Cuando el cliente responde se abre la ventana y vale el texto libre.
        $bandeja->recibir('56955551234', null, 'wamid.R', 'Gracias', now()->toImmutable());
        $this->assertSame('enviado', $bandeja->enviarTexto($c->fresh(), 'A usted', $this->ejecutiva)->fresh()->estado);
        $this->travel(25)->hours();
        $this->falla(fn () => $bandeja->enviarTexto($c->fresh(), 'Hola de nuevo', $this->ejecutiva), 'texto');
        $this->travelBack();

        // Rechazo del proveedor sin reintentos: queda fallido con el error.
        $falla = $bandeja->iniciar('+56 9 1234 0000', null, null, null, $this->ejecutiva);
        $m2 = $bandeja->enviarPlantilla($falla, $p, ['X', 'Y'], $this->ejecutiva);
        $this->assertSame('fallido', $m2->fresh()->estado);
        $this->assertNotNull($m2->fresh()->error);

        // Lista negra: no se escribe.
        ListaNegra::create(['numero' => '+56955551234', 'motivo' => 'Pidió no ser contactado', 'creado_por' => $this->supervisor->id]);
        $this->falla(fn () => $bandeja->enviarPlantilla($c->fresh(), $p, ['a', 'b'], $this->ejecutiva), 'texto');
    }

    public function test_asistente_de_ia_recibe_el_texto_seudonimizado_y_deja_registro(): void
    {
        $this->activar('whatsapp', ['identificador' => 'PHONE-1']);
        $simulado = new AsistenteSimulado;
        $this->app->instance(AsistenteIa::class, $simulado);
        $bandeja = app(Bandeja::class);
        $bandeja->recibir('56987654321', 'Pedro González', 'wamid.1', 'Hola, soy Pedro González, RUT 12.345.678-5. ¿Precio de 50 sacos? Mi correo es pedro@obra.cl y mi fono +56 9 8765 4321', now()->toImmutable());
        $c = Conversacion::firstOrFail();

        $this->entrar($this->ejecutiva)->postJson("/ventas/whatsapp/{$c->id}/ia/sugerir")->assertStatus(422);   // IA inactiva
        $this->activar('ia');
        $r = $this->entrar($this->ejecutiva)->postJson("/ventas/whatsapp/{$c->id}/ia/sugerir")->assertOk();
        $this->assertStringContainsString('cotización', $r->json('texto'));
        $enviado = json_encode($simulado->recibido, JSON_UNESCAPED_UNICODE);
        foreach (['Pedro', 'González', '12.345.678-5', 'pedro@obra.cl', '8765'] as $dato) {
            $this->assertStringNotContainsString($dato, $enviado, "El proveedor de IA recibió {$dato}");
        }
        $this->assertStringContainsString('[RUT_1]', $enviado);
        app(Tenancy::class)->establecer($this->empresa);
        $this->entrar($this->ejecutiva)->postJson("/ventas/whatsapp/{$c->id}/ia/resumir")->assertOk()->assertJsonPath('texto', fn ($t) => str_contains($t, 'cotización'));

        app(Tenancy::class)->establecer($this->empresa);
        $uso = DB::table('ia_usos')->first();
        $this->assertSame([$this->ejecutiva->id, 'sugerir', 'whatsapp:'.$c->id], [$uso->user_id, $uso->accion, $uso->contexto]);
        $this->assertGreaterThanOrEqual(5, $uso->datos_ocultados);
        $this->esperarRechazoBd(fn () => DB::table('ia_usos')->where('id', $uso->id)->update(['accion' => 'otra']));
    }

    public function test_ficha_de_integracion_guarda_el_secreto_cifrado_y_nunca_lo_devuelve(): void
    {
        $admin = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        $this->entrar($admin)->put('/configuracion/integraciones/whatsapp', ['activa' => true, 'ambiente' => 'certificacion', 'url' => 'http://inseguro.example', 'reintentos' => 5, 'timeout_seg' => 15])
            ->assertSessionHasErrors('url');
        $this->entrar($admin)->put('/configuracion/integraciones/whatsapp', ['activa' => true, 'ambiente' => 'produccion', 'url' => 'https://graph.facebook.com', 'identificador' => 'PHONE-9',
            'secreto' => 'token-super-secreto-123', 'reintentos' => 3, 'timeout_seg' => 20])->assertSessionHasNoErrors();
        app(Tenancy::class)->establecer($this->empresa);
        $i = Integracion::de('whatsapp');
        $this->assertNotSame('token-super-secreto-123', $i->secreto_cifrado);
        $this->assertSame('token-super-secreto-123', $i->secreto());
        $this->assertNotNull($i->secreto_rotado_at);

        $pagina = $this->entrar($admin)->get('/configuracion/integraciones')->assertOk();
        $this->assertStringNotContainsString('token-super-secreto-123', $pagina->getContent());
        $this->assertStringNotContainsString($i->secreto_cifrado, $pagina->getContent());
        $pagina->assertInertia(fn ($pg) => $pg->component('Configuracion/Integraciones')->where('integraciones.2.tipo', 'whatsapp')->where('integraciones.2.tiene_secreto', true));

        // Guardar sin secreto lo mantiene.
        $this->entrar($admin)->put('/configuracion/integraciones/whatsapp', ['activa' => true, 'ambiente' => 'produccion', 'reintentos' => 3, 'timeout_seg' => 20])->assertSessionHasNoErrors();
        app(Tenancy::class)->establecer($this->empresa);
        $this->assertSame('token-super-secreto-123', Integracion::de('whatsapp')->secreto());
        $this->entrar($admin)->post('/configuracion/integraciones/whatsapp/probar')->assertSessionHas('exito');
        app(Tenancy::class)->establecer($this->empresa);
        $this->assertSame('ok', Integracion::de('whatsapp')->salud);
        $this->entrar($this->ejecutiva)->get('/configuracion/integraciones')->assertForbidden();
    }

    public function test_pantallas_y_visibilidad_de_la_bandeja(): void
    {
        $this->activar('whatsapp');
        $bandeja = app(Bandeja::class);
        $mia = $bandeja->iniciar('+56 9 1111 2222', 'Mía', null, null, $this->ejecutiva);
        $ajena = $bandeja->iniciar('+56 9 3333 4444', 'Ajena', null, null, $this->otro);
        $bandeja->recibir('56911112222', null, 'wamid.x', 'hola', now()->toImmutable());

        $this->entrar($this->ejecutiva)->get('/ventas/whatsapp?filtro=todas')->assertOk()
            ->assertInertia(fn ($pg) => $pg->component('Mensajeria/Whatsapp')->has('conversaciones', 1)->where('conversaciones.0.id', $mia->id));
        $this->entrar($this->ejecutiva)->get("/ventas/whatsapp?c={$ajena->id}")->assertForbidden();
        $this->entrar($this->ejecutiva)->post("/ventas/whatsapp/{$ajena->id}/texto", ['texto' => 'x'])->assertForbidden();
        $this->entrar($this->ejecutiva)->get("/ventas/whatsapp?c={$mia->id}")->assertOk()->assertInertia(fn ($pg) => $pg->where('actual.id', $mia->id)->has('actual.mensajes', 1));
        app(Tenancy::class)->establecer($this->empresa);
        $this->assertSame(0, $mia->fresh()->no_leidos);                                            // abrirla la marca leída
        $this->entrar($this->supervisor)->get('/ventas/whatsapp?filtro=todas')->assertInertia(fn ($pg) => $pg->has('conversaciones', 2));

        // Aviso flotante: conteo de no leídas visibles.
        app(Tenancy::class)->establecer($this->empresa);
        $bandeja->recibir('56933334444', null, 'wamid.y', 'hola', now()->toImmutable());
        $this->entrar($this->supervisor)->get('/ventas/recorrido')->assertInertia(fn ($pg) => $pg->where('whatsapp', 1));
        $this->entrar($this->ejecutiva)->get('/ventas/recorrido')->assertInertia(fn ($pg) => $pg->where('whatsapp', 0));

        $admin = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        foreach (['/ventas/recorrido', '/ventas/whatsapp', '/configuracion/integraciones'] as $ruta) {
            $this->assertSame(200, $this->entrar($admin)->get($ruta)->status(), $ruta);
        }
    }
}
