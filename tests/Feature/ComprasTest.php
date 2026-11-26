<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Compras\FacturasProveedor;
use App\Modules\Compras\Models\FacturaProveedor;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\Proveedor;
use App\Modules\Compras\OrdenesCompra;
use App\Modules\Compras\PagosProveedor;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Inventario\Models\Recepcion;
use App\Modules\Inventario\Models\Stock;
use App\Modules\Inventario\Recepciones;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComprasTest extends TestCase
{
    private Empresa $empresa;

    private User $comprador;

    private User $jefe;

    private User $finanzas;

    private Local $local;

    private Producto $cemento;

    private Producto $fierro;

    private Proveedor $proveedor;

    private OrdenesCompra $oc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->empresa();
        $this->comprador = $this->usuario($this->empresa, 'Comprador');
        $this->jefe = $this->usuario($this->empresa, 'Gerente');
        $this->finanzas = $this->usuario($this->empresa, 'Analista de Finanzas');
        app(Tenancy::class)->establecer($this->empresa);
        $this->local = Local::create(['codigo' => 'M', 'nombre' => 'Matriz']);
        $this->cemento = Producto::create(['sku' => 'CEM', 'nombre' => 'Cemento 25 kg', 'precio_neto' => '5000', 'costo' => '3000']);
        $this->fierro = Producto::create(['sku' => 'FIE', 'nombre' => 'Fierro 8 mm', 'precio_neto' => '3000', 'costo' => '2000']);
        $this->proveedor = Proveedor::create(['rut' => '96512340-7', 'razon_social' => 'Cementos del Sur S.A.', 'plazo_pago_dias' => 45]);
        $this->oc = app(OrdenesCompra::class);
    }

    private function orden(array $lineas, array $cotizaciones = []): OrdenCompra
    {
        return $this->oc->guardar(['proveedor_id' => $this->proveedor->id, 'local_id' => $this->local->id, 'lineas' => $lineas, 'cotizaciones' => $cotizaciones], $this->comprador);
    }

    private function ordenAprobada(): OrdenCompra
    {
        $oc = $this->orden([
            ['producto_id' => $this->cemento->id, 'cantidad' => '50', 'costo_unitario' => '3000'],
            ['producto_id' => $this->fierro->id, 'cantidad' => '20', 'costo_unitario' => '2000'],
        ]);

        return $this->oc->enviar($oc, $this->comprador)->load('lineas');
    }

    private function recibir(OrdenCompra $oc, array $cantidades): Recepcion
    {
        $lineas = [];
        foreach ($oc->lineas as $i => $l) {
            $lineas[] = ['producto_id' => $l->producto_id, 'orden_compra_linea_id' => $l->id, 'cantidad' => $cantidades[$i] ?? '0', 'costo_unitario' => (string) $l->costo_unitario];
        }
        $r = app(Recepciones::class)->guardar(['orden_compra_id' => $oc->id, 'local_id' => 0, 'proveedor_nombre' => '', 'lineas' => $lineas], $this->comprador);

        return app(Recepciones::class)->confirmar($r, $this->comprador);
    }

    private function falla(callable $fn, string $msg = 'Debió rechazarse.'): void
    {
        try {
            $fn();
            $this->fail($msg);
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    /** Factura de las líneas de la OC con las cantidades y costos dados; neto = suma de líneas salvo que se indique. */
    private function facturar(OrdenCompra $oc, array $lineas, int $folio = 1001, ?int $neto = null): FacturaProveedor
    {
        $filas = [];
        $suma = 0;
        foreach ($lineas as $i => [$cant, $costo]) {
            $filas[] = ['orden_compra_linea_id' => $oc->lineas[$i]->id, 'cantidad' => $cant, 'costo_unitario' => $costo];
            $suma += (int) round($cant * $costo);
        }
        $neto ??= $suma;
        $iva = (int) round($neto * 0.19);

        return app(FacturasProveedor::class)->registrar([
            'proveedor_id' => $this->proveedor->id, 'tipo_dte' => 33, 'folio' => $folio, 'fecha_emision' => '2026-11-20', 'orden_compra_id' => $oc->id,
            'monto_neto' => $neto, 'monto_iva' => $iva, 'monto_total' => $neto + $iva, 'lineas' => $filas,
        ], $this->comprador);
    }

    /* ───────────── Órdenes de compra ───────────── */

    public function test_oc_bajo_el_monto_queda_aprobada_y_sobre_el_monto_la_aprueba_otra_persona(): void
    {
        $chica = $this->ordenAprobada();
        $this->assertSame(['aprobada', 190000, 36100, 226100], [$chica->estado, $chica->monto_neto, $chica->monto_iva, $chica->monto_total]);
        $this->assertNull($chica->aprobado_por);

        $grande = $this->oc->enviar($this->orden([['producto_id' => $this->cemento->id, 'cantidad' => '200', 'costo_unitario' => '3000']]), $this->comprador);
        $this->assertSame('por_aprobar', $grande->estado);
        $this->assertStringContainsString('sin aprobación', $grande->motivos[0]);
        $this->falla(fn () => $this->oc->aprobar($grande, $this->comprador), 'Nadie aprueba su propia OC.');
        $this->falla(fn () => app(Recepciones::class)->guardar(['orden_compra_id' => $grande->id, 'lineas' => [['producto_id' => $this->cemento->id, 'orden_compra_linea_id' => $grande->lineas()->value('id'), 'cantidad' => '1', 'costo_unitario' => '3000']]], $this->comprador), 'No se recibe una OC sin aprobar.');

        $ok = $this->oc->aprobar($grande, $this->jefe);
        $this->assertSame(['aprobada', $this->jefe->id], [$ok->estado, $ok->aprobado_por]);
    }

    public function test_sobre_el_monto_exige_cotizaciones_y_elegir_la_cara_pide_aprobacion(): void
    {
        $lineas = [['producto_id' => $this->cemento->id, 'cantidad' => '400', 'costo_unitario' => '3000']];
        $sin = $this->orden($lineas);
        $this->falla(fn () => $this->oc->enviar($sin, $this->comprador), 'Faltan cotizaciones.');

        $con = $this->oc->guardar(['proveedor_id' => $this->proveedor->id, 'local_id' => $this->local->id, 'lineas' => $lineas, 'cotizaciones' => [
            ['proveedor_nombre' => 'Cementos del Sur', 'monto_neto' => 1200000, 'elegida' => true],
            ['proveedor_nombre' => 'Otro proveedor', 'monto_neto' => 1100000],
        ]], $this->comprador, $sin);
        $enviada = $this->oc->enviar($con, $this->comprador);
        $this->assertSame('por_aprobar', $enviada->estado);
        $this->assertCount(2, $enviada->motivos);
        $this->assertStringContainsString('no es la más barata', $enviada->motivos[1]);

        $rech = $this->oc->rechazar($enviada, $this->jefe, 'Comprar al más barato');
        $this->assertSame('rechazada', $rech->estado);
    }

    /* ───────────── Recepción contra OC ───────────── */

    public function test_recepcion_contra_oc_descuenta_lo_pendiente_y_no_permite_recibir_de_mas(): void
    {
        $oc = $this->ordenAprobada();
        $this->recibir($oc, ['30', '20']);
        $oc->refresh()->load('lineas');
        $this->assertSame('parcial', $oc->estado);
        $this->assertSame('30.000000', (string) $oc->lineas[0]->cantidad_recibida);
        $this->assertSame('30.000000', (string) Stock::where('producto_id', $this->cemento->id)->value('cantidad'));
        $rec = Recepcion::latest('id')->first();
        $this->assertSame([$oc->id, $this->proveedor->id, 'Cementos del Sur S.A.'], [$rec->orden_compra_id, $rec->proveedor_id, $rec->proveedor_nombre]);

        $this->falla(fn () => $this->recibir($oc, ['21']), 'Solo quedan 20 pendientes.');
        $this->falla(fn () => $this->oc->anular($oc, $this->jefe, 'x'), 'Con recepciones se cierra, no se anula.');

        $this->recibir($oc, ['20']);
        $this->assertSame('recibida', $oc->fresh()->estado);
    }

    public function test_dos_borradores_no_reciben_juntos_mas_de_lo_pedido(): void
    {
        $oc = $this->ordenAprobada();
        $linea = fn ($c) => ['orden_compra_id' => $oc->id, 'lineas' => [['producto_id' => $this->cemento->id, 'orden_compra_linea_id' => $oc->lineas[0]->id, 'cantidad' => $c, 'costo_unitario' => '3000']]];
        $a = app(Recepciones::class)->guardar($linea('40'), $this->comprador);
        $b = app(Recepciones::class)->guardar($linea('40'), $this->comprador);
        app(Recepciones::class)->confirmar($a, $this->comprador);
        $this->falla(fn () => app(Recepciones::class)->confirmar($b, $this->comprador), 'Al confirmar se revalida contra lo pendiente.');

        $this->falla(fn () => $this->oc->cerrar($oc, $this->jefe), 'Hay un borrador pendiente.');
        $b->lineas()->delete();
        $b->delete();
        $this->assertSame('cerrada', $this->oc->cerrar($oc, $this->jefe)->estado);
    }

    /* ───────────── Facturas de proveedor (3-way match) ───────────── */

    public function test_factura_que_calza_pasa_a_pagar_con_vencimiento_del_proveedor(): void
    {
        $oc = $this->ordenAprobada();
        $this->recibir($oc, ['50', '20']);
        $f = $this->facturar($oc, [[50, 3000], [20, 2000]]);

        $this->assertSame(['por_pagar', 226100, null], [$f->estado, $f->saldo, $f->diferencias]);
        $this->assertSame('2027-01-04', $f->fecha_vencimiento->toDateString());   // 45 días
        $this->assertSame('50.000000', (string) $oc->lineas[0]->fresh()->cantidad_facturada);
        $this->assertSame(226100, $this->proveedor->deuda());

        $this->falla(fn () => $this->facturar($oc, [[1, 3000]]), 'Folio repetido.');
    }

    public function test_factura_con_diferencias_queda_por_revisar_y_la_aprueba_otra_persona(): void
    {
        $oc = $this->ordenAprobada();
        $this->recibir($oc, ['30', '20']);
        // Factura 50 cementos (solo llegaron 30) y a mayor precio.
        $f = $this->facturar($oc, [[50, 3200], [20, 2000]]);
        $this->assertSame('por_revisar', $f->estado);
        $this->assertCount(2, $f->diferencias);
        $this->assertStringContainsString('precio', $f->diferencias[0]);
        $this->assertStringContainsString('recibido sin facturar', $f->diferencias[1]);
        $this->assertSame('0.000000', (string) $oc->lineas[0]->fresh()->cantidad_facturada);

        $this->falla(fn () => app(FacturasProveedor::class)->aprobar($f, $this->comprador), 'Cuatro ojos.');
        $ok = app(FacturasProveedor::class)->aprobar($f, $this->finanzas);
        $this->assertSame('por_pagar', $ok->estado);
        // Lo facturado nunca supera lo recibido.
        $this->assertSame('30.000000', (string) $oc->lineas[0]->fresh()->cantidad_facturada);
    }

    public function test_montos_que_no_cuadran_y_factura_sin_oc(): void
    {
        $oc = $this->ordenAprobada();
        $this->recibir($oc, ['50', '20']);
        // IVA que no corresponde al neto: se rechaza al registrar.
        $this->falla(fn () => app(FacturasProveedor::class)->registrar([
            'proveedor_id' => $this->proveedor->id, 'tipo_dte' => 33, 'folio' => 9, 'fecha_emision' => '2026-11-20',
            'monto_neto' => 100000, 'monto_iva' => 1000, 'monto_total' => 101000,
        ], $this->comprador));

        // Líneas que no suman el neto declarado.
        $f = $this->facturar($oc, [[50, 3000], [20, 2000]], 1002, 150000);
        $this->assertSame('por_revisar', $f->estado);
        $this->assertStringContainsString('suman', $f->diferencias[0]);

        $sinOc = app(FacturasProveedor::class)->registrar([
            'proveedor_id' => $this->proveedor->id, 'tipo_dte' => 34, 'folio' => 77, 'fecha_emision' => '2026-11-20', 'fecha_vencimiento' => '2026-11-30',
            'monto_neto' => 0, 'monto_exento' => 50000, 'monto_iva' => 0, 'monto_total' => 50000,
        ], $this->comprador);
        $this->assertSame(['por_revisar', ['Factura sin orden de compra.']], [$sinOc->estado, $sinOc->diferencias]);
        $this->assertSame('rechazada', app(FacturasProveedor::class)->rechazar($sinOc, $this->finanzas, 'No corresponde')->estado);
    }

    public function test_los_montos_de_una_factura_registrada_no_cambian(): void
    {
        $oc = $this->ordenAprobada();
        $this->recibir($oc, ['50', '20']);
        $f = $this->facturar($oc, [[50, 3000], [20, 2000]]);
        $this->expectException(QueryException::class);
        DB::table('facturas_proveedor')->where('id', $f->id)->update(['monto_total' => 1]);
    }

    /* ───────────── Pagos ───────────── */

    public function test_pagos_rebajan_el_saldo_y_sobre_el_umbral_los_aprueba_otra_persona(): void
    {
        $oc = $this->ordenAprobada();
        $this->recibir($oc, ['50', '20']);
        $f = $this->facturar($oc, [[50, 3000], [20, 2000]]);
        $pagos = app(PagosProveedor::class);

        $p1 = $pagos->registrar($f, ['monto' => 126100, 'fecha' => '2026-11-25', 'metodo' => 'transferencia', 'referencia' => 'TRF-1'], $this->finanzas);
        $this->assertSame('aplicado', $p1->estado);
        $this->assertSame([100000, 'por_pagar'], [$f->fresh()->saldo, $f->fresh()->estado]);
        $this->falla(fn () => $pagos->registrar($f, ['monto' => 100001, 'fecha' => '2026-11-25', 'metodo' => 'cheque'], $this->finanzas), 'No se paga más que el saldo.');

        $cambio = app(Parametros::class)->proponer('aprobaciones.pago_proveedor_monto', 50000, 'empresa', 'Pruebas', $this->jefe);
        app(Parametros::class)->aprobar($cambio, $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true));
        $p2 = $pagos->registrar($f, ['monto' => 100000, 'fecha' => '2026-11-26', 'metodo' => 'transferencia'], $this->finanzas);
        $this->assertSame('por_aprobar', $p2->estado);
        $this->assertSame(100000, $f->fresh()->saldo);
        $this->falla(fn () => $pagos->registrar($f, ['monto' => 1, 'fecha' => '2026-11-26', 'metodo' => 'cheque'], $this->finanzas), 'El saldo está comprometido en un pago por aprobar.');
        $this->falla(fn () => $pagos->aprobar($p2, $this->finanzas), 'Cuatro ojos.');

        $pagos->aprobar($p2, $this->jefe);
        $this->assertSame([0, 'pagada'], [$f->fresh()->saldo, $f->fresh()->estado]);
        $this->assertSame(0, $this->proveedor->deuda());

        $this->expectException(QueryException::class);
        DB::table('pagos_proveedor')->where('id', $p1->id)->update(['monto' => 1]);
    }

    /* ───────────── Pantallas y permisos (HTTP) ───────────── */

    public function test_flujo_completo_por_pantallas_y_roles(): void
    {
        $bodeguero = $this->usuario($this->empresa, 'Bodeguero');
        $this->entrar($this->comprador)->post('/configuracion/proveedores', ['rut' => '11.111.111-2', 'razon_social' => 'X', 'plazo_pago_dias' => 30])->assertSessionHasErrors('rut');
        $this->entrar($this->comprador)->post('/configuracion/proveedores', ['rut' => '76.086.428-5', 'razon_social' => 'Ferretería Mayorista Ltda.', 'plazo_pago_dias' => 30, 'activo' => true])->assertSessionHasNoErrors();
        $this->entrar($this->comprador)->getJson('/buscar/proveedores?q=Mayorista')->assertOk()->assertJsonCount(1);

        $this->entrar($this->comprador)->post('/compras/ordenes', ['proveedor_id' => $this->proveedor->id, 'local_id' => $this->local->id, 'lineas' => [
            ['producto_id' => $this->cemento->id, 'cantidad' => 10, 'costo_unitario' => 3000],
        ]])->assertRedirect();
        $oc = app(Tenancy::class)->como($this->empresa, fn () => OrdenCompra::latest('id')->first());
        $this->entrar($this->comprador)->get("/compras/ordenes/{$oc->id}/editar")->assertOk();
        $this->entrar($this->comprador)->post("/compras/ordenes/{$oc->id}/enviar")->assertSessionHasNoErrors();
        $this->entrar($this->comprador)->get("/compras/ordenes/{$oc->id}")->assertOk();

        // El bodeguero recibe contra la OC.
        $this->entrar($bodeguero)->get("/inventario/recepciones/crear?orden_compra_id={$oc->id}")->assertOk();
        $linea = app(Tenancy::class)->como($this->empresa, fn () => $oc->lineas()->value('id'));
        $this->entrar($bodeguero)->post('/inventario/recepciones', ['orden_compra_id' => $oc->id, 'lineas' => [
            ['producto_id' => $this->cemento->id, 'orden_compra_linea_id' => $linea, 'cantidad' => 10, 'costo_unitario' => 3000],
        ]])->assertRedirect();
        $rec = app(Tenancy::class)->como($this->empresa, fn () => Recepcion::latest('id')->first());
        $this->entrar($bodeguero)->post("/inventario/recepciones/{$rec->id}/confirmar")->assertSessionHasNoErrors();
        $this->entrar($bodeguero)->get("/compras/ordenes/{$oc->id}")->assertForbidden();

        // Factura y pago.
        $this->entrar($this->comprador)->get("/compras/facturas/crear?orden_compra_id={$oc->id}")->assertOk();
        $this->entrar($this->comprador)->post('/compras/facturas', ['proveedor_id' => $this->proveedor->id, 'orden_compra_id' => $oc->id, 'tipo_dte' => 33, 'folio' => 555,
            'fecha_emision' => now()->toDateString(), 'monto_neto' => 30000, 'monto_iva' => 5700, 'monto_total' => 35700,
            'lineas' => [['orden_compra_linea_id' => $linea, 'cantidad' => 10, 'costo_unitario' => 3000]]])->assertRedirect();
        $f = app(Tenancy::class)->como($this->empresa, fn () => FacturaProveedor::latest('id')->first());
        $this->assertSame('por_pagar', $f->estado);
        $this->entrar($this->comprador)->post("/finanzas/cuentas-por-pagar/{$f->id}/pagos", ['monto' => 35700, 'fecha' => now()->toDateString(), 'metodo' => 'transferencia'])->assertForbidden();
        $this->entrar($this->finanzas)->get("/compras/facturas/{$f->id}")->assertOk();
        $this->entrar($this->finanzas)->get('/finanzas/cuentas-por-pagar')->assertOk();
        $this->entrar($this->finanzas)->post("/finanzas/cuentas-por-pagar/{$f->id}/pagos", ['monto' => 35700, 'fecha' => now()->toDateString(), 'metodo' => 'transferencia'])->assertSessionHasNoErrors();
        $this->assertSame('pagada', app(Tenancy::class)->como($this->empresa, fn () => $f->fresh()->estado));
        $this->entrar($this->finanzas)->get('/finanzas/libro-de-compras?exportar=1')->assertOk();
    }
}
