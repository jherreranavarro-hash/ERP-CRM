<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContactCenter\Asignador;
use App\Modules\ContactCenter\Calidad;
use App\Modules\ContactCenter\CentroContacto;
use App\Modules\ContactCenter\Models\Agente;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Cola;
use App\Modules\ContactCenter\Models\CriterioCalidad;
use App\Modules\ContactCenter\Models\GrabacionAcceso;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\ListaNegra;
use App\Modules\ContactCenter\Models\Llamada;
use App\Modules\ContactCenter\Models\NumeroEntrante;
use App\Modules\ContactCenter\Models\ProveedorIp;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContactCenterTest extends TestCase
{
    private Empresa $empresa;

    private User $supervisor;

    private User $ana;

    private User $beto;

    private Agente $a1;

    private Agente $a2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->next('Tuesday')->setTime(11, 0));
        $this->empresa = $this->empresa();
        $this->supervisor = $this->usuario($this->empresa, 'Supervisor de contact center');
        $this->ana = $this->usuario($this->empresa, 'Agente de contact center');
        $this->beto = $this->usuario($this->empresa, 'Agente de contact center');
        app(Tenancy::class)->establecer($this->empresa);
        $this->a1 = Agente::create(['user_id' => $this->ana->id, 'extension' => '201', 'zonas' => ['Maipú'], 'capacidad' => 50, 'estado' => 'desconectado']);
        $this->a2 = Agente::create(['user_id' => $this->beto->id, 'extension' => '202', 'zonas' => ['Providencia'], 'capacidad' => 2, 'estado' => 'desconectado']);
    }

    private function falla(callable $fn, ?string $campo = null): void
    {
        try {
            $fn();
            $this->fail('Debió rechazarse.');
        } catch (ValidationException $e) {
            if ($campo) {
                $this->assertArrayHasKey($campo, $e->errors());
            }
            $this->addToAssertionCount(1);
        }
    }

    private function campana(array $d = []): Campana
    {
        $c = Campana::create($d + ['nombre' => 'Clientes constructoras', 'tipo' => 'saliente', 'estado' => 'activa', 'fecha_inicio' => now()->toDateString(),
            'horario' => ['dias' => [1, 2, 3, 4, 5], 'desde' => '09:00', 'hasta' => '18:00'], 'max_intentos' => 2, 'creado_por' => $this->supervisor->id]);
        $c->agentes()->sync([$this->a1->id => ['empresa_id' => $this->empresa->id], $this->a2->id => ['empresa_id' => $this->empresa->id]]);

        return $c;
    }

    public function test_importa_y_asigna_por_rotacion_zona_y_carga(): void
    {
        ListaNegra::create(['numero' => '+56 9 1111 1111', 'motivo' => 'Pidió no ser llamado', 'creado_por' => $this->supervisor->id]);
        $c = $this->campana();
        $csv = "nombre;telefono;rut;email;comuna\nPedro;+56922222222;;;Maipú\nLuisa;+56933333333;;;Providencia\nPedro bis;+56922222222;;;Maipú\nBloqueado;+56911111111;;;Maipú\n;123;;;\nMarta;+56944444444;;;Maipú\nJuan;+56955555555;;;Ñuñoa\n";
        $r = app(Asignador::class)->importar($c, $csv, $this->supervisor);
        $this->assertSame(['creados' => 4, 'duplicados' => 1, 'bloqueados' => 1], array_slice($r, 0, 3));
        $this->assertCount(1, $r['errores']);
        $this->assertSame('no_contactar', Lead::where('nombre', 'Bloqueado')->value('estado'));

        // Por zona: cada uno a su comuna; Juan (Ñuñoa) no tiene agente.
        $c->update(['asignacion' => 'zona']);
        $this->assertSame(['asignados' => 3, 'sin_agente' => 1], app(Asignador::class)->asignar($c, $this->supervisor));
        $this->assertSame($this->a2->id, Lead::where('nombre', 'Luisa')->value('agente_id'));
        $this->assertSame($this->a1->id, Lead::where('nombre', 'Marta')->value('agente_id'));

        // Por carga, respetando la capacidad (Beto tiene 1 de 2): Juan va a Beto; el siguiente ya no cabe en Beto.
        $c->update(['asignacion' => 'carga']);
        $this->assertSame(1, app(Asignador::class)->asignar($c, $this->supervisor)['asignados']);
        $this->assertSame($this->a2->id, Lead::where('nombre', 'Juan')->value('agente_id'));
        Lead::create(['campana_id' => $c->id, 'nombre' => 'Nuevo', 'telefono' => '+56966666666']);
        app(Asignador::class)->asignar($c, $this->supervisor);
        $this->assertSame($this->a1->id, Lead::where('nombre', 'Nuevo')->value('agente_id'));

        // Rotativa: alterna empezando por el siguiente del último asignado.
        $c2 = $this->campana(['nombre' => 'Rotativa', 'asignacion' => 'round_robin']);
        $this->a2->update(['capacidad' => 100]);
        foreach (range(1, 4) as $i) {
            Lead::create(['campana_id' => $c2->id, 'nombre' => "R{$i}", 'telefono' => "+5697000000{$i}"]);
        }
        app(Asignador::class)->asignar($c2, $this->supervisor);
        $this->assertSame([$this->a1->id, $this->a2->id, $this->a1->id, $this->a2->id], Lead::where('campana_id', $c2->id)->orderBy('id')->pluck('agente_id')->all());

        // Reasignar solo a agentes de la campaña.
        $otro = Agente::create(['user_id' => $this->supervisor->id, 'extension' => '299', 'estado' => 'desconectado']);
        $this->falla(fn () => app(Asignador::class)->reasignar([Lead::where('nombre', 'R1')->value('id')], $otro, $this->supervisor), 'agente_id');
    }

    public function test_por_zona_el_agente_sin_zonas_atiende_las_comunas_sin_cobertura(): void
    {
        $c = $this->campana(['asignacion' => 'zona']);
        $this->a2->update(['zonas' => []]);
        foreach ([['Marta', 'Maipú', '+56944444444'], ['Juan', 'Ñuñoa', '+56955555555']] as [$n, $comuna, $tel]) {
            Lead::create(['campana_id' => $c->id, 'nombre' => $n, 'comuna' => $comuna, 'telefono' => $tel]);
        }
        $this->assertSame(['asignados' => 2, 'sin_agente' => 0], array_slice(app(Asignador::class)->asignar($c, $this->supervisor), 0, 2));
        $this->assertSame($this->a1->id, Lead::where('nombre', 'Marta')->value('agente_id'));   // quien cubre la comuna tiene prioridad
        $this->assertSame($this->a2->id, Lead::where('nombre', 'Juan')->value('agente_id'));
    }

    public function test_llamada_saliente_eventos_tipificacion_y_reglas(): void
    {
        $cc = app(CentroContacto::class);
        $c = $this->campana();
        $l = Lead::create(['campana_id' => $c->id, 'nombre' => 'Pedro', 'telefono' => '+56922222222', 'agente_id' => $this->a1->id, 'estado' => 'asignado']);

        $this->falla(fn () => $cc->llamar($this->a1, $l, $this->ana), 'agente');               // desconectado
        $this->falla(fn () => $cc->cambiarEstado($this->a1, 'pausa'), 'motivo');
        $cc->cambiarEstado($this->a1, 'disponible');
        $this->falla(fn () => $cc->llamar($this->a1, $l, $this->beto), 'agente');              // otro usuario

        $ll = $cc->llamar($this->a1->fresh(), $l, $this->ana);
        $this->assertSame('marcando', $ll->estado);
        $this->assertSame('en_llamada', $this->a1->fresh()->estado);
        $this->assertSame(1, $l->fresh()->intentos);
        $this->falla(fn () => $cc->cambiarEstado($this->a1->fresh(), 'pausa', 'colacion'), 'estado');

        $this->travel(5)->seconds();
        $cc->evento($ll->pbx_id, 'contestada');
        $this->travel(95)->seconds();
        $this->falla(fn () => $cc->tipificar($ll, ['resultado' => 'interesado'], $this->ana), 'llamada'); // aún en curso
        $cc->evento($ll->pbx_id, 'terminada');
        $cc->evento($ll->pbx_id, 'contestada');                                                 // repetido y fuera de orden: no retrocede
        $ll = $ll->fresh();
        $this->assertSame('terminada', $ll->estado);
        $this->assertSame(95, $ll->duracion_seg);
        $this->assertNotNull($ll->grabacion_ref);
        $this->assertSame('disponible', $this->a1->fresh()->estado);

        $this->falla(fn () => $cc->tipificar($ll, ['resultado' => 'volver_a_llamar'], $this->ana), 'proximo_contacto');
        $cc->tipificar($ll, ['resultado' => 'no_contactar', 'notas' => 'Molesto'], $this->ana);
        $this->assertSame('no_contactar', $l->fresh()->estado);
        $this->assertTrue(ListaNegra::bloqueado('922222222'));

        // Lista negra, horario e intentos.
        $l2 = Lead::create(['campana_id' => $c->id, 'nombre' => 'Bloq', 'telefono' => '922222222', 'agente_id' => $this->a1->id, 'estado' => 'asignado']);
        $this->falla(fn () => $cc->llamar($this->a1->fresh(), $l2, $this->ana), 'lead');
        $this->assertSame('no_contactar', $l2->fresh()->estado);
        $l3 = Lead::create(['campana_id' => $c->id, 'nombre' => 'Tarde', 'telefono' => '+56977777777', 'agente_id' => $this->a1->id, 'estado' => 'asignado', 'intentos' => 2]);
        $this->falla(fn () => $cc->llamar($this->a1->fresh(), $l3, $this->ana), 'lead');                 // máximo de intentos
        $l3->update(['intentos' => 0]);
        $this->travelTo(now()->setTime(20, 0));
        $this->falla(fn () => $cc->llamar($this->a1->fresh(), $l3, $this->ana), 'lead');                 // fuera de horario
    }

    public function test_entrante_por_cola_y_webhook_firmado(): void
    {
        $cc = app(CentroContacto::class);
        $cola = Cola::create(['nombre' => 'Ventas', 'estrategia' => 'menor_carga']);
        NumeroEntrante::create(['numero' => '+56222345678', 'destino_tipo' => 'cola', 'destino_id' => $cola->id]);
        $this->a1->update(['cola_id' => $cola->id]);
        $this->a2->update(['cola_id' => $cola->id]);

        $ll = $cc->entrante('+56222345678', '+56988888888', 'pbx-1');
        $this->assertSame('en_cola', $ll->estado);                                              // nadie disponible
        $cc->cambiarEstado($this->a2, 'disponible');
        $ll = $ll->fresh();
        $this->assertSame($this->a2->id, $ll->agente_id);
        $this->assertSame('marcando', $ll->estado);

        config(['services.pbx.webhook_secret' => 'secreto-de-prueba']);
        $cuerpo = json_encode(['empresa' => $this->empresa->id, 'tipo' => 'contestada', 'pbx_id' => 'pbx-1']);
        $t = time();
        $this->call('POST', '/api/pbx/eventos', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PBX_MOMENTO' => $t,
            'HTTP_X_PBX_FIRMA' => 'malo'], $cuerpo)->assertStatus(401);
        $this->call('POST', '/api/pbx/eventos', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PBX_MOMENTO' => $t - 600,
            'HTTP_X_PBX_FIRMA' => hash_hmac('sha256', ($t - 600).'.'.$cuerpo, 'secreto-de-prueba')], $cuerpo)->assertStatus(401);
        $this->call('POST', '/api/pbx/eventos', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PBX_MOMENTO' => $t,
            'HTTP_X_PBX_FIRMA' => hash_hmac('sha256', $t.'.'.$cuerpo, 'secreto-de-prueba')], $cuerpo)->assertOk();
        $this->assertSame('en_curso', app(Tenancy::class)->como($this->empresa, fn () => Llamada::where('pbx_id', 'pbx-1')->value('estado')));

        app(Tenancy::class)->establecer($this->empresa);
        $tablero = $cc->tablero();
        $this->assertSame('•••••••8888', $tablero['llamadas'][0]['numero']);                   // enmascarado en el wallboard
        $this->assertSame(1, $tablero['hoy']['entrantes']);
    }

    public function test_calidad_y_grabaciones_con_permiso_y_registro(): void
    {
        $cc = app(CentroContacto::class);
        $c = $this->campana();
        $l = Lead::create(['campana_id' => $c->id, 'nombre' => 'Pedro', 'telefono' => '+56922222222', 'agente_id' => $this->a1->id, 'estado' => 'asignado']);
        $cc->cambiarEstado($this->a1, 'disponible');
        $ll = $cc->llamar($this->a1->fresh(), $l, $this->ana);
        $cc->evento($ll->pbx_id, 'contestada');
        $this->travel(60)->seconds();
        $cc->evento($ll->pbx_id, 'terminada');

        $saludo = CriterioCalidad::create(['nombre' => 'Saluda y se identifica', 'peso' => 20]);
        $oferta = CriterioCalidad::create(['nombre' => 'Presenta la oferta', 'peso' => 60]);
        $datos = CriterioCalidad::create(['nombre' => 'Respeta protección de datos', 'peso' => 20, 'critico' => true]);
        $q = app(Calidad::class);
        $this->falla(fn () => $q->evaluar($ll, [], null, $this->ana), 'llamada');               // la propia
        $a = $q->evaluar($ll->fresh(), [$saludo->id => true, $oferta->id => false, $datos->id => true], 'Faltó la oferta', $this->supervisor);
        $this->assertSame(40.0, $a->puntaje);
        $this->falla(fn () => $q->evaluar($ll->fresh(), [], null, $this->supervisor), 'llamada'); // ya evaluada

        // Grabaciones: el superadministrador NO escucha sin el permiso explícito; el supervisor sí, con motivo y registro.
        $admin = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        $this->entrar($admin)->postJson("/ventas/grabaciones/{$ll->id}", ['motivo' => 'Revisión', 'accion' => 'escuchar'])->assertForbidden();
        $this->entrar($this->supervisor)->postJson("/ventas/grabaciones/{$ll->id}", ['motivo' => 'x', 'accion' => 'escuchar'])->assertStatus(422);
        $url = $this->entrar($this->supervisor)->postJson("/ventas/grabaciones/{$ll->id}", ['motivo' => 'Reclamo del cliente', 'accion' => 'escuchar'])->assertOk()->json('url');
        $this->entrar($this->supervisor)->get($url)->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->entrar($this->supervisor)->get($url)->assertForbidden();                         // enlace de un solo uso
        app(Tenancy::class)->establecer($this->empresa);
        $this->assertSame(1, GrabacionAcceso::count());
        $this->esperarRechazoBd(fn () => DB::table('grabacion_accesos')->update(['motivo' => 'borrado']));

        // El secreto del proveedor se cifra y nunca vuelve a la pantalla.
        $this->entrar($this->supervisor)->post('/configuracion/telefonia/proveedores', ['nombre' => 'Asterisk', 'tipo' => 'asterisk', 'host' => 'pbx.local', 'puerto' => 5038,
            'protocolo' => 'ami', 'usuario_servicio' => 'erp', 'secreto' => 'muy-secreto-123', 'activo' => true]);
        app(Tenancy::class)->establecer($this->empresa);
        $p = ProveedorIp::first();
        if ($p) {
            $this->assertNotSame('muy-secreto-123', $p->getRawOriginal('secreto_cifrado'));
        }
        $admin2 = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        $this->entrar($admin2)->post('/configuracion/telefonia/proveedores', ['nombre' => 'Asterisk', 'tipo' => 'asterisk', 'host' => 'pbx.local', 'puerto' => 5038,
            'protocolo' => 'ami', 'usuario_servicio' => 'erp', 'secreto' => 'muy-secreto-123', 'activo' => true])->assertRedirect();
        $pagina = $this->entrar($admin2)->get('/configuracion/telefonia/proveedores')->assertOk();
        $this->assertStringNotContainsString('muy-secreto-123', $pagina->getContent());
        $this->assertStringNotContainsString('secreto_cifrado', $pagina->getContent());
    }

    public function test_pantallas_cargan(): void
    {
        $admin = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        Agente::create(['user_id' => $admin->id, 'extension' => '200', 'estado' => 'desconectado']);
        $c = $this->campana();
        foreach (['/ventas/agentes', '/ventas/consola', '/ventas/campanas', '/ventas/campanas/crear', "/ventas/campanas/{$c->id}", "/ventas/campanas/{$c->id}/editar", '/ventas/asignacion',
            '/ventas/guiones', '/ventas/tiempo-real', '/ventas/panel', '/ventas/auditoria', '/ventas/grabaciones', '/configuracion/telefonia/proveedores',
            '/configuracion/telefonia/numeros', '/configuracion/telefonia/ivr', '/configuracion/telefonia/lista-negra'] as $ruta) {
            $this->assertSame(200, $this->entrar($admin)->get($ruta)->status(), $ruta);
        }
        $this->entrar($this->ana)->get('/ventas/campanas')->assertForbidden();                  // el agente no administra campañas
    }
}
