<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\ListaNegra;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Marketing\Models\AdsCampana;
use App\Modules\Marketing\Models\AdsLead;
use App\Modules\Marketing\Publicidad;
use App\Modules\Mensajeria\Linkedin\BandejaLinkedin;
use App\Modules\Mensajeria\Models\LinkedinConversacion;
use App\Modules\Mensajeria\Models\LinkedinMensaje;
use App\Modules\Mensajeria\Models\Mensaje;
use App\Modules\Mensajeria\Models\Plantilla;
use App\Modules\Mensajeria\Plantillas;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MetaAdsLinkedinTest extends TestCase
{
    private Empresa $empresa;

    private User $admin;

    private User $ejecutiva;

    private Campana $campana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->empresa();
        $this->admin = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        $this->ejecutiva = $this->usuario($this->empresa, 'Ejecutiva comercial');
        $this->tenant();
        $this->campana = Campana::create(['nombre' => 'Web y redes', 'tipo' => 'saliente', 'estado' => 'activa', 'fecha_inicio' => today(), 'asignacion' => 'round_robin',
            'horario' => ['dias' => [1, 2, 3, 4, 5, 6, 7], 'desde' => '00:00', 'hasta' => '23:59'], 'creado_por' => $this->admin->id]);
    }

    private function tenant(): void
    {
        app(Tenancy::class)->establecer($this->empresa);
    }

    private function activar(string $tipo): void
    {
        Integracion::updateOrCreate(['tipo' => $tipo], ['activa' => true]);
    }

    public function test_meta_ads_sincroniza_metricas_e_importa_prospectos_sin_duplicar(): void
    {
        $pub = app(Publicidad::class);
        try {
            $pub->sincronizar();
            $this->fail('Sin integración activa no sincroniza.');
        } catch (ValidationException) {
        }
        $this->activar('meta_ads');

        // Primera pasada sin destino: todo queda registrado como "sin destino".
        $r = $pub->sincronizar();
        $this->assertSame(3, $r['campanas']);
        $this->assertGreaterThan(0, $r['recibidos']);
        $this->assertSame($r['recibidos'], $r['sin_destino'] + $r['invalidos']);
        $this->assertSame(0, Lead::count());

        // Al asignar destino se reprocesan; la lista negra se respeta.
        $camp = AdsCampana::where('externo_id', 'act_sim_120001')->firstOrFail();
        $vetado = AdsLead::where('ads_campana_id', $camp->id)->orderBy('id')->firstOrFail();
        ListaNegra::create(['numero' => $vetado->telefono, 'motivo' => 'Pidió no ser contactado', 'creado_por' => $this->admin->id]);
        $pub->destino($camp, $this->campana);
        $n = $pub->reprocesar($camp->fresh());
        $this->assertGreaterThan(0, $n);
        $this->assertSame($n, Lead::where('campana_id', $this->campana->id)->count());
        $this->assertSame(0, AdsLead::where('ads_campana_id', $camp->id)->where('resultado', 'sin_destino')->count());
        $this->assertSame('bloqueado', $vetado->fresh()->resultado);
        $this->assertSame('nuevo', Lead::where('campana_id', $this->campana->id)->value('estado'));

        // Una segunda sincronización no trae nada repetido.
        $antes = AdsLead::count();
        $r2 = $pub->sincronizar();
        $this->assertSame(0, $r2['recibidos']);
        $this->assertSame($antes, AdsLead::count());
        $this->assertSame('ok', Integracion::de('meta_ads')->salud);

        // La BD exige que un importado tenga lead.
        $this->esperarRechazoBd(fn () => AdsLead::create(['ads_campana_id' => $camp->id, 'externo_id' => 'x', 'nombre' => 'X', 'telefono' => '56911112222', 'resultado' => 'importado', 'recibido_at' => now()]));

        // Pantalla: el teléfono va enmascarado; la ejecutiva no la ve.
        $this->entrar($this->admin)->get('/ventas/meta-ads')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Marketing/MetaAds')->has('campanas', 3)->where('prospectos.0.telefono', fn ($t) => str_starts_with($t, '•••• ')));
        $this->entrar($this->ejecutiva)->get('/ventas/meta-ads')->assertForbidden();
        $this->entrar($this->admin)->post('/ventas/meta-ads/sincronizar')->assertSessionHasNoErrors();
    }

    public function test_plantillas_pasan_por_revision_de_meta(): void
    {
        $pl = app(Plantillas::class);
        $ok = $pl->guardar(['nombre' => 'despacho_listo', 'idioma' => 'es', 'categoria' => 'utility', 'cuerpo' => 'Hola {{1}}, tu pedido {{2}} va en camino. Gracias por preferirnos.'], $this->admin);
        $promo = $pl->guardar(['nombre' => 'cyber_promo', 'idioma' => 'es', 'categoria' => 'utility', 'cuerpo' => 'Hola {{1}}, aprovecha 20% de descuento esta semana en cemento.'], $this->admin);
        $this->assertSame('borrador', $ok->estado);

        // Variables no consecutivas y llaves sueltas se rechazan antes de llegar a Meta.
        foreach (['Hola {{1}}, pedido {{3}} listo.', 'Hola {{nombre}}, tu pedido está listo.'] as $malo) {
            try {
                $pl->guardar(['nombre' => 'mala', 'idioma' => 'es', 'categoria' => 'utility', 'cuerpo' => $malo], $this->admin);
                $this->fail('Debía rechazar: '.$malo);
            } catch (ValidationException) {
            }
        }

        // Sin WhatsApp activo no se envía a revisión.
        try {
            $pl->enviarARevision($ok);
            $this->fail('Requiere integración.');
        } catch (ValidationException) {
        }
        $this->activar('whatsapp');
        $pl->enviarARevision($ok);
        $pl->enviarARevision($promo);
        $this->assertSame(2, $pl->actualizarPendientes());
        $this->assertSame('aprobada', $ok->fresh()->estado);
        $this->assertSame('rechazada', $promo->fresh()->estado);
        $this->assertStringContainsString('promocional', $promo->fresh()->motivo_rechazo);

        // La aprobada no se edita; la rechazada se corrige y vuelve a borrador.
        try {
            $pl->guardar(['nombre' => 'despacho_listo', 'idioma' => 'es', 'categoria' => 'utility', 'cuerpo' => 'Otro texto {{1}} de prueba.'], $this->admin, $ok->fresh());
            $this->fail('Aprobada no se edita.');
        } catch (ValidationException) {
        }
        $corr = $pl->guardar(['nombre' => 'cyber_promo', 'idioma' => 'es', 'categoria' => 'marketing', 'cuerpo' => 'Hola {{1}}, aprovecha 20% de descuento esta semana en cemento.'], $this->admin, $promo->fresh());
        $this->assertSame(['borrador', null], [$corr->estado, $corr->motivo_rechazo]);

        // Nombre con el formato de Meta, por HTTP y en la BD; una usada no se elimina.
        $this->entrar($this->admin)->post('/ventas/whatsapp-plantillas', ['nombre' => 'Con Mayúsculas', 'idioma' => 'es', 'categoria' => 'utility', 'cuerpo' => 'Texto de prueba largo.'])->assertSessionHasErrors('nombre');
        $this->tenant();
        $this->esperarRechazoBd(fn () => Plantilla::create(['nombre' => 'Mal Nombre', 'cuerpo' => 'x']));
        $conv = \App\Modules\Mensajeria\Models\Conversacion::create(['telefono' => '56911112222']);
        Mensaje::create(['conversacion_id' => $conv->id, 'direccion' => 'saliente', 'tipo' => 'plantilla', 'cuerpo' => 'x', 'plantilla_id' => $ok->id, 'estado' => 'enviado']);
        $this->entrar($this->admin)->delete("/ventas/whatsapp-plantillas/{$ok->id}")->assertSessionHasErrors('plantilla');
        $this->entrar($this->admin)->get('/ventas/whatsapp-plantillas')->assertOk()->assertInertia(fn ($p) => $p->component('Mensajeria/Plantillas')->has('plantillas', 2));
        $this->entrar($this->ejecutiva)->post('/ventas/whatsapp-plantillas', ['nombre' => 'x_y', 'idioma' => 'es', 'categoria' => 'utility', 'cuerpo' => 'Texto de prueba largo.'])->assertForbidden();
    }

    public function test_bandeja_linkedin_sincroniza_responde_por_cola_y_crea_prospecto(): void
    {
        $this->activar('linkedin');
        $bandeja = app(BandejaLinkedin::class);
        $n = $bandeja->sincronizar();
        $this->assertSame(6, $n);
        $this->assertSame(3, LinkedinConversacion::count());
        $this->assertSame(0, $bandeja->sincronizar());   // idempotente

        $c = LinkedinConversacion::where('externo_id', 'urn:li:conv:sim-c3')->firstOrFail();
        $this->assertSame(3, $c->no_leidos);

        // La ejecutiva abre (marca leída), responde (cola síncrona en pruebas) y queda asignada.
        $this->entrar($this->ejecutiva)->get("/ventas/linkedin?c={$c->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Mensajeria/Linkedin')->has('actual.mensajes', 3));
        $this->entrar($this->ejecutiva)->post("/ventas/linkedin/{$c->id}/texto", ['texto' => 'Hola Andrés, te envío la cotización hoy.'])->assertSessionHasNoErrors();
        $this->tenant();
        $c->refresh();
        $this->assertSame([0, $this->ejecutiva->id], [$c->no_leidos, $c->asignado_a]);
        $this->assertSame('enviado', LinkedinMensaje::where('conversacion_id', $c->id)->where('direccion', 'saliente')->value('estado'));

        // Un envío que LinkedIn rechaza termina "fallido" tras los reintentos.
        $malo = LinkedinConversacion::create(['externo_id' => 'urn:li:conv:sim-x', 'nombre' => 'Rechazo']);
        Integracion::where('tipo', 'linkedin')->update(['reintentos' => 0]);
        $bandeja->enviar($malo, 'Hola', $this->admin);
        $this->assertSame('fallido', LinkedinMensaje::where('conversacion_id', $malo->id)->value('estado'));

        // Crear prospecto: pide teléfono y no duplica.
        $this->entrar($this->ejecutiva)->post("/ventas/linkedin/{$c->id}/lead", ['campana_id' => $this->campana->id, 'telefono' => '12'])->assertSessionHasErrors('telefono');
        $this->entrar($this->ejecutiva)->post("/ventas/linkedin/{$c->id}/lead", ['campana_id' => $this->campana->id, 'telefono' => '+56 9 8765 4321', 'comuna' => 'Ñuñoa'])->assertSessionHasNoErrors();
        $this->tenant();
        $lead = Lead::findOrFail($c->fresh()->lead_id);
        $this->assertSame(['Andrés Carrasco', '+56987654321', 'nuevo'], [$lead->nombre, $lead->telefono, $lead->estado]);
        $this->entrar($this->ejecutiva)->post("/ventas/linkedin/{$c->id}/lead", ['campana_id' => $this->campana->id, 'telefono' => '+56 9 1111 2222'])->assertSessionHasErrors('telefono');

        // Visibilidad: otra ejecutiva no abre una conversación ajena.
        $otra = $this->usuario($this->empresa, 'Ejecutiva comercial');
        $this->entrar($otra)->get("/ventas/linkedin?c={$c->id}")->assertForbidden();
        $this->entrar($otra)->get('/ventas/linkedin')->assertOk()->assertInertia(fn ($p) => $p->has('conversaciones', 2));
    }

    public function test_tarea_programada_sincroniza_solo_empresas_con_la_integracion_activa(): void
    {
        $this->activar('linkedin');
        $otra = $this->empresa();
        Artisan::call('integraciones:sincronizar', ['tipo' => 'linkedin']);
        $this->assertStringContainsString('Empresas procesadas: 1', Artisan::output());
        $this->tenant();
        $this->assertSame(3, LinkedinConversacion::count());
        app(Tenancy::class)->como($otra, fn () => $this->assertSame(0, LinkedinConversacion::count()));
    }
}
