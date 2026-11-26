<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Models\Trazabilidad;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Facturacion\Sii\ProveedorDteSimulado;
use App\Modules\Inventario\Inventario;
use App\Modules\Inventario\Mermas;
use App\Modules\Inventario\Models\MovimientoStock;
use App\Modules\Inventario\Models\Stock;
use App\Modules\Inventario\Recepciones;
use App\Modules\Ventas\Cotizaciones;
use App\Modules\Ventas\OrdenesVenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventarioTest extends TestCase
{
    private Empresa $empresa;

    private User $bodeguero;

    private User $cajera;

    private User $jefe;

    private Local $local;

    private Producto $cemento;

    private Inventario $inv;

    private Documentos $docs;

    protected function setUp(): void
    {
        parent::setUp();
        ProveedorDteSimulado::caer(false);
        $this->empresa = $this->empresa();
        $this->bodeguero = $this->usuario($this->empresa, 'Bodeguero', con2fa: false);
        $this->cajera = $this->usuario($this->empresa, 'Cajera');
        $this->jefe = $this->usuario($this->empresa, 'Gerente');
        app(Tenancy::class)->establecer($this->empresa);
        app(Parametros::class)->proponer('modulos.facturacion', true, 'empresa', 'Pruebas', $this->jefe);
        foreach ([33 => [1, 100], 39 => [1, 100], 61 => [1, 100]] as $tipo => [$d, $h]) {
            FolioRango::create(['tipo_dte' => $tipo, 'desde' => $d, 'hasta' => $h, 'siguiente' => $d]);
        }
        $this->local = Local::create(['codigo' => 'M', 'nombre' => 'Matriz']);
        $this->cemento = Producto::create(['sku' => 'CEM', 'nombre' => 'Cemento 25 kg', 'precio_neto' => '4990', 'precio_mayorista' => '4500']);
        $this->inv = app(Inventario::class);
        $this->docs = app(Documentos::class);
    }

    private function recibir(string $cantidad, string $costo, ?Producto $p = null): void
    {
        $r = app(Recepciones::class)->guardar(['local_id' => $this->local->id, 'proveedor_nombre' => 'Cementos Bío Bío', 'proveedor_rut' => '76543210-3',
            'lineas' => [['producto_id' => ($p ?? $this->cemento)->id, 'cantidad' => $cantidad, 'costo_unitario' => $costo]]], $this->bodeguero);
        app(Recepciones::class)->confirmar($r, $this->bodeguero);
    }

    private function stock(): Stock
    {
        return Stock::where('producto_id', $this->cemento->id)->where('local_id', $this->local->id)->firstOrFail();
    }

    private function boleta(string $cantidad): Documento
    {
        return $this->docs->guardar(['tipo_dte' => 39, 'local_id' => $this->local->id, 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => $cantidad]]], $this->cajera);
    }

    public function test_recepcion_suma_stock_y_calcula_costo_promedio_ponderado(): void
    {
        $this->recibir('100', '3000');
        $this->recibir('50', '3600');

        $s = $this->stock();
        $this->assertSame('150.000000', (string) $s->cantidad);
        $this->assertSame('3200.000000', (string) $s->costo_promedio);   // (100×3000 + 50×3600) / 150
        $this->assertSame('3600.000000', (string) $this->cemento->fresh()->costo);
        $this->assertSame(['150.000000', '3200.000000'], [(string) MovimientoStock::latest('id')->first()->saldo_cantidad, (string) MovimientoStock::latest('id')->first()->saldo_costo_promedio]);
    }

    public function test_emitir_descuenta_stock_al_costo_promedio_y_sin_stock_no_emite(): void
    {
        $this->recibir('10', '3000');
        $this->docs->emitir($this->boleta('4'), $this->cajera);

        $this->assertSame('6.000000', (string) $this->stock()->cantidad);
        $venta = MovimientoStock::where('tipo', 'venta')->first();
        $this->assertSame(['-4.000000', '3000.000000'], [(string) $venta->cantidad, (string) $venta->costo_unitario]);

        $sinStock = $this->boleta('7');
        try {
            $this->docs->emitir($sinStock, $this->cajera);
            $this->fail('Sin stock no se emite.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stock insuficiente', $e->getMessage());
        }
        // La emisión se revirtió entera: sin folio y sin movimiento.
        $this->assertSame('borrador', $sinStock->fresh()->estado);
        $this->assertNull($sinStock->fresh()->folio);
        $this->assertSame('6.000000', (string) $this->stock()->cantidad);
    }

    public function test_permitir_vender_sin_stock_deja_negativo(): void
    {
        app(Parametros::class)->proponer('inventario.permitir_negativo', true, 'empresa', 'Pruebas', $this->jefe);
        $this->docs->emitir($this->boleta('2'), $this->cajera);

        $this->assertSame('-2.000000', (string) $this->stock()->cantidad);
    }

    public function test_nota_de_credito_que_anula_devuelve_el_stock(): void
    {
        $this->recibir('10', '3000');
        $b = $this->docs->emitir($this->boleta('4'), $this->cajera);
        $this->docs->emitir($this->docs->prepararNota($b, 61, 1, 'Cliente desiste', $this->cajera), $this->cajera);

        $this->assertSame('10.000000', (string) $this->stock()->cantidad);
        $this->assertSame('3000.000000', (string) $this->stock()->costo_promedio);
    }

    public function test_kardex_inmutable_y_stock_solo_por_kardex(): void
    {
        $this->recibir('10', '3000');
        $mov = MovimientoStock::first();

        $this->esperarRechazoBd(fn () => DB::table('movimientos_stock')->where('id', $mov->id)->update(['cantidad' => 999]));
        $this->esperarRechazoBd(fn () => DB::table('movimientos_stock')->where('id', $mov->id)->delete());
        $this->esperarRechazoBd(fn () => DB::table('stock')->where('id', $this->stock()->id)->update(['cantidad' => 999]));
        $this->esperarRechazoBd(fn () => DB::table('recepciones')->update(['proveedor_nombre' => 'Otro']));
        // El mínimo sí es configuración editable.
        DB::table('stock')->where('id', $this->stock()->id)->update(['minimo' => 5]);
        $this->assertSame('5.000000', (string) $this->stock()->minimo);
    }

    public function test_merma_bajo_umbral_baja_stock_y_sobre_umbral_espera_aprobacion(): void
    {
        $this->recibir('100', '3000');
        $mermas = app(Mermas::class);

        $chica = $mermas->registrar($this->local->id, $this->cemento->id, '2', 'rotura', 'Sacos rotos en descarga', $this->bodeguero);
        $this->assertSame(['registrada', 6000], [$chica->estado, $chica->costo_total]);
        $this->assertSame('98.000000', (string) $this->stock()->cantidad);

        $grande = $mermas->registrar($this->local->id, $this->cemento->id, '40', 'vencimiento', 'Lote fraguado por humedad', $this->bodeguero);   // $120.000 > $100.000
        $this->assertSame('por_aprobar', $grande->estado);
        $this->assertSame('98.000000', (string) $this->stock()->cantidad);

        try {
            $mermas->aprobar($grande, $this->bodeguero);
            $this->fail('Nadie aprueba su propia merma.');
        } catch (ValidationException) {
        }
        $mermas->aprobar($grande, $this->jefe);
        $this->assertSame('58.000000', (string) $this->stock()->cantidad);
        $this->assertSame(2, Trazabilidad::where('accion', 'merma.registrar')->count());

        $this->expectException(ValidationException::class);
        $mermas->registrar($this->local->id, $this->cemento->id, '59', 'robo', 'Más de lo que hay', $this->bodeguero);
    }

    public function test_orden_de_venta_reserva_stock_y_lo_libera_al_facturar(): void
    {
        $this->recibir('10', '3000');
        $ejecutiva = $this->usuario($this->empresa, 'Ejecutiva comercial');
        $cliente = Cliente::create(['rut' => '76543210-3', 'razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción', 'direccion' => 'Av. 1', 'comuna' => 'Providencia', 'lista_precio' => 'mayorista']);
        $cots = app(Cotizaciones::class);
        $cot = $cots->guardar(['cliente_id' => $cliente->id, 'local_id' => $this->local->id, 'forma_pago' => 'contado', 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '8']]], $ejecutiva);
        $cots->enviar($cot, $ejecutiva);

        $ov = app(OrdenesVenta::class)->desdeCotizacion($cot->fresh(), $ejecutiva);
        $this->assertSame('convertida', $cot->fresh()->estado);
        $this->assertSame('2', (string) $this->inv->disponible($this->cemento->id, $this->local->id)->stripTrailingZeros());

        // El mesón no puede vender lo reservado.
        try {
            $this->docs->emitir($this->boleta('3'), $this->cajera);
            $this->fail('Lo reservado no se vende en el mesón.');
        } catch (ValidationException) {
        }

        // Facturar la orden sí usa su propia reserva; al emitir, la orden queda facturada y la reserva se libera.
        $doc = app(OrdenesVenta::class)->facturar($ov, 33, $ejecutiva);
        $this->assertSame($ov->monto_total, $doc->monto_total);
        $this->docs->emitir($doc, $this->cajera);
        $this->assertSame('facturada', $ov->fresh()->estado);
        $this->assertSame('2.000000', (string) $this->stock()->cantidad);
        $this->assertSame('2', (string) $this->inv->disponible($this->cemento->id, $this->local->id)->stripTrailingZeros());
    }

    public function test_orden_de_venta_valida_stock_y_cupo_antes_de_confirmar(): void
    {
        $this->recibir('5', '3000');
        $ejecutiva = $this->usuario($this->empresa, 'Ejecutiva comercial');
        $cots = app(Cotizaciones::class);
        $cliente = Cliente::create(['rut' => '96543210-K', 'razon_social' => 'Ferretería Sur', 'giro' => 'Ferretería', 'direccion' => 'Av. 2', 'comuna' => 'Maipú',
            'lista_precio' => 'meson', 'tiene_credito' => true, 'cupo_credito' => 20000, 'plazo_dias' => 30]);

        $mucho = $cots->guardar(['cliente_id' => $cliente->id, 'local_id' => $this->local->id, 'forma_pago' => 'contado', 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '6']]], $ejecutiva);
        $cots->enviar($mucho, $ejecutiva);
        try {
            app(OrdenesVenta::class)->desdeCotizacion($mucho->fresh(), $ejecutiva);
            $this->fail('Sin stock no se confirma la orden.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $credito = $cots->guardar(['cliente_id' => $cliente->id, 'local_id' => $this->local->id, 'forma_pago' => 'credito', 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '5']]], $ejecutiva);
        $cots->aprobar($credito, $this->jefe);   // la cotización excedía el cupo y se aprobó igual…
        $cots->enviar($credito->fresh(), $ejecutiva);
        $this->expectException(ValidationException::class);   // …pero la orden revisa el cupo real
        app(OrdenesVenta::class)->desdeCotizacion($credito->fresh(), $ejecutiva);
    }

    public function test_anular_orden_libera_la_reserva(): void
    {
        $this->recibir('10', '3000');
        $ejecutiva = $this->usuario($this->empresa, 'Ejecutiva comercial');
        $cliente = Cliente::create(['rut' => '76543210-3', 'razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción', 'direccion' => 'Av. 1', 'comuna' => 'Providencia', 'lista_precio' => 'meson']);
        $cot = app(Cotizaciones::class)->guardar(['cliente_id' => $cliente->id, 'local_id' => $this->local->id, 'forma_pago' => 'contado', 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '10']]], $ejecutiva);
        app(Cotizaciones::class)->enviar($cot, $ejecutiva);
        $ov = app(OrdenesVenta::class)->desdeCotizacion($cot->fresh(), $ejecutiva);
        app(OrdenesVenta::class)->facturar($ov, 33, $ejecutiva);   // borrador que se descarta al anular

        app(OrdenesVenta::class)->anular($ov->fresh(), $ejecutiva, 'Cliente compró en otro lado');
        $this->assertSame('10', (string) $this->inv->disponible($this->cemento->id, $this->local->id)->stripTrailingZeros());
        $this->assertSame(0, Documento::where('orden_venta_id', $ov->id)->count());
    }
}
