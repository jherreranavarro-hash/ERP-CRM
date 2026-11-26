<?php

namespace Tests\Feature;

use App\Modules\Comercial\Models\Categoria;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Dashboard\Tablero;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Facturacion\Sii\ProveedorDteSimulado;
use App\Modules\Inventario\Recepciones;
use App\Modules\Ventas\Models\Cotizacion;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_kpis_margen_actividad_cache_y_cuenta_corriente(): void
    {
        $empresa = $this->empresa();
        $jefe = $this->usuario($empresa, 'Gerente');
        $vendedora = $this->usuario($empresa, 'Ejecutiva comercial', con2fa: false);
        $tenancy = app(Tenancy::class);
        $tenancy->establecer($empresa);
        $local = Local::create(['codigo' => 'M', 'nombre' => 'Matriz']);
        FolioRango::create(['tipo_dte' => 33, 'desde' => 1, 'hasta' => 100, 'siguiente' => 1]);
        ProveedorDteSimulado::caer(false);
        app(Parametros::class)->proponer('modulos.facturacion', true, 'empresa', 'Pruebas', $jefe);

        $cat = Categoria::create(['nombre' => 'Cementos']);
        $cemento = Producto::create(['sku' => 'CEM', 'nombre' => 'Cemento', 'precio_neto' => '5000', 'costo' => '3000', 'categoria_id' => $cat->id]);
        $r = app(Recepciones::class)->guardar(['local_id' => $local->id, 'proveedor_nombre' => 'P', 'lineas' => [['producto_id' => $cemento->id, 'cantidad' => '100', 'costo_unitario' => '3000']]], $jefe);
        app(Recepciones::class)->confirmar($r, $jefe);
        $andes = Cliente::create(['rut' => '76543210-3', 'razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción', 'direccion' => 'Av. 1', 'comuna' => 'Providencia', 'lista_precio' => 'meson',
            'tiene_credito' => true, 'cupo_credito' => 5000000, 'plazo_dias' => 30]);
        Cliente::create(['rut' => '11111111-1', 'razon_social' => 'Juan Pérez', 'giro' => 'Particular', 'direccion' => 'Calle 2', 'comuna' => 'Maipú', 'lista_precio' => 'meson']);

        $docs = app(Documentos::class);
        $doc = $docs->emitir($docs->guardar(['tipo_dte' => 33, 'cliente_id' => $andes->id, 'local_id' => $local->id, 'forma_pago' => 'credito', 'lineas' => [['producto_id' => $cemento->id, 'cantidad' => '20']]], $vendedora), $vendedora);
        foreach (['enviada', 'convertida', 'rechazada', 'borrador'] as $i => $estado) {
            Cotizacion::create(['numero' => $i + 1, 'estado' => $estado, 'cliente_id' => $andes->id, 'fecha' => now()->toDateString(), 'valida_hasta' => now()->addDays(10)->toDateString(),
                'tasa_iva' => '19', 'monto_neto' => 100000, 'monto_iva' => 19000, 'monto_total' => 119000, 'creado_por' => $vendedora->id, 'documento_id' => $estado === 'convertida' ? $doc->id : null]);
        }

        $t = app(Tablero::class);
        [$desde, $hasta] = Tablero::periodo(null, null);
        $crm = $t->crm($desde, $hasta);
        $k = $crm['kpis'];
        $this->assertSame(100000, $k['ventas']);                         // 20 × 5.000 neto
        $this->assertSame(40000, $k['margen']);                          // costo 20 × 3.000
        $this->assertSame(40.0, $k['margen_pct']);
        $this->assertSame(3, $k['cotizaciones']);                        // el borrador no cuenta como emitida
        $this->assertSame(33.3, $k['conversion']);
        $this->assertSame(50.0, $k['cobertura']);                        // 1 de 2 clientes compró
        $this->assertSame('Cementos', $crm['categorias'][0]['categoria']);
        $this->assertSame(100000, collect($crm['serie'])->firstWhere('dia', now()->toDateString())['ventas']);
        $this->assertSame(5, $crm['actividad']['ejecutivos'][0]['total']); // 1 documento + 4 cotizaciones
        $this->assertSame(40000, $k['resultado']);                       // venta 100.000 menos costo de venta 60.000, contabilizados

        // Caché: la segunda lectura no recalcula; una nueva emisión la invalida.
        $this->assertSame($crm['calculado'], $t->crm($desde, $hasta)['calculado']);
        $docs->emitir($docs->guardar(['tipo_dte' => 33, 'cliente_id' => $andes->id, 'local_id' => $local->id, 'lineas' => [['producto_id' => $cemento->id, 'cantidad' => '10']]], $vendedora), $vendedora);
        $this->assertSame(150000, $t->crm($desde, $hasta)['kpis']['ventas']);

        $com = $t->comercial($desde, $hasta);
        $fila = collect($com['ejecutivos'])->firstWhere('id', $vendedora->id);
        $this->assertSame(150000, $fila['ventas']);
        $this->assertSame(75000, $fila['ticket']);
        $this->assertSame(100000, $fila['pipeline']);                    // la enviada sigue abierta
        $this->assertSame('Constructora Andes SpA', $com['clientes'][0]['nombre']);

        // Un período invertido o de más de un año se normaliza.
        [$d, $h] = Tablero::periodo('2026-12-31', '2024-01-01');
        $this->assertTrue(CarbonImmutable::parse('2026-12-31')->eq($h));
        $this->assertTrue($d->gt(CarbonImmutable::parse('2025-12-31')));

        // Cuenta corriente: cargo, pago y saldo.
        $docs->registrarPago($doc->fresh(), 50000, 'transferencia', $jefe, 'TRX-1');
        $this->entrar($jefe)->get('/dashboard')->assertOk();
        $this->entrar($vendedora)->get('/ventas/dashboard?desde=2026-01-01&hasta=2026-12-31')->assertOk();
        $this->entrar($vendedora)->get('/ventas/cuentas-corrientes?filtro=con_saldo')->assertOk()
            ->assertInertia(fn ($p) => $p->where('clientes.data.0.nombre', 'Constructora Andes SpA')->where('clientes.data.0.saldo', 119000 + 59500 - 50000));
        $this->entrar($vendedora)->get("/ventas/cuentas-corrientes/{$andes->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->where('resumen.abonado', 50000)->where('movimientos.0.saldo', 128500));
        $this->assertStringContainsString('Constructora Andes', $this->entrar($vendedora)->get('/ventas/cuentas-corrientes?exportar=1')->streamedContent());
    }
}
