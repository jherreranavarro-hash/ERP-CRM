<?php

namespace Tests\Feature;

use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\FolioRango;
use Tests\TestCase;

/** Permisos iniciales del prompt: ejecutiva arma borradores; cajera emite y cobra; jefe aprueba. */
class PantallasFacturacionTest extends TestCase
{
    public function test_flujo_por_roles(): void
    {
        $e = $this->empresa();
        $ejecutiva = $this->usuario($e, 'Ejecutiva comercial', con2fa: false);
        $cajera = $this->usuario($e, 'Cajera');
        $jefe = $this->usuario($e, 'Gerente');
        $pid = app(Tenancy::class)->como($e, function () use ($jefe) {
            app(Parametros::class)->proponer('modulos.facturacion', true, 'empresa', 'Pruebas', $jefe);
            FolioRango::create(['tipo_dte' => 39, 'desde' => 1, 'hasta' => 10, 'siguiente' => 1]);

            return Producto::create(['sku' => 'P', 'nombre' => 'Pala', 'precio_neto' => '10000'])->id;
        });

        // La ejecutiva arma un borrador con 20 % de descuento (sobre el máximo de 10 %).
        $this->entrar($ejecutiva)->post('/caja/documentos', ['tipo_dte' => 39, 'forma_pago' => 'contado', 'lineas' => [['producto_id' => $pid, 'cantidad' => 1, 'descuento_pct' => 20]]])->assertRedirect();
        $doc = app(Tenancy::class)->como($e, fn () => Documento::latest('id')->first());
        $this->assertSame('por_aprobar', $doc->estado);
        $this->entrar($ejecutiva)->post("/caja/documentos/{$doc->id}/emitir")->assertForbidden();

        // La cajera no aprueba; el jefe sí.
        $this->entrar($cajera)->post("/caja/documentos/{$doc->id}/aprobar")->assertForbidden();
        $this->entrar($jefe)->post("/caja/documentos/{$doc->id}/aprobar")->assertSessionHasNoErrors();

        // La cajera emite y cobra.
        $this->entrar($cajera)->post("/caja/documentos/{$doc->id}/emitir")->assertSessionHasNoErrors();
        $doc = app(Tenancy::class)->como($e, fn () => $doc->fresh());
        $this->assertSame([1, 'emitido', 9520], [$doc->folio, $doc->estado, $doc->monto_total]);   // 11.900 − 20 % = 9.520
        $this->entrar($cajera)->post("/caja/documentos/{$doc->id}/pagos", ['monto' => 9520, 'metodo' => 'efectivo'])->assertSessionHasNoErrors();
        $this->assertSame('pagado', app(Tenancy::class)->como($e, fn () => $doc->fresh()->estado));
    }

    public function test_las_pantallas_cargan(): void
    {
        $e = $this->empresa();
        $u = $this->usuario($e, 'Administrador del sistema', superadmin: true);

        foreach (['/caja/documentos', '/caja/documentos/crear', '/configuracion/clientes', '/configuracion/productos', '/configuracion/folios', '/finanzas/libro-de-ventas', '/finanzas/cuentas-por-cobrar'] as $ruta) {
            $this->entrar($u)->get($ruta)->assertOk();
        }
    }

    public function test_cliente_con_rut_invalido_se_rechaza(): void
    {
        $u = $this->usuario($this->empresa(), 'Administrador del sistema', superadmin: true);

        $this->entrar($u)->post('/configuracion/clientes', ['rut' => '77654321-K', 'razon_social' => 'X', 'lista_precio' => 'meson'])->assertSessionHasErrors('rut');
        $this->entrar($u)->post('/configuracion/clientes', ['rut' => '77.654.321-7', 'razon_social' => 'X', 'lista_precio' => 'meson'])->assertSessionHasNoErrors();
    }
}
