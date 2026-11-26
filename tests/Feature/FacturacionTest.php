<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Facturacion\Sii\ProveedorDteSimulado;
use App\Support\Rut;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FacturacionTest extends TestCase
{
    private Empresa $empresa;

    private User $cajera;

    private User $jefe;

    private Documentos $docs;

    protected function setUp(): void
    {
        parent::setUp();
        ProveedorDteSimulado::caer(false);
        $this->empresa = $this->empresa();
        $this->cajera = $this->usuario($this->empresa, 'Cajera');
        $this->jefe = $this->usuario($this->empresa, 'Gerente');
        app(Tenancy::class)->establecer($this->empresa);
        app(Parametros::class)->proponer('modulos.facturacion', true, 'empresa', 'Activar facturación', $this->jefe);
        foreach ([33 => [1070, 2000], 39 => [1405, 3000], 61 => [253, 400], 56 => [1, 100], 52 => [1, 100]] as $tipo => [$desde, $hasta]) {
            FolioRango::create(['tipo_dte' => $tipo, 'desde' => $desde, 'hasta' => $hasta, 'siguiente' => $desde]);
        }
        $this->docs = app(Documentos::class);
    }

    private function cliente(array $attrs = []): Cliente
    {
        return Cliente::firstOrCreate(['rut' => $attrs['rut'] ?? '76543210-3'], $attrs + [
            'rut' => '76543210-3', 'razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción',
            'direccion' => 'Av. Los Leones 100', 'comuna' => 'Providencia', 'lista_precio' => 'meson',
        ]);
    }

    private function producto(array $attrs = []): Producto
    {
        static $n = 0;
        $n++;

        return Producto::create($attrs + ['sku' => "SKU-{$n}", 'nombre' => "Cemento 25 kg {$n}", 'precio_neto' => '4990', 'precio_mayorista' => '4500']);
    }

    private function factura(array $lineas = null, array $extra = []): Documento
    {
        $lineas ??= [['producto_id' => $this->producto()->id, 'cantidad' => '10']];

        return $this->docs->guardar(['tipo_dte' => 33, 'cliente_id' => $this->cliente()->id, 'lineas' => $lineas] + $extra, $this->cajera);
    }

    public function test_rut_de_prueba_es_valido(): void
    {
        $this->assertTrue(Rut::valido('76543210-3'));
    }

    public function test_emitir_factura_asigna_folio_congela_receptor_y_envia_al_sii(): void
    {
        $doc = $this->docs->emitir($this->factura(), $this->cajera);

        $this->assertSame(1070, $doc->folio);
        $this->assertSame('emitido', $doc->estado);
        $this->assertSame([49900, 9481, 59381], [$doc->monto_neto, $doc->monto_iva, $doc->monto_total]);
        $this->assertSame('76543210-3', $doc->receptor_rut);
        $this->assertSame($doc->monto_total, $doc->saldo);
        $this->assertSame('aceptado', $doc->estado_sii);
        $this->assertNotNull($doc->pdf_path);
        $this->assertSame(1071, FolioRango::where('tipo_dte', 33)->value('siguiente'));
    }

    public function test_el_precio_lo_decide_el_backend_segun_la_lista_del_cliente(): void
    {
        $p = $this->producto();
        $mayorista = $this->cliente(['rut' => '11111111-1', 'lista_precio' => 'mayorista']);

        $doc = $this->docs->guardar(['tipo_dte' => 33, 'cliente_id' => $mayorista->id, 'lineas' => [['producto_id' => $p->id, 'cantidad' => '2']]], $this->cajera);
        $this->assertSame(9000, $doc->monto_neto);

        $boleta = $this->docs->guardar(['tipo_dte' => 39, 'lineas' => [['producto_id' => $p->id, 'cantidad' => '1']]], $this->cajera);
        $this->assertSame(0, BigDecimal::of($boleta->lineas[0]->precio_unitario)->compareTo('5938'));   // 4990 × 1,19 = 5938,1
        $this->assertSame(5938, $boleta->monto_total);
    }

    public function test_folios_correlativos_y_rollback_devuelve_el_folio(): void
    {
        $a = $this->docs->emitir($this->factura(), $this->cajera);

        // Esta emisión falla después de tomar el folio (receptor incompleto se valida antes; forzamos falla con NC inválida):
        $sinGiro = $this->docs->guardar(['tipo_dte' => 33, 'cliente_id' => $this->cliente(['rut' => '22222222-2', 'giro' => null])->id, 'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => '1']]], $this->cajera);
        try {
            $this->docs->emitir($sinGiro, $this->cajera);
            $this->fail('Debía rechazar receptor incompleto');
        } catch (ValidationException) {
        }

        $b = $this->docs->emitir($this->factura(), $this->cajera);
        $this->assertSame([1070, 1071], [$a->folio, $b->folio], 'Sin huecos ni duplicados');
        $this->assertNull($sinGiro->fresh()->folio);
    }

    public function test_sin_folios_no_se_emite(): void
    {
        FolioRango::where('tipo_dte', 33)->update(['siguiente' => DB::raw('hasta + 1')]);

        $this->expectException(ValidationException::class);
        $this->docs->emitir($this->factura(), $this->cajera);
    }

    public function test_factura_no_va_al_rut_generico(): void
    {
        $generico = $this->cliente(['rut' => '66666666-6', 'razon_social' => 'Consumidor final']);
        $doc = $this->docs->guardar(['tipo_dte' => 33, 'cliente_id' => $generico->id, 'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => '1']]], $this->cajera);

        $this->expectException(ValidationException::class);
        $this->docs->emitir($doc, $this->cajera);
    }

    public function test_descuento_sobre_el_maximo_requiere_aprobacion_de_otra_persona(): void
    {
        $doc = $this->factura([['producto_id' => $this->producto()->id, 'cantidad' => '1', 'descuento_pct' => '15']]);
        $this->assertSame('por_aprobar', $doc->estado);
        $this->assertNotEmpty($doc->aprobacion_motivos);

        try {
            $this->docs->emitir($doc, $this->cajera);
            $this->fail('No se emite sin aprobación');
        } catch (ValidationException) {
        }
        try {
            $this->docs->aprobar($doc, $this->cajera);
            $this->fail('Nadie aprueba lo propio');
        } catch (ValidationException) {
        }

        $this->docs->aprobar($doc->fresh(), $this->jefe);
        $this->assertSame('emitido', $this->docs->emitir($doc->fresh(), $this->cajera)->estado);
    }

    public function test_precio_manual_bajo_lista_requiere_aprobacion_y_queda_registrado(): void
    {
        $doc = $this->factura([['producto_id' => $this->producto()->id, 'cantidad' => '1', 'precio_unitario' => '3990']]);

        $this->assertSame('por_aprobar', $doc->estado);
        $this->assertSame(0, BigDecimal::of($doc->lineas[0]->precio_lista)->compareTo('4990'));
        $this->assertSame(0, BigDecimal::of($doc->lineas[0]->precio_unitario)->compareTo('3990'));
    }

    public function test_documento_emitido_es_inmutable_en_la_base(): void
    {
        $doc = $this->docs->emitir($this->factura(), $this->cajera);

        $this->esperarRechazoBd(fn () => DB::table('documentos')->where('id', $doc->id)->update(['monto_neto' => 1, 'monto_total' => 1 + $doc->monto_iva]));
        $this->esperarRechazoBd(fn () => DB::table('documentos')->where('id', $doc->id)->delete());
        $this->esperarRechazoBd(fn () => DB::table('documento_lineas')->where('documento_id', $doc->id)->update(['descripcion' => 'otra']));
        $this->esperarRechazoBd(fn () => DB::table('documento_lineas')->where('documento_id', $doc->id)->delete());
    }

    public function test_la_base_rechaza_una_cabecera_descuadrada_con_sus_lineas(): void
    {
        $doc = $this->factura();

        $e = $this->esperarRechazoBd(fn () => DB::table('documentos')->where('id', $doc->id)->update([
            'estado' => 'emitido', 'folio' => 999999, 'monto_neto' => $doc->monto_neto + 1, 'monto_total' => $doc->monto_total + 1,
        ]));
        $this->assertStringContainsString('no cuadra', $e->getMessage());
    }

    public function test_la_base_exige_total_igual_a_neto_mas_iva_mas_exento(): void
    {
        $doc = $this->factura();

        $this->esperarRechazoBd(fn () => DB::table('documentos')->where('id', $doc->id)->update(['monto_total' => $doc->monto_total + 1]));
    }

    public function test_rangos_de_folios_no_se_solapan(): void
    {
        $this->esperarRechazoBd(fn () => FolioRango::create(['tipo_dte' => 33, 'desde' => 1990, 'hasta' => 2500, 'siguiente' => 1990]));
    }

    public function test_nota_de_credito_parcial_y_tope_del_saldo_acreditable(): void
    {
        $p = $this->producto();
        $f = $this->docs->emitir($this->factura([['producto_id' => $p->id, 'cantidad' => '10']]), $this->cajera);

        $nc = $this->docs->prepararNota($f, 61, 3, 'Devuelve 4 sacos', $this->cajera, [['producto_id' => $p->id, 'cantidad' => '4']]);
        $nc = $this->docs->emitir($nc, $this->cajera);
        $this->assertSame(253, $nc->folio);
        $this->assertSame(23752, $nc->monto_total);
        $this->assertSame(59381 - 23752, $f->fresh()->saldo);

        $exceso = $this->docs->prepararNota($f->fresh(), 61, 3, 'Devuelve 7 sacos', $this->cajera, [['producto_id' => $p->id, 'cantidad' => '7']]);
        $this->expectException(ValidationException::class);
        $this->docs->emitir($exceso, $this->cajera);
    }

    public function test_anulacion_por_el_total_deja_el_documento_anulado(): void
    {
        $f = $this->docs->emitir($this->factura([['producto_id' => $this->producto()->id, 'cantidad' => '3', 'descuento_pct' => '5']], ['descuento_global_pct' => '2']), $this->cajera);

        $nc = $this->docs->emitir($this->docs->prepararNota($f, 61, 1, 'Error en cliente', $this->cajera), $this->cajera);

        $this->assertSame($f->monto_total, $nc->monto_total);
        $this->assertSame('anulado', $f->fresh()->estado);
        $this->assertSame(0, $f->fresh()->saldo);
    }

    public function test_anulacion_de_boleta_cuadra_al_peso_con_iva_incluido(): void
    {
        $b = $this->docs->emitir($this->docs->guardar(['tipo_dte' => 39, 'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => '3']]], $this->cajera), $this->cajera);
        $nc = $this->docs->emitir($this->docs->prepararNota($b, 61, 1, 'Cliente desiste', $this->cajera), $this->cajera);

        $this->assertTrue($nc->iva_incluido);
        $this->assertSame($b->monto_total, $nc->monto_total);
    }

    public function test_nota_que_corrige_texto_puede_ser_de_monto_cero(): void
    {
        $f = $this->docs->emitir($this->factura(), $this->cajera);
        $nc = $this->docs->emitir($this->docs->prepararNota($f, 61, 2, 'Dirección correcta: Av. Los Leones 110', $this->cajera), $this->cajera);

        $this->assertSame(0, $nc->monto_total);
        $this->assertSame($f->monto_total, $f->fresh()->saldo);
    }

    public function test_cobro_parcial_y_total(): void
    {
        $f = $this->docs->emitir($this->factura(), $this->cajera);

        $this->docs->registrarPago($f, 20000, 'transferencia', $this->cajera);
        $this->assertSame('emitido', $f->fresh()->estado);
        $this->docs->registrarPago($f, 39381, 'debito', $this->cajera);
        $this->assertSame('pagado', $f->fresh()->estado);
        $this->assertSame(0, $f->fresh()->saldo);

        $this->expectException(ValidationException::class);
        $this->docs->registrarPago($f->fresh(), 1, 'efectivo', $this->cajera);
    }

    public function test_venta_a_credito_solo_con_cuenta_corriente(): void
    {
        $doc = $this->factura(null, ['forma_pago' => 'credito']);

        $this->expectException(ValidationException::class);
        $this->docs->emitir($doc, $this->cajera);
    }

    public function test_credito_con_cupo_fija_vencimiento(): void
    {
        $c = $this->cliente(['rut' => '33333333-3', 'tiene_credito' => true, 'cupo_credito' => 1000000, 'plazo_dias' => 30]);
        $doc = $this->docs->guardar(['tipo_dte' => 33, 'cliente_id' => $c->id, 'forma_pago' => 'credito', 'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => '1']]], $this->cajera);
        $doc = $this->docs->emitir($doc, $this->cajera);

        $this->assertSame(now()->addDays(30)->toDateString(), $doc->fecha_vencimiento->toDateString());
    }

    public function test_cupo_excedido_requiere_aprobacion(): void
    {
        $c = $this->cliente(['rut' => '44444444-4', 'tiene_credito' => true, 'cupo_credito' => 10000, 'plazo_dias' => 30]);
        $doc = $this->docs->guardar(['tipo_dte' => 33, 'cliente_id' => $c->id, 'forma_pago' => 'credito', 'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => '5']]], $this->cajera);

        $this->assertSame('por_aprobar', $doc->estado);
        $this->assertStringContainsString('Cupo de crédito excedido', implode(' ', $doc->aprobacion_motivos));
    }

    public function test_proveedor_caido_deja_el_documento_emitido_con_error_sii(): void
    {
        ProveedorDteSimulado::caer();
        try {
            $doc = $this->docs->emitir($this->factura(), $this->cajera);
        } catch (\Throwable) {
        } finally {
            ProveedorDteSimulado::caer(false);
        }

        $doc = Documento::where('tipo_dte', 33)->where('folio', 1070)->firstOrFail();
        $this->assertSame('emitido', $doc->estado);
        $this->assertSame('error', $doc->estado_sii);
    }

    public function test_lineas_y_descripcion_dentro_de_limites(): void
    {
        $this->expectException(ValidationException::class);
        $this->factura([['descripcion' => str_repeat('x', 81), 'cantidad' => '1', 'precio_unitario' => '100']]);
    }

    public function test_sin_modulo_activo_no_se_factura(): void
    {
        $otra = $this->empresa();
        app(Tenancy::class)->establecer($otra);

        $this->expectException(ValidationException::class);
        $this->docs->guardar(['tipo_dte' => 39, 'lineas' => [['descripcion' => 'X', 'cantidad' => '1', 'precio_unitario' => '100']]], $this->cajera);
    }
}
