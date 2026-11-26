<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Compras\FacturasProveedor;
use App\Modules\Compras\Models\Proveedor;
use App\Modules\Compras\OrdenesCompra;
use App\Modules\Compras\PagosProveedor;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Facturacion\Sii\ProveedorDteSimulado;
use App\Modules\Finanzas\Conciliacion;
use App\Modules\Finanzas\Contabilidad;
use App\Modules\Finanzas\FlujoCaja;
use App\Modules\Finanzas\Honorarios;
use App\Modules\Finanzas\Models\Asiento;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\MovimientoBancario;
use App\Modules\Inventario\Recepciones;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanzasTest extends TestCase
{
    private Empresa $empresa;

    private User $vendedor;

    private User $jefe;

    private User $finanzas;

    private Local $local;

    private Producto $cemento;

    private Cliente $cliente;

    private Documentos $docs;

    private Contabilidad $conta;

    protected function setUp(): void
    {
        parent::setUp();
        ProveedorDteSimulado::caer(false);
        $this->empresa = $this->empresa();
        $this->vendedor = $this->usuario($this->empresa, 'Ejecutiva comercial', con2fa: false);
        $this->jefe = $this->usuario($this->empresa, 'Gerente');
        $this->finanzas = $this->usuario($this->empresa, 'Analista de Finanzas');
        app(Tenancy::class)->establecer($this->empresa);
        app(Parametros::class)->proponer('modulos.facturacion', true, 'empresa', 'Pruebas', $this->jefe);
        foreach ([33, 39, 61] as $tipo) {
            FolioRango::create(['tipo_dte' => $tipo, 'desde' => 1, 'hasta' => 100, 'siguiente' => 1]);
        }
        $this->local = Local::create(['codigo' => 'M', 'nombre' => 'Matriz']);
        $this->cemento = Producto::create(['sku' => 'CEM', 'nombre' => 'Cemento 25 kg', 'precio_neto' => '5000', 'costo' => '3000']);
        $this->cliente = Cliente::firstOrCreate(['rut' => '76543210-3'], ['razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción', 'direccion' => 'Av. Los Leones 100', 'comuna' => 'Providencia', 'lista_precio' => 'meson']);
        $this->docs = app(Documentos::class);
        $this->conta = app(Contabilidad::class);
        $r = app(Recepciones::class)->guardar(['local_id' => $this->local->id, 'proveedor_nombre' => 'Proveedor', 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '100', 'costo_unitario' => '3000']]], $this->jefe);
        app(Recepciones::class)->confirmar($r, $this->jefe);
    }

    private function factura(int $cantidad = 10): Documento
    {
        $doc = $this->docs->guardar(['tipo_dte' => 33, 'cliente_id' => $this->cliente->id, 'local_id' => $this->local->id,
            'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => (string) $cantidad]]], $this->vendedor);

        return $this->docs->emitir($doc, $this->vendedor);
    }

    /** Saldo de la cuenta con esa clave, en su sentido natural. */
    private function saldo(string $clave): int
    {
        return $this->conta->saldos(null, now()->addYear()->toDateString())->firstWhere('codigo', $this->conta->cuenta($clave)->codigo)->saldo;
    }

    private function falla(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Debió rechazarse.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_emitir_y_cobrar_genera_asientos_que_cuadran(): void
    {
        $doc = $this->factura();                                   // 10 × 5.000 = 50.000 + IVA 9.500
        $a = Asiento::with('lineas')->where(['origen_tipo' => 'documento', 'origen_id' => $doc->id])->firstOrFail();
        $this->assertSame('automatico', $a->tipo);
        $this->assertSame($a->lineas->sum('debe'), $a->lineas->sum('haber'));
        // La recepción inicial no pasó por una factura de proveedor, así que el inventario contable solo ve la salida.
        $this->assertSame([59500, 50000, 9500, 30000, -30000], [$this->saldo('clientes'), $this->saldo('ventas'), $this->saldo('iva_debito'), $this->saldo('costo_ventas'), $this->saldo('inventario')]);

        $this->docs->registrarPago($doc->fresh(), 20000, 'transferencia', $this->vendedor, 'TRF-77');
        $this->assertSame([39500, 20000], [$this->saldo('clientes'), $this->saldo('banco')]);

        // La NC que anula revierte venta, IVA y costo.
        $nc = $this->docs->prepararNota($doc->fresh(), 61, 1, 'Anula', $this->vendedor);
        $this->docs->emitir($nc, $this->vendedor);
        $this->assertSame([0, 0, 0], [$this->saldo('ventas'), $this->saldo('iva_debito'), $this->saldo('costo_ventas')]);
    }

    public function test_la_base_de_datos_exige_partida_doble_e_inmutabilidad(): void
    {
        $this->conta->asegurarPlan();
        $caja = $this->conta->cuenta('caja')->id;
        $capital = $this->conta->cuenta('capital')->id;
        $this->esperarRechazoBd(function () use ($caja, $capital) {
            $a = Asiento::create(['numero' => 900, 'fecha' => now()->toDateString(), 'glosa' => 'Descuadrado', 'tipo' => 'manual', 'total' => 100, 'creado_por' => $this->jefe->id]);
            $a->lineas()->create(['cuenta_id' => $caja, 'debe' => 100]);
            $a->lineas()->create(['cuenta_id' => $capital, 'haber' => 90]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $ok = $this->conta->registrar(now()->toDateString(), 'Aporte de capital', [['cuenta_id' => $caja, 'debe' => 1000000], ['cuenta_id' => $capital, 'haber' => 1000000]], $this->finanzas);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->esperarRechazoBd(fn () => DB::table('asiento_lineas')->where('asiento_id', $ok->id)->update(['debe' => 1]));
        $this->esperarRechazoBd(fn () => DB::table('asientos')->where('id', $ok->id)->delete());
    }

    public function test_asiento_manual_valida_cuadre_periodo_y_se_revierte_una_vez(): void
    {
        $caja = $this->conta->cuenta('caja')->id;
        $gastos = $this->conta->cuenta('gastos_generales')->id;
        $this->falla(fn () => $this->conta->registrar(now()->toDateString(), 'Mal', [['cuenta_id' => $caja, 'haber' => 100], ['cuenta_id' => $gastos, 'debe' => 90]], $this->finanzas));
        $this->falla(fn () => $this->conta->registrar(now()->toDateString(), 'Una línea', [['cuenta_id' => $caja, 'haber' => 100]], $this->finanzas));

        $a = $this->conta->registrar(now()->toDateString(), 'Compra de útiles', [['cuenta_id' => $gastos, 'debe' => 12000], ['cuenta_id' => $caja, 'haber' => 12000]], $this->finanzas);
        $r = $this->conta->revertir($a, $this->finanzas, 'Error de digitación');
        $this->assertSame(['reverso', $a->id, 0], [$r->tipo, $r->revierte_id, $this->saldo('gastos_generales')]);
        $this->falla(fn () => $this->conta->revertir($a, $this->finanzas, 'Otra vez'));

        $su = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        $cambio = app(Parametros::class)->proponer('contabilidad.cierre_hasta', now()->toDateString(), 'empresa', 'Cierre', $this->finanzas);
        app(Parametros::class)->aprobar($cambio, $su);
        $this->falla(fn () => $this->conta->registrar(now()->toDateString(), 'En período cerrado', [['cuenta_id' => $gastos, 'debe' => 1], ['cuenta_id' => $caja, 'haber' => 1]], $this->finanzas));
    }

    public function test_compras_contabilizan_y_el_balance_cuadra(): void
    {
        $prov = Proveedor::create(['rut' => '96512340-7', 'razon_social' => 'Cementos del Sur', 'plazo_pago_dias' => 30]);
        $oc = app(OrdenesCompra::class)->enviar(app(OrdenesCompra::class)->guardar(['proveedor_id' => $prov->id, 'local_id' => $this->local->id, 'lineas' => [['producto_id' => $this->cemento->id, 'cantidad' => '10', 'costo_unitario' => '3000']]], $this->jefe), $this->jefe);
        $linea = $oc->lineas()->first();
        app(Recepciones::class)->confirmar(app(Recepciones::class)->guardar(['orden_compra_id' => $oc->id, 'lineas' => [['producto_id' => $this->cemento->id, 'orden_compra_linea_id' => $linea->id, 'cantidad' => '10', 'costo_unitario' => '3000']]], $this->jefe), $this->jefe);
        $f = app(FacturasProveedor::class)->registrar(['proveedor_id' => $prov->id, 'tipo_dte' => 33, 'folio' => 1, 'fecha_emision' => now()->toDateString(), 'orden_compra_id' => $oc->id,
            'monto_neto' => 30000, 'monto_iva' => 5700, 'monto_total' => 35700, 'lineas' => [['orden_compra_linea_id' => $linea->id, 'cantidad' => '10', 'costo_unitario' => '3000']]], $this->jefe);
        $this->assertSame([35700, 5700], [$this->saldo('proveedores'), $this->saldo('iva_credito')]);
        app(PagosProveedor::class)->registrar($f, ['monto' => 35700, 'fecha' => now()->toDateString(), 'metodo' => 'transferencia'], $this->finanzas);
        $this->assertSame([0, -35700], [$this->saldo('proveedores'), $this->saldo('banco')]);

        $this->factura(4);
        $s = $this->conta->saldos(null, now()->toDateString());
        $this->assertSame($s->sum('debe'), $s->sum('haber'));
        $activo = $s->where('tipo', 'activo')->sum('saldo');
        $pasivoPat = $s->whereIn('tipo', ['pasivo', 'patrimonio'])->sum('saldo');
        $resultado = $s->where('tipo', 'ingreso')->sum('saldo') - $s->where('tipo', 'gasto')->sum('saldo');
        $this->assertSame($activo, $pasivoPat + $resultado);
    }

    public function test_conciliacion_importa_cartola_cruza_y_no_duplica(): void
    {
        $prov = Proveedor::create(['rut' => '96512340-7', 'razon_social' => 'Cementos del Sur', 'plazo_pago_dias' => 30]);
        $cuenta = CuentaBancaria::create(['banco' => 'Banco de Chile', 'numero' => '001-23456-07', 'nombre' => 'Cuenta corriente', 'cuenta_contable_id' => $this->conta->cuenta('banco')->id]);
        $d1 = $this->factura(2);                                    // 11.900
        $d2 = $this->factura(2);                                    // 11.900 (mismo monto)
        $hoy = now();
        $this->docs->registrarPago($d1->fresh(), 11900, 'transferencia', $this->vendedor, 'TRF-1001');
        $this->docs->registrarPago($d2->fresh(), 11900, 'transferencia', $this->vendedor, 'TRF-1002');
        $fp = app(FacturasProveedor::class)->registrar(['proveedor_id' => $prov->id, 'tipo_dte' => 34, 'folio' => 9, 'fecha_emision' => $hoy->toDateString(), 'monto_neto' => 0, 'monto_exento' => 80000, 'monto_iva' => 0, 'monto_total' => 80000], $this->jefe);
        app(FacturasProveedor::class)->aprobar($fp, $this->finanzas);
        $pp = app(PagosProveedor::class)->registrar($fp->fresh(), ['monto' => 80000, 'fecha' => $hoy->toDateString(), 'metodo' => 'transferencia'], $this->finanzas);

        $f = $hoy->format('d/m/Y');
        $csv = "Cartola Cuenta Corriente 001-23456-07\nFecha;Descripción;N° Documento;Cargos;Abonos;Saldo\n"
            ."{$f};TRANSF DE CONSTRUCTORA ANDES TRF-1002;123;;11.900;1.011.900\n"
            ."{$f};TRANSF A CEMENTOS DEL SUR;124;80.000;;931.900\n"
            ."{$f};COMISION MANTENCION;;4.500;;927.400\n"
            ."{$f};DEPOSITO SIN IDENTIFICAR;;;250.000;1.177.400\n"
            ."Saldo final;;;;;1.177.400\n";
        $c = app(Conciliacion::class)->importar($cuenta, $csv, 'cartola.csv', $this->finanzas);
        $this->assertSame([4, 0, 2], [$c->movimientos, $c->duplicados, $c->conciliados]);
        $abono = MovimientoBancario::where('monto', 11900)->first();
        $this->assertSame($d2->id, $abono->pago->documento_id);      // ante el empate decide la referencia en la glosa
        $this->assertSame($pp->id, MovimientoBancario::where('monto', -80000)->value('pago_proveedor_id'));

        $otra = app(Conciliacion::class)->importar($cuenta, $csv, 'cartola.csv', $this->finanzas);
        $this->assertSame([0, 4], [$otra->movimientos, $otra->duplicados]);

        $comision = MovimientoBancario::where('monto', -4500)->first();
        app(Conciliacion::class)->contabilizar($comision, $this->conta->cuenta('gastos_bancarios')->id, 'Comisión mensual', $this->finanzas);
        $this->assertSame(4500, $this->saldo('gastos_bancarios'));
        app(Conciliacion::class)->deshacer($comision->fresh(), $this->finanzas);
        $this->assertSame([0, 'pendiente'], [$this->saldo('gastos_bancarios'), $comision->fresh()->estado]);

        $this->falla(fn () => app(Conciliacion::class)->conciliar(MovimientoBancario::where('monto', 250000)->first(), 'pago', $abono->pago_id, $this->finanzas));
    }

    public function test_honorarios_retienen_y_flujo_de_caja_proyecta(): void
    {
        $h = app(Honorarios::class)->registrar(['rut' => '12.345.678-5', 'nombre' => 'Ana Rojas', 'folio' => 45, 'fecha' => now()->toDateString(), 'descripcion' => 'Asesoría contable', 'bruto' => 400000], $this->finanzas);
        $this->assertSame([61000, 339000], [$h->retencion, $h->liquido]);       // 15,25 %
        $this->assertSame([400000, 61000, 339000], [$this->saldo('honorarios'), $this->saldo('retencion_honorarios'), $this->saldo('honorarios_por_pagar')]);
        $this->falla(fn () => app(Honorarios::class)->registrar(['rut' => '12.345.678-5', 'nombre' => 'Ana Rojas', 'folio' => 45, 'fecha' => now()->toDateString(), 'descripcion' => 'x', 'bruto' => 1], $this->finanzas));

        $this->factura();                                           // 59.500 por cobrar a 30 días o al contado
        $flujo = app(FlujoCaja::class)->proyectar();
        $this->assertSame(0, $flujo['disponible']);
        $this->assertSame(59500 - 339000, $flujo['tramos'][2]['saldo']);

        app(Honorarios::class)->pagar($h, now()->toDateString(), $this->finanzas);
        $this->assertSame([0, -339000], [$this->saldo('honorarios_por_pagar'), $this->saldo('banco')]);
        $this->falla(fn () => app(Honorarios::class)->anular($h->fresh(), 'x', $this->finanzas));

        $h2 = app(Honorarios::class)->registrar(['rut' => '12.345.678-5', 'nombre' => 'Ana Rojas', 'folio' => 46, 'fecha' => now()->toDateString(), 'descripcion' => 'Asesoría', 'bruto' => 100000], $this->finanzas);
        app(Honorarios::class)->anular($h2, 'Anulada en el SII', $this->finanzas);
        $this->assertSame(400000, $this->saldo('honorarios'));
    }

    public function test_pantallas_de_finanzas_por_http(): void
    {
        $doc = $this->factura();
        $this->docs->registrarPago($doc->fresh(), $doc->monto_total, 'transferencia', $this->vendedor, 'TRF-9');
        $u = $this->finanzas;

        foreach (['/finanzas/flujos-de-caja', '/finanzas/honorarios', '/finanzas/centros-de-costo', '/finanzas/asientos', '/finanzas/plan-de-cuentas', '/finanzas/balances?vista=general', '/finanzas/conciliacion'] as $ruta) {
            $this->entrar($u)->get($ruta)->assertOk();
        }
        $asiento = $this->en(fn () => Asiento::where('origen_tipo', 'documento')->firstOrFail());
        $this->entrar($u)->get("/finanzas/asientos/{$asiento->id}")->assertOk();
        $this->entrar($u)->get('/finanzas/balances?exportar=1')->assertOk();

        // Asiento manual descuadrado: se rechaza; cuadrado: se contabiliza.
        $caja = $this->en(fn () => $this->conta->cuenta('caja')->id);
        $gasto = $this->en(fn () => $this->conta->cuenta('gastos_generales')->id);
        $this->entrar($u)->post('/finanzas/asientos', ['fecha' => now()->toDateString(), 'glosa' => 'Compra menor', 'lineas' => [['cuenta_id' => $gasto, 'debe' => 1000, 'haber' => 0], ['cuenta_id' => $caja, 'debe' => 0, 'haber' => 900]]])->assertSessionHasErrors();
        $this->entrar($u)->post('/finanzas/asientos', ['fecha' => now()->toDateString(), 'glosa' => 'Compra menor', 'lineas' => [['cuenta_id' => $gasto, 'debe' => 1000, 'haber' => 0], ['cuenta_id' => $caja, 'debe' => 0, 'haber' => 1000]]])->assertRedirect();
        $this->assertSame(1, $this->en(fn () => Asiento::where('tipo', 'manual')->count()));

        // Boleta de honorarios: retención según parámetro.
        $this->entrar($u)->post('/finanzas/honorarios', ['rut' => '12345678-5', 'nombre' => 'Ana Rojas', 'folio' => 15, 'fecha' => now()->toDateString(), 'descripcion' => 'Asesoría', 'bruto' => 100000])->assertSessionHasNoErrors();
        $this->entrar($u)->get('/finanzas/honorarios')->assertOk()->assertInertia(fn ($p) => $p->where('totales.retencion', 15250)->where('totales.liquido', 84750));

        // Cuenta bancaria + cartola por HTTP: el cobro por transferencia se concilia solo.
        $this->entrar($u)->post('/finanzas/conciliacion/cuentas', ['banco' => 'Banco Estado', 'numero' => '123-4', 'nombre' => 'Corriente'])->assertRedirect();
        $cuenta = $this->en(fn () => CuentaBancaria::firstOrFail());
        $csv = "Fecha;Descripción;Monto\n".now()->format('d/m/Y').";Transferencia Constructora Andes;".number_format($doc->monto_total, 0, ',', '.')."\n".now()->format('d/m/Y').";Comisión mantención;-3.500\n";
        $archivo = \Illuminate\Http\UploadedFile::fake()->createWithContent('cartola.csv', $csv);
        $this->entrar($u)->post("/finanzas/conciliacion/{$cuenta->id}/importar", ['archivo' => $archivo])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->en(fn () => MovimientoBancario::where('estado', 'conciliado')->count()));
        $comision = $this->en(fn () => MovimientoBancario::where('estado', 'pendiente')->firstOrFail());
        $this->entrar($u)->getJson("/finanzas/conciliacion/movimientos/{$comision->id}/candidatos")->assertOk()->assertJsonCount(0);
        $this->entrar($u)->post("/finanzas/conciliacion/movimientos/{$comision->id}/contabilizar", ['cuenta_id' => $this->en(fn () => $this->conta->cuenta('gastos_bancarios')->id)])->assertSessionHasNoErrors();
        $this->assertSame('conciliado', $this->en(fn () => $comision->fresh()->estado));

        // Sin permiso, el vendedor no entra.
        $this->entrar($this->vendedor)->get('/finanzas/asientos')->assertForbidden();
    }

    /** Tras una petición HTTP el estado de tenencia se reinicia: las lecturas directas van dentro de la empresa. */
    private function en(callable $fn): mixed
    {
        return app(Tenancy::class)->como($this->empresa, $fn);
    }
}
