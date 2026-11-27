<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comercial\ListasPrecios;
use App\Modules\Comercial\Models\Categoria;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\ListaPrecio;
use App\Modules\Comercial\Models\ListaPrecioItem;
use App\Modules\Comercial\Models\Meta;
use App\Modules\Comercial\Models\NivelDescuento;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Comercial\Models\ReglaDescuento;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Facturacion\Sii\ProveedorDteSimulado;
use App\Modules\Ventas\Cotizaciones;
use App\Support\Api\OpenApi;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PreciosDescuentosMetasTest extends TestCase
{
    private Empresa $empresa;

    private User $admin;

    private User $ejecutiva;

    private User $finanzas;

    private User $gerente;

    private Producto $cemento;

    protected function setUp(): void
    {
        parent::setUp();
        ProveedorDteSimulado::caer(false);
        $this->empresa = $this->empresa();
        $this->admin = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        $this->ejecutiva = $this->usuario($this->empresa, 'Ejecutiva comercial');
        $this->finanzas = $this->usuario($this->empresa, 'Analista de Finanzas');
        $this->gerente = $this->usuario($this->empresa, 'Gerente');
        $this->tenant();
        $p = app(Parametros::class);
        $p->proponer('modulos.facturacion', true, 'empresa', 'Pruebas', $this->admin);
        $p->proponer('inventario.permitir_negativo', true, 'empresa', 'Pruebas', $this->admin);
        FolioRango::create(['tipo_dte' => 33, 'desde' => 1, 'hasta' => 100, 'siguiente' => 1]);
        $cat = Categoria::create(['nombre' => 'Cementos']);
        $this->cemento = Producto::create(['sku' => 'CEM', 'nombre' => 'Cemento 25 kg', 'categoria_id' => $cat->id, 'precio_neto' => '5000', 'precio_mayorista' => '4600']);
    }

    private function tenant(): void
    {
        app(Tenancy::class)->establecer($this->empresa);
    }

    private function cliente(string $lista, string $rut = '76543210-3'): Cliente
    {
        return Cliente::create(['rut' => $rut, 'razon_social' => 'Cliente '.$lista, 'giro' => 'Construcción', 'direccion' => 'Calle 1', 'comuna' => 'Maipú', 'lista_precio' => $lista]);
    }

    private function cotizar(Cliente $c, array $linea, ?User $quien = null)
    {
        return app(Cotizaciones::class)->guardar(['cliente_id' => $c->id, 'forma_pago' => 'contado', 'lineas' => [['producto_id' => $this->cemento->id] + $linea]], $quien ?? $this->ejecutiva);
    }

    public function test_el_precio_sale_de_la_lista_del_cliente_con_su_regla_y_vigencia(): void
    {
        $listas = app(ListasPrecios::class);
        $this->assertSame(['meson', 'mayorista'], $listas->codigos());

        // Por HTTP: lista "constructoras" 12 % bajo mesón y "obras" con precio propio.
        $this->entrar($this->admin)->post('/configuracion/listas-precios', ['codigo' => 'constructor', 'nombre' => 'Constructoras', 'regla' => 'porcentaje', 'porcentaje' => 12, 'activa' => true])->assertSessionHasNoErrors();
        $this->entrar($this->admin)->post('/configuracion/listas-precios', ['codigo' => 'obras', 'nombre' => 'Obras', 'regla' => 'propio', 'activa' => true])->assertSessionHasNoErrors();
        $this->entrar($this->admin)->post('/configuracion/listas-precios', ['codigo' => 'Con Espacio', 'nombre' => 'X', 'regla' => 'propio'])->assertSessionHasErrors('codigo');
        $this->tenant();
        $obras = ListaPrecio::where('codigo', 'obras')->firstOrFail();
        $this->entrar($this->admin)->post("/configuracion/listas-precios/{$obras->id}/items", ['producto_id' => $this->cemento->id, 'precio' => 4300])->assertSessionHasNoErrors();
        $this->tenant();

        $listas = app(ListasPrecios::class);
        $this->assertSame('5000', $listas->precio($this->cemento, 'meson'));
        $this->assertSame('4600', $listas->precio($this->cemento, 'mayorista'));
        $this->assertSame('4400', $listas->precio($this->cemento, 'constructor'));
        $this->assertSame('4300', $listas->precio($this->cemento, 'obras'));
        $this->assertSame('5000', $listas->precio($this->cemento, 'inexistente'));

        // La cotización toma el precio de la lista del cliente (validada contra las listas de la empresa).
        $this->entrar($this->admin)->post('/configuracion/clientes', ['rut' => '76543210-3', 'razon_social' => 'Andes', 'lista_precio' => 'constructor', 'tiene_credito' => false, 'activo' => true])->assertSessionHasNoErrors();
        $this->entrar($this->admin)->post('/configuracion/clientes', ['rut' => '11111111-1', 'razon_social' => 'Otro', 'lista_precio' => 'falsa', 'tiene_credito' => false, 'activo' => true])->assertSessionHasErrors('lista_precio');
        $this->tenant();
        $andes = Cliente::where('rut', '76543210-3')->firstOrFail();
        $cot = $this->cotizar($andes, ['cantidad' => '10']);
        $this->assertSame(44000, $cot->monto_neto);
        $this->assertSame('borrador', $cot->estado);

        // Fuera de vigencia: mesón. Y no se borra ni desactiva una lista con clientes.
        $lista = ListaPrecio::where('codigo', 'constructor')->firstOrFail();
        $lista->update(['vigente_hasta' => now()->subDay()->toDateString(), 'vigente_desde' => now()->subMonth()->toDateString()]);
        $this->assertSame(50000, $this->cotizar($andes, ['cantidad' => '10'])->monto_neto);
        $this->entrar($this->admin)->delete("/configuracion/listas-precios/{$lista->id}")->assertSessionHasErrors('lista');
        $this->entrar($this->admin)->put("/configuracion/listas-precios/{$lista->id}", ['nombre' => 'Constructoras', 'regla' => 'porcentaje', 'porcentaje' => 12, 'activa' => false])->assertSessionHasErrors('activa');
        $this->tenant();
        $meson = ListaPrecio::where('codigo', 'meson')->firstOrFail();
        $this->entrar($this->admin)->delete("/configuracion/listas-precios/{$meson->id}")->assertSessionHasErrors('lista');

        // La BD exige coherencia de la regla.
        $this->tenant();
        $this->esperarRechazoBd(fn () => ListaPrecio::create(['codigo' => 'malo', 'nombre' => 'Malo', 'regla' => 'porcentaje', 'porcentaje' => null]));
        $this->esperarRechazoBd(fn () => ListaPrecio::create(['codigo' => 'base2', 'nombre' => 'Base', 'regla' => 'base']));

        $this->entrar($this->admin)->get('/configuracion/listas-precios?lista='.$obras->id)->assertOk()
            ->assertInertia(fn ($p) => $p->component('Configuracion/ListasPrecios')->has('listas', 4)->where('seleccionada.items.0.precio', '4300.000000'));
        $this->entrar($this->admin)->get('/configuracion/listas-precios/simular?producto_id='.$this->cemento->id)->assertOk()->assertJsonPath('precios.2.precio', '5000')->assertJsonPath('precios.2.vigente', false)->assertJsonPath('precios.3.precio', '4300');
    }

    public function test_reglas_automaticas_y_escalones_de_aprobacion_por_rol(): void
    {
        $mayorista = $this->cliente('mayorista');
        $this->entrar($this->admin)->post('/configuracion/descuentos/reglas', ['nombre' => 'Volumen cemento', 'categoria_id' => $this->cemento->categoria_id, 'lista_precio' => 'mayorista', 'cantidad_minima' => 50, 'descuento_pct' => 15, 'activa' => true])->assertSessionHasNoErrors();
        $fin = Rol::where('nombre', 'Analista de Finanzas')->value('id');
        $ger = Rol::where('nombre', 'Gerente')->value('id');
        $this->entrar($this->admin)->post('/configuracion/descuentos/niveles', ['hasta_pct' => 5, 'rol_id' => $fin])->assertSessionHasErrors('hasta_pct'); // bajo el máximo (10 %)
        $this->entrar($this->admin)->post('/configuracion/descuentos/niveles', ['hasta_pct' => 20, 'rol_id' => $fin])->assertSessionHasNoErrors();
        $this->entrar($this->admin)->post('/configuracion/descuentos/niveles', ['hasta_pct' => 40, 'rol_id' => $ger])->assertSessionHasNoErrors();
        $this->tenant();

        // Sin descuento indicado y 50 unidades: la regla aplica 15 % y no pide aprobación.
        $cot = $this->cotizar($mayorista, ['cantidad' => '50']);
        $this->assertSame('15', rtrim(rtrim((string) $cot->lineas[0]->descuento_pct, '0'), '.'));
        $this->assertSame('borrador', $cot->estado);
        $this->assertNull($cot->nivel_descuento_pct);
        // Con 49 unidades no calza; un 0 explícito tampoco recibe la regla.
        $this->assertSame('0', rtrim(rtrim((string) $this->cotizar($mayorista, ['cantidad' => '49'])->lineas[0]->descuento_pct, '0'), '.'));
        $this->assertSame('0', rtrim(rtrim((string) $this->cotizar($mayorista, ['cantidad' => '50', 'descuento_pct' => '0'])->lineas[0]->descuento_pct, '0'), '.'));
        // Hasta el % de la regla no requiere aprobación aunque supere el máximo.
        $this->assertSame('borrador', $this->cotizar($mayorista, ['cantidad' => '60', 'descuento_pct' => '15'])->estado);

        // 18 %: escalón 1 (finanzas o superior). 30 %: escalón 2 (solo gerente).
        $c18 = $this->cotizar($mayorista, ['cantidad' => '60', 'descuento_pct' => '18']);
        $this->assertSame('por_aprobar', $c18->estado);
        $this->assertSame('18.00', (string) $c18->nivel_descuento_pct);
        $c30 = $this->cotizar($mayorista, ['cantidad' => '10', 'descuento_pct' => '30']);
        try {
            app(Cotizaciones::class)->aprobar($c30, $this->finanzas);
            $this->fail('Finanzas no cubre el 30 %.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Gerente', $e->getMessage());
        }
        app(Cotizaciones::class)->aprobar($c30, $this->gerente);
        app(Cotizaciones::class)->aprobar($c18, $this->gerente); // un escalón superior también aprueba
        $this->assertSame('borrador', $c18->fresh()->estado);

        // Sobre el último escalón no se acepta; tampoco en un documento directo.
        try {
            $this->cotizar($mayorista, ['cantidad' => '10', 'descuento_pct' => '45']);
            $this->fail('45 % supera el tope.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('40 %', $e->getMessage());
        }
        $docs = app(Documentos::class);
        $doc = $docs->guardar(['tipo_dte' => 33, 'cliente_id' => $mayorista->id, 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '2', 'descuento_pct' => '25']]], $this->ejecutiva);
        $this->assertSame(['por_aprobar', '25.00'], [$doc->estado, (string) $doc->nivel_descuento_pct]);
        $this->expectException(ValidationException::class);
        $docs->aprobar($doc, $this->finanzas);
    }

    public function test_quien_edito_un_borrador_ajeno_no_lo_aprueba(): void
    {
        $c = $this->cliente('meson');
        $cot = $this->cotizar($c, ['cantidad' => '1', 'descuento_pct' => '10']);
        // Finanzas sube el descuento de la cotización de la ejecutiva y queda como editor.
        $cot = app(Cotizaciones::class)->guardar(['cliente_id' => $c->id, 'forma_pago' => 'contado', 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '1', 'descuento_pct' => '60']]], $this->finanzas, $cot);
        $this->assertSame('por_aprobar', $cot->estado);
        try {
            app(Cotizaciones::class)->aprobar($cot, $this->finanzas);
            $this->fail('Quien editó no aprueba.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('editó', $e->getMessage());
        }
        app(Cotizaciones::class)->aprobar($cot, $this->gerente);
        $this->assertSame('borrador', $cot->fresh()->estado);

        $docs = app(Documentos::class);
        $doc = $docs->guardar(['tipo_dte' => 33, 'cliente_id' => $c->id, 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '1']]], $this->ejecutiva);
        $doc = $docs->guardar(['tipo_dte' => 33, 'cliente_id' => $c->id, 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '1', 'descuento_pct' => '60']]], $this->finanzas, $doc);
        $this->assertSame('por_aprobar', $doc->estado);
        try {
            $docs->aprobar($doc, $this->finanzas);
            $this->fail('Quien editó no aprueba.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('editó', $e->getMessage());
        }
        $docs->aprobar($doc, $this->gerente);
        $this->assertNotSame('por_aprobar', $doc->fresh()->estado);
    }

    public function test_sin_escalones_aprueba_cualquiera_con_permiso_y_la_pantalla_muestra_la_escalera(): void
    {
        $c = $this->cliente('meson');
        $cot = $this->cotizar($c, ['cantidad' => '1', 'descuento_pct' => '60']);
        $this->assertSame('60.00', (string) $cot->nivel_descuento_pct);
        app(Cotizaciones::class)->aprobar($cot, $this->finanzas);
        $this->assertSame('borrador', $cot->fresh()->estado);

        // La vista previa del formulario devuelve el descuento que aplicó la regla.
        ReglaDescuento::create(['nombre' => 'Todo 5', 'cantidad_minima' => 0, 'descuento_pct' => 5, 'creado_por' => $this->admin->id]);
        $this->entrar($this->ejecutiva)->postJson('/ventas/cotizaciones/calcular', ['cliente_id' => $c->id, 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => 1, 'descuento_pct' => '']]])
            ->assertOk()->assertJsonPath('lineas.0.descuento_pct', '5');
        $this->entrar($this->admin)->get('/configuracion/descuentos')->assertOk()->assertInertia(fn ($p) => $p->component('Configuracion/Descuentos')->where('maximo', '10')->has('reglas', 1));
        $this->entrar($this->ejecutiva)->get('/configuracion/descuentos')->assertForbidden();
    }

    public function test_metas_calculan_avance_con_documentos_emitidos_y_cotizaciones(): void
    {
        $periodo = now()->format('Y-m');
        $c = $this->cliente('meson');
        $docs = app(Documentos::class);
        // Ejecutiva: una factura de 10 × 5.000 = 50.000 neto. Gerente: 4 × 5.000 = 20.000.
        $docs->emitir($docs->guardar(['tipo_dte' => 33, 'cliente_id' => $c->id, 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '10']]], $this->ejecutiva), $this->ejecutiva);
        $docs->emitir($docs->guardar(['tipo_dte' => 33, 'cliente_id' => $c->id, 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '4']]], $this->gerente), $this->gerente);
        $this->cotizar($c, ['cantidad' => '1']);
        $this->cotizar($c, ['cantidad' => '2']);

        $m = fn (array $d) => $this->entrar($this->admin)->post('/configuracion/metas', $d + ['periodo' => $periodo]);
        $m(['indicador' => 'venta_neta', 'alcance' => 'empresa', 'objetivo' => 100000])->assertSessionHasNoErrors();
        $m(['indicador' => 'venta_neta', 'alcance' => 'vendedor', 'user_id' => $this->ejecutiva->id, 'objetivo' => 40000])->assertSessionHasNoErrors();
        $m(['indicador' => 'cotizaciones', 'alcance' => 'vendedor', 'user_id' => $this->ejecutiva->id, 'objetivo' => 8])->assertSessionHasNoErrors();
        $m(['indicador' => 'venta_neta', 'alcance' => 'empresa', 'objetivo' => 5])->assertSessionHasErrors('indicador'); // repetida
        $m(['indicador' => 'documentos', 'alcance' => 'local', 'objetivo' => 5])->assertSessionHasErrors('local_id');
        $m(['indicador' => 'documentos', 'alcance' => 'vendedor', 'user_id' => $this->usuario()->id, 'objetivo' => 5])->assertSessionHasErrors('user_id');

        $r = $this->entrar($this->admin)->get('/configuracion/metas?periodo='.$periodo)->assertOk();
        $metas = collect($r->viewData('page')['props']['metas'])->keyBy(fn ($x) => $x['indicador'].'.'.$x['alcance']);
        $this->assertSame([70000, 70.0], [$metas['venta_neta.empresa']['logrado'], $metas['venta_neta.empresa']['pct']]);
        $this->assertSame([50000, 125.0], [$metas['venta_neta.vendedor']['logrado'], $metas['venta_neta.vendedor']['pct']]);
        $this->assertSame(2, $metas['cotizaciones.vendedor']['logrado']);

        // Copiar al mes siguiente no duplica y la BD exige el alcance coherente.
        $sig = now()->addMonthNoOverflow()->format('Y-m');
        $this->entrar($this->admin)->post('/configuracion/metas/copiar', ['periodo' => $sig])->assertSessionHasNoErrors();
        $this->entrar($this->admin)->post('/configuracion/metas/copiar', ['periodo' => $sig])->assertSessionHasNoErrors();
        $this->tenant();
        $this->assertSame(3, Meta::whereDate('periodo', $sig.'-01')->count());
        $this->esperarRechazoBd(fn () => Meta::create(['periodo' => now()->startOfMonth()->toDateString(), 'indicador' => 'documentos', 'alcance' => 'empresa', 'user_id' => $this->ejecutiva->id, 'objetivo' => 1, 'creado_por' => $this->admin->id]));
        $this->entrar($this->ejecutiva)->get('/configuracion/metas')->assertForbidden();
    }

    public function test_documentacion_openapi_cubre_todas_las_rutas_de_la_api(): void
    {
        $api = app(OpenApi::class);
        $sin = collect($api->endpoints())->reject(fn ($e) => $e['documentada'])->map(fn ($e) => $e['metodo'].' '.$e['ruta'])->all();
        $this->assertSame([], $sin, 'Rutas de la API sin documentar.');

        $spec = $api->generar();
        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertSame(['informe-ventas:read'], $spec['paths']['/informe-ventas/resumen']['get']['security'][0]['token']);
        $this->assertArrayHasKey('requestBody', $spec['paths']['/dtes']['post']);

        $this->entrar($this->admin)->get('/configuracion/documentacion-api')->assertOk()->assertInertia(fn ($p) => $p->component('Configuracion/DocumentacionApi')->has('endpoints', count($api->endpoints())));
        $this->entrar($this->admin)->get('/configuracion/documentacion-api/openapi.json')->assertOk()->assertJsonPath('paths./dtes.post.responses.201.description', 'Correcto');
        $this->entrar($this->ejecutiva)->get('/configuracion/documentacion-api')->assertForbidden();
    }
}
