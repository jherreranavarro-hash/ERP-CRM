<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Facturacion\Sii\ProveedorDteSimulado;
use App\Modules\Inventario\Despachos;
use App\Modules\Inventario\Devoluciones;
use App\Modules\Inventario\Models\Despacho;
use App\Modules\Inventario\Models\HistorialPrecio;
use App\Modules\Inventario\Models\MovimientoStock;
use App\Modules\Inventario\Models\Stock;
use App\Modules\Inventario\Precios;
use App\Modules\Inventario\Recepciones;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PreciosDespachosDevolucionesTest extends TestCase
{
    private const FIRMA = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Empresa $empresa;

    private User $vendedor;

    private User $jefe;

    private Local $local;

    private Producto $cemento;

    private Producto $fierro;

    private Cliente $cliente;

    private Documentos $docs;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        ProveedorDteSimulado::caer(false);
        $this->empresa = $this->empresa();
        $this->vendedor = $this->usuario($this->empresa, 'Ejecutiva comercial', con2fa: false);
        $this->jefe = $this->usuario($this->empresa, 'Gerente');
        app(Tenancy::class)->establecer($this->empresa);
        app(Parametros::class)->proponer('modulos.facturacion', true, 'empresa', 'Pruebas', $this->jefe);
        foreach ([33, 39, 61] as $tipo) {
            FolioRango::create(['tipo_dte' => $tipo, 'desde' => 1, 'hasta' => 100, 'siguiente' => 1]);
        }
        $this->local = Local::create(['codigo' => 'M', 'nombre' => 'Matriz']);
        $this->cemento = Producto::create(['sku' => 'CEM', 'nombre' => 'Cemento 25 kg', 'precio_neto' => '5000', 'precio_mayorista' => '4500', 'costo' => '3000']);
        $this->fierro = Producto::create(['sku' => 'FIE', 'nombre' => 'Fierro 8 mm', 'precio_neto' => '3000', 'costo' => '2000']);
        $this->cliente = Cliente::firstOrCreate(['rut' => '76543210-3'], ['razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción', 'direccion' => 'Av. Los Leones 100', 'comuna' => 'Providencia', 'lista_precio' => 'meson']);
        $this->docs = app(Documentos::class);

        $r = app(Recepciones::class)->guardar(['local_id' => $this->local->id, 'proveedor_nombre' => 'Proveedor', 'lineas' => [
            ['producto_id' => $this->cemento->id, 'cantidad' => '100', 'costo_unitario' => '3000'],
            ['producto_id' => $this->fierro->id, 'cantidad' => '100', 'costo_unitario' => '2000'],
        ]], $this->jefe);
        app(Recepciones::class)->confirmar($r, $this->jefe);
    }

    private function factura(array $lineas, string $descGlobal = '0'): Documento
    {
        $doc = $this->docs->guardar(['tipo_dte' => 33, 'cliente_id' => $this->cliente->id, 'local_id' => $this->local->id, 'descuento_global_pct' => $descGlobal, 'lineas' => $lineas], $this->vendedor);

        return $this->docs->emitir($doc, $this->vendedor);
    }

    private function stock(Producto $p): string
    {
        return (string) Stock::where('producto_id', $p->id)->where('local_id', $this->local->id)->value('cantidad');
    }

    /* ───────────── Precios ───────────── */

    public function test_cambio_pequeno_se_aplica_y_queda_en_el_historial(): void
    {
        $c = app(Precios::class)->proponer([['producto_id' => $this->cemento->id, 'campo' => 'precio_neto', 'nuevo' => '5400']], 'Alza proveedor', $this->vendedor);

        $this->assertSame('aplicado', $c->estado);
        $this->assertSame('8.00', (string) $c->lineas->first()->variacion_pct);
        $this->assertSame('5400.000000', (string) $this->cemento->fresh()->precio_neto);
        $h = HistorialPrecio::where('producto_id', $this->cemento->id)->where('campo', 'precio_neto')->sole();
        $this->assertSame(['5000.000000', '5400.000000', 'actualizacion', $c->id], [(string) $h->anterior, (string) $h->nuevo, $h->origen, (int) $h->cambio_precio_id]);
    }

    public function test_variacion_grande_o_bajo_costo_espera_aprobacion_de_otra_persona(): void
    {
        $precios = app(Precios::class);
        $c = $precios->proponer([['producto_id' => $this->cemento->id, 'campo' => 'precio_neto', 'nuevo' => '2900']], 'Liquidación', $this->vendedor);

        $this->assertSame('por_aprobar', $c->estado);
        $this->assertCount(2, $c->motivos);                     // −42 % y bajo el costo de $3.000
        $this->assertSame('5000.000000', (string) $this->cemento->fresh()->precio_neto);

        try {
            $precios->aprobar($c, $this->vendedor);
            $this->fail('Nadie aprueba su propio cambio.');
        } catch (ValidationException) {
        }
        $precios->aprobar($c, $this->jefe);
        $this->assertSame('2900.000000', (string) $this->cemento->fresh()->precio_neto);
        $this->assertSame('aplicado', $c->fresh()->estado);
    }

    public function test_si_el_precio_cambio_desde_la_solicitud_no_se_aplica(): void
    {
        $precios = app(Precios::class);
        $grande = $precios->proponer([['producto_id' => $this->cemento->id, 'campo' => 'precio_neto', 'nuevo' => '9000']], 'Alza', $this->vendedor);
        $precios->proponer([['producto_id' => $this->cemento->id, 'campo' => 'precio_neto', 'nuevo' => '5100']], 'Ajuste', $this->jefe);

        $this->expectException(ValidationException::class);
        $precios->aprobar($grande, $this->jefe);
    }

    public function test_ajuste_masivo_por_porcentaje_con_redondeo(): void
    {
        $c = app(Precios::class)->masivo(null, 'precio_neto', '5', 10, 'Reajuste IPC', $this->vendedor);

        $this->assertSame('aplicado', $c->estado);
        $this->assertSame('5250.000000', (string) $this->cemento->fresh()->precio_neto);
        $this->assertSame('3150.000000', (string) $this->fierro->fresh()->precio_neto);
    }

    public function test_el_historial_es_inmutable_y_registra_el_costo_de_recepcion(): void
    {
        $h = HistorialPrecio::where('producto_id', $this->cemento->id)->where('campo', 'costo')->first();
        $this->assertNull($h);                       // el costo de recepción era igual al de la ficha

        $r = app(Recepciones::class)->guardar(['local_id' => $this->local->id, 'proveedor_nombre' => 'P', 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '1', 'costo_unitario' => '3300']]], $this->jefe);
        app(Recepciones::class)->confirmar($r, $this->jefe);
        $h = HistorialPrecio::where('producto_id', $this->cemento->id)->where('campo', 'costo')->sole();
        $this->assertSame('recepcion', $h->origen);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('historial_precios')->where('id', $h->id)->update(['nuevo' => 1]);
    }

    /* ───────────── Despachos ───────────── */

    public function test_despacho_recorre_la_maquina_de_estados_con_firma(): void
    {
        $doc = $this->factura([['producto_id' => $this->cemento->id, 'cantidad' => '40']]);   // $238.000, sobre el mínimo para gratis
        $d = app(Despachos::class)->crear($doc, ['fecha_programada' => now()->toDateString(), 'transportista' => 'simulado'], $this->vendedor);

        $this->assertSame(['programado', 'Av. Los Leones 100'], [$d->estado, $d->direccion]);
        $d = app(Despachos::class)->despachar($d, $this->vendedor);
        $this->assertStringStartsWith('SIM-', $d->tracking);

        try {
            app(Despachos::class)->entregar($d, 'Pedro Rojas', null, '', $this->vendedor);
            $this->fail('Sin firma no se entrega.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('firma', $e->errors());
        }
        $d = app(Despachos::class)->entregar($d, 'Pedro Rojas', '11.111.111-1', self::FIRMA, $this->vendedor);
        $this->assertSame('entregado', $d->estado);
        Storage::disk('local')->assertExists($d->firma_path);
        $this->assertSame([null, 'programado', 'en_ruta', 'entregado'], array_merge([null], $d->eventos()->pluck('a')->all()));

        // Cerrado: la base de datos no deja tocarlo.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('despachos')->where('id', $d->id)->update(['estado' => 'en_ruta']);
    }

    public function test_la_base_de_datos_rechaza_transiciones_invalidas(): void
    {
        $doc = $this->factura([['producto_id' => $this->cemento->id, 'cantidad' => '40']]);
        $d = app(Despachos::class)->crear($doc, ['fecha_programada' => now()->toDateString()], $this->vendedor);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('despachos')->where('id', $d->id)->update(['estado' => 'entregado', 'recibido_nombre' => 'X', 'firma_path' => 'x', 'entregado_at' => now()]);
    }

    public function test_despacho_gratis_en_venta_chica_lo_aprueba_otra_persona_y_fallido_se_reprograma(): void
    {
        $doc = $this->factura([['producto_id' => $this->cemento->id, 'cantidad' => '2']]);
        $svc = app(Despachos::class);
        $d = $svc->crear($doc, ['fecha_programada' => now()->toDateString(), 'cobro' => 0], $this->vendedor);
        $this->assertSame('por_aprobar', $d->estado);

        try {
            $svc->aprobar($d, $this->vendedor);
            $this->fail('Cuatro ojos.');
        } catch (ValidationException) {
        }
        $d = $svc->aprobar($d, $this->jefe);
        $d = $svc->despachar($d, $this->vendedor);
        $d = $svc->fallar($d, 'Cliente ausente', $this->vendedor);
        $d = $svc->reprogramar($d, now()->addDay()->toDateString(), $this->vendedor);
        $this->assertSame(['programado', 1], [$d->estado, $d->intentos]);

        // Con cargo no requiere aprobación.
        $otro = $this->factura([['producto_id' => $this->fierro->id, 'cantidad' => '1']]);
        $this->assertSame('programado', $svc->crear($otro, ['fecha_programada' => now()->toDateString(), 'cobro' => 5990], $this->vendedor)->estado);
    }

    /* ───────────── Devoluciones ───────────── */

    public function test_devolucion_parcial_genera_nc_y_reingresa_stock(): void
    {
        $doc = $this->factura([['producto_id' => $this->cemento->id, 'cantidad' => '10'], ['producto_id' => $this->fierro->id, 'cantidad' => '5']]);
        $this->assertSame('90.000000', $this->stock($this->cemento));
        $linea = $doc->lineas->firstWhere('producto_id', $this->cemento->id);

        $dev = app(Devoluciones::class)->solicitar($doc, [['documento_linea_id' => $linea->id, 'cantidad' => '4']], 'falla', 'Sacos rotos', $this->vendedor);
        $this->assertSame('solicitada', $dev->estado);
        $this->assertSame(23800, $dev->monto_estimado);           // 4 × 5.000 + IVA
        $this->assertSame('90.000000', $this->stock($this->cemento));

        $dev = app(Devoluciones::class)->aprobar($dev, $this->jefe);
        $nc = $dev->notaCredito;
        $this->assertSame(['aprobada', 61, 'emitido', 23800], [$dev->estado, $nc->tipo_dte, $nc->estado, $nc->monto_total]);
        $this->assertSame(3, $nc->referencias->first()->codigo);
        $this->assertSame('94.000000', $this->stock($this->cemento));
        $mov = MovimientoStock::where('tipo', 'devolucion')->sole();
        $this->assertSame('3000.000000', (string) $mov->costo_unitario);

        // No se puede devolver más de lo que queda.
        $this->expectException(ValidationException::class);
        app(Devoluciones::class)->solicitar($doc, [['documento_linea_id' => $linea->id, 'cantidad' => '7']], 'otro', 'x', $this->vendedor);
    }

    public function test_devolucion_total_anula_y_producto_danado_no_vuelve_al_stock(): void
    {
        $doc = $this->factura([['producto_id' => $this->cemento->id, 'cantidad' => '10']], '5');
        $linea = $doc->lineas->first();

        $dev = app(Devoluciones::class)->solicitar($doc, [['documento_linea_id' => $linea->id, 'cantidad' => '10', 'reingresa' => false]], 'falla', 'Llegó mojado', $this->vendedor);
        $dev = app(Devoluciones::class)->aprobar($dev, $this->jefe);

        $this->assertSame(1, $dev->notaCredito->referencias->first()->codigo);
        $this->assertSame($doc->monto_total, $dev->notaCredito->monto_total);
        $this->assertSame(0, $doc->fresh()->saldoAcreditable());
        $this->assertSame('90.000000', $this->stock($this->cemento));   // dañado: no reingresa
        $this->assertSame(0, MovimientoStock::whereIn('tipo', ['devolucion', 'anulacion_venta'])->count());
    }

    public function test_nadie_aprueba_su_propia_devolucion_salvo_bajo_el_umbral(): void
    {
        $doc = $this->factura([['producto_id' => $this->fierro->id, 'cantidad' => '5']]);
        $linea = $doc->lineas->first();
        $dev = app(Devoluciones::class)->solicitar($doc, [['documento_linea_id' => $linea->id, 'cantidad' => '1']], 'arrepentimiento', 'No lo usó', $this->vendedor);
        try {
            app(Devoluciones::class)->aprobar($dev, $this->vendedor);
            $this->fail('Cuatro ojos.');
        } catch (ValidationException) {
        }
        app(Devoluciones::class)->rechazar($dev, $this->jefe, 'Producto usado');

        // El umbral de una empresa no puede ser más laxo que el global: se sube el global.
        $su = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        app(Parametros::class)->proponer('inventario.devolucion_aprobacion_monto', 10000, 'global', 'Devoluciones chicas', $su);
        $auto = app(Devoluciones::class)->solicitar($doc, [['documento_linea_id' => $linea->id, 'cantidad' => '1']], 'arrepentimiento', 'No lo usó', $this->vendedor);
        $this->assertSame('aprobada', $auto->estado);
        $this->assertNull($auto->aprobado_por);
    }

    public function test_editar_el_precio_en_la_ficha_pasa_por_la_aprobacion(): void
    {
        $su = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        $datos = ['sku' => 'CEM', 'nombre' => 'Cemento 25 kg', 'unidad' => 'SAC', 'precio_neto' => '9000', 'precio_mayorista' => '4500', 'exento' => false, 'activo' => true];
        $this->entrar($su)->put("/configuracion/productos/{$this->cemento->id}", $datos)->assertSessionHasNoErrors();

        app(Tenancy::class)->establecer($this->empresa);
        $p = $this->cemento->fresh();
        $this->assertSame(['5000.000000', 'SAC'], [(string) $p->precio_neto, $p->unidad]);   // el resto sí cambia
        $this->assertSame('por_aprobar', \App\Modules\Inventario\Models\CambioPrecio::latest('id')->first()->estado);
    }
}
