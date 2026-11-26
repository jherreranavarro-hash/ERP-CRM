<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\TurnoCaja;
use App\Modules\Caja\Turnos;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Models\Trazabilidad as RegistroAuditoria;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Facturacion\Models\Pago;
use App\Modules\Facturacion\Sii\ProveedorDteSimulado;
use App\Modules\Ventas\Cotizaciones;
use App\Modules\Ventas\Models\Cotizacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CotizacionesYCajaTest extends TestCase
{
    private Empresa $empresa;

    private User $ejecutiva;

    private User $cajera;

    private User $jefe;

    private Cotizaciones $cots;

    private Documentos $docs;

    private Turnos $turnos;

    private Caja $caja;

    protected function setUp(): void
    {
        parent::setUp();
        ProveedorDteSimulado::caer(false);
        $this->empresa = $this->empresa();
        $this->ejecutiva = $this->usuario($this->empresa, 'Ejecutiva comercial');
        $this->cajera = $this->usuario($this->empresa, 'Cajera');
        $this->jefe = $this->usuario($this->empresa, 'Gerente');
        app(Tenancy::class)->establecer($this->empresa);
        app(Parametros::class)->proponer('modulos.facturacion', true, 'empresa', 'Pruebas', $this->jefe);
        foreach ([33 => [1, 100], 39 => [1, 100]] as $tipo => [$d, $h]) {
            FolioRango::create(['tipo_dte' => $tipo, 'desde' => $d, 'hasta' => $h, 'siguiente' => $d]);
        }
        $this->caja = Caja::create(['local_id' => Local::create(['codigo' => 'M', 'nombre' => 'Matriz'])->id, 'nombre' => 'Caja 1']);
        $this->cots = app(Cotizaciones::class);
        $this->docs = app(Documentos::class);
        $this->turnos = app(Turnos::class);
    }

    private function cliente(array $attrs = []): Cliente
    {
        return Cliente::firstOrCreate(['rut' => $attrs['rut'] ?? '76543210-3'], $attrs + [
            'rut' => '76543210-3', 'razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción',
            'direccion' => 'Av. Los Leones 100', 'comuna' => 'Providencia', 'lista_precio' => 'mayorista',
        ]);
    }

    private function producto(): Producto
    {
        return Producto::firstOrCreate(['sku' => 'CEM'], ['nombre' => 'Cemento 25 kg', 'precio_neto' => '4990', 'precio_mayorista' => '4500']);
    }

    private function cotizacion(array $datos = [], ?User $quien = null): Cotizacion
    {
        return $this->cots->guardar($datos + ['cliente_id' => $this->cliente()->id, 'forma_pago' => 'contado', 'lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => '10']]], $quien ?? $this->ejecutiva);
    }

    /* ───────────────────────── Cotizaciones ───────────────────────── */

    public function test_cotizacion_usa_la_lista_del_cliente_y_numera_correlativo(): void
    {
        $a = $this->cotizacion();
        $b = $this->cots->guardar(['contacto_nombre' => 'María (sin registrar)', 'forma_pago' => 'contado', 'lineas' => [['descripcion' => 'Flete', 'cantidad' => '1', 'precio_unitario' => '15000']]], $this->ejecutiva);

        $this->assertSame([45000, 8550, 53550], [$a->monto_neto, $a->monto_iva, $a->monto_total]);   // 10 × 4.500 mayorista
        $this->assertSame($a->numero + 1, $b->numero);
        $this->assertSame('borrador', $a->estado);
        $this->assertSame(now()->addDays(15)->toDateString(), $a->valida_hasta->toDateString());
    }

    public function test_sin_cliente_exige_contacto(): void
    {
        $this->expectException(ValidationException::class);
        $this->cots->guardar(['forma_pago' => 'contado', 'lineas' => [['descripcion' => 'X', 'cantidad' => '1', 'precio_unitario' => '100']]], $this->ejecutiva);
    }

    public function test_descuento_sobre_umbral_requiere_aprobacion_de_otra_persona(): void
    {
        $cot = $this->cotizacion(['lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => '10', 'descuento_pct' => '15']]]);
        $this->assertSame('por_aprobar', $cot->estado);

        try {
            $this->cots->enviar($cot, $this->ejecutiva);
            $this->fail('No se envía sin aprobación.');
        } catch (ValidationException) {
        }
        try {
            $this->cots->aprobar($cot, $this->ejecutiva);
            $this->fail('Nadie aprueba lo propio.');
        } catch (ValidationException) {
        }

        $this->cots->aprobar($cot, $this->jefe);
        $this->assertSame('enviada', $this->cots->enviar($cot->fresh(), $this->ejecutiva)->estado);
    }

    public function test_convertir_en_factura_hereda_precios_y_aprobacion(): void
    {
        $cot = $this->cotizacion(['lineas' => [['producto_id' => $this->producto()->id, 'cantidad' => '10', 'descuento_pct' => '15']]]);
        $this->cots->aprobar($cot, $this->jefe);
        $this->cots->enviar($cot->fresh(), $this->ejecutiva);
        $this->cots->aceptar($cot->fresh(), $this->ejecutiva);

        // La lista sube después de cotizar: se respeta el precio cotizado.
        Producto::where('sku', 'CEM')->update(['precio_mayorista' => '5200']);
        $doc = $this->cots->convertir($cot->fresh(), 33, $this->ejecutiva);

        $this->assertSame('borrador', $doc->estado, 'La aprobación de descuento se hereda');
        $this->assertSame($cot->monto_total, $doc->monto_total);
        $this->assertSame($cot->id, $doc->cotizacion_id);
        $this->assertSame(['convertida', $doc->id], [$cot->fresh()->estado, $cot->fresh()->documento_id]);

        $emitido = $this->docs->emitir($doc, $this->cajera);
        $this->assertSame(1, $emitido->folio);
    }

    public function test_convertir_en_boleta_pasa_precios_a_iva_incluido(): void
    {
        $cot = $this->cotizacion();
        $this->cots->enviar($cot, $this->ejecutiva);
        $doc = $this->cots->convertir($cot->fresh(), 39, $this->ejecutiva);

        $this->assertTrue($doc->iva_incluido);
        $this->assertSame('5355', rtrim(rtrim((string) $doc->lineas->first()->precio_unitario, '0'), '.'));   // 4.500 × 1,19
        $this->assertSame(53550, $doc->monto_total);
    }

    public function test_cotizacion_vencida_no_se_envia_ni_se_convierte_hasta_renovarla(): void
    {
        $cot = $this->cotizacion();
        $this->cots->enviar($cot, $this->ejecutiva);
        DB::table('cotizaciones')->where('id', $cot->id)->update(['fecha' => now()->subDays(20)->toDateString(), 'valida_hasta' => now()->subDay()->toDateString()]);
        $cot = $cot->fresh();
        $this->assertSame('vencida', $cot->estadoVisible());

        try {
            $this->cots->convertir($cot, 33, $this->ejecutiva);
            $this->fail('Una cotización vencida no se convierte.');
        } catch (ValidationException) {
        }

        // Renovar: se edita y vuelve a borrador con nueva vigencia.
        $renovada = $this->cots->guardar(['cliente_id' => $cot->cliente_id, 'forma_pago' => 'contado', 'valida_hasta' => now()->addDays(10)->toDateString(),
            'lineas' => [['producto_id' => $cot->lineas->first()->producto_id, 'cantidad' => '10']]], $this->ejecutiva, $cot);
        $this->assertSame('borrador', $renovada->estado);
        $this->assertFalse($renovada->vencida());
    }

    public function test_convertida_queda_congelada_en_bd(): void
    {
        $cot = $this->cotizacion();
        $this->cots->enviar($cot, $this->ejecutiva);
        $this->cots->convertir($cot->fresh(), 33, $this->ejecutiva);

        $this->esperarRechazoBd(fn () => DB::table('cotizaciones')->where('id', $cot->id)->update(['monto_total' => 1, 'monto_neto' => 1, 'monto_iva' => 0]));
        $this->esperarRechazoBd(fn () => DB::table('cotizacion_lineas')->where('cotizacion_id', $cot->id)->delete());
        $this->expectException(ValidationException::class);
        $this->cots->guardar(['cliente_id' => $cot->cliente_id, 'forma_pago' => 'contado', 'lineas' => [['descripcion' => 'X', 'cantidad' => '1', 'precio_unitario' => '1']]], $this->ejecutiva, $cot->fresh());
    }

    public function test_cupo_excedido_no_se_hereda(): void
    {
        $cliente = $this->cliente(['rut' => '96543210-K', 'tiene_credito' => true, 'cupo_credito' => 10000, 'plazo_dias' => 30]);
        $cot = $this->cotizacion(['cliente_id' => $cliente->id, 'forma_pago' => 'credito']);
        $this->assertSame('por_aprobar', $cot->estado);
        $this->cots->aprobar($cot, $this->jefe);
        $this->cots->enviar($cot->fresh(), $this->ejecutiva);

        $doc = $this->cots->convertir($cot->fresh(), 33, $this->ejecutiva);
        $this->assertSame('por_aprobar', $doc->estado, 'La deuda pudo cambiar: el cupo se vuelve a aprobar');
    }

    /* ───────────────────────── Caja ───────────────────────── */

    private function boletaEmitida(int $cantidad = 2): \App\Modules\Facturacion\Models\Documento
    {
        $p = Producto::firstOrCreate(['sku' => 'PAL'], ['nombre' => 'Pala', 'precio_neto' => '10000']);

        return $this->docs->emitir($this->docs->guardar(['tipo_dte' => 39, 'lineas' => [['producto_id' => $p->id, 'cantidad' => (string) $cantidad]]], $this->cajera), $this->cajera);
    }

    public function test_un_turno_abierto_por_usuario_y_por_caja(): void
    {
        $this->turnos->abrir($this->caja, 50000, $this->cajera);

        try {
            $this->turnos->abrir(Caja::create(['local_id' => $this->caja->local_id, 'nombre' => 'Caja 2']), 0, $this->cajera);
            $this->fail('Un usuario no abre dos turnos.');
        } catch (ValidationException) {
        }
        try {
            $this->turnos->abrir($this->caja, 0, $this->jefe);
            $this->fail('Una caja no tiene dos turnos.');
        } catch (ValidationException) {
        }
        // Y la base lo garantiza aunque se salte el servicio.
        $this->esperarRechazoBd(fn () => TurnoCaja::create(['caja_id' => $this->caja->id, 'usuario_id' => $this->jefe->id, 'estado' => 'abierto', 'monto_apertura' => 0, 'abierto_at' => now()]));
    }

    public function test_hora_de_apertura_es_el_instante_real(): void
    {
        // Regresión: timestamptz escrito sin offset se leía como UTC y quedaba corrido 3 horas.
        $turno = $this->turnos->abrir($this->caja, 0, $this->cajera);
        $this->assertEqualsWithDelta(time(), $turno->fresh()->abierto_at->getTimestamp(), 5);
    }

    public function test_cierre_cuadrado(): void
    {
        $turno = $this->turnos->abrir($this->caja, 50000, $this->cajera);
        $b = $this->boletaEmitida();                       // 23.800
        $this->docs->registrarPago($b, 20000, 'efectivo', $this->cajera);
        $this->docs->registrarPago($b, 3800, 'debito', $this->cajera);

        $t = $this->turnos->cerrar($turno, ['efectivo' => 70000, 'debito' => 3800, 'credito' => 0], $this->cajera);

        $this->assertSame(['efectivo' => 70000, 'debito' => 3800, 'credito' => 0], $t->esperado);
        $this->assertSame(0, $t->diferencia);
        $this->assertFalse($t->requiere_aprobacion);
        $this->assertSame(0, RegistroAuditoria::where('accion', 'caja.cierre_con_diferencia')->count());
    }

    public function test_diferencia_por_medio_no_se_compensa_y_se_audita(): void
    {
        $turno = $this->turnos->abrir($this->caja, 0, $this->cajera);
        $b = $this->boletaEmitida();
        $this->docs->registrarPago($b, 10000, 'efectivo', $this->cajera);
        $this->docs->registrarPago($b, 13800, 'debito', $this->cajera);

        // Sobra efectivo y falta débito por lo mismo: el total da 0, pero hay diferencia.
        try {
            $this->turnos->cerrar($turno, ['efectivo' => 11000, 'debito' => 12800, 'credito' => 0], $this->cajera);
            $this->fail('Con diferencia se exige observación.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('observacion', $e->errors());
        }
        $t = $this->turnos->cerrar($turno, ['efectivo' => 11000, 'debito' => 12800, 'credito' => 0], $this->cajera, 'Vuelto mal digitado en POS');

        $this->assertSame(0, $t->diferencia);
        $this->assertTrue($t->requiere_aprobacion, 'Tolerancia 0: cualquier diferencia por medio requiere aprobación');
        $this->assertSame(1, RegistroAuditoria::where('accion', 'caja.cierre_con_diferencia')->count());

        try {
            $this->turnos->aprobarDiferencia($t, $this->cajera);
            $this->fail('Nadie aprueba su propio cuadre.');
        } catch (ValidationException) {
        }
        $this->assertSame($this->jefe->id, $this->turnos->aprobarDiferencia($t, $this->jefe)->aprobado_por);
    }

    public function test_dentro_de_tolerancia_no_requiere_aprobacion_pero_se_audita(): void
    {
        // La tolerancia de una empresa no puede ser más laxa que la global: se sube la global.
        $su = $this->usuario($this->empresa, 'Administrador del sistema', superadmin: true);
        app(Parametros::class)->proponer('caja.tolerancia', 500, 'global', 'Pruebas', $su);
        $this->assertSame(500, (int) app(Parametros::class)->valor('caja.tolerancia'));
        $turno = $this->turnos->abrir($this->caja, 10000, $this->cajera);

        $t = $this->turnos->cerrar($turno, ['efectivo' => 9800, 'debito' => 0, 'credito' => 0], $this->cajera, 'Faltan 200');

        $this->assertSame(-200, $t->diferencia);
        $this->assertFalse($t->requiere_aprobacion);
        $this->assertSame(1, RegistroAuditoria::where('accion', 'caja.cierre_con_diferencia')->count());
    }

    public function test_turno_cerrado_es_inmutable_y_no_recibe_pagos(): void
    {
        $turno = $this->turnos->abrir($this->caja, 0, $this->cajera);
        $b = $this->boletaEmitida();
        $t = $this->turnos->cerrar($turno, ['efectivo' => 0, 'debito' => 0, 'credito' => 0], $this->cajera);

        $this->esperarRechazoBd(fn () => DB::table('turnos_caja')->where('id', $t->id)->update(['declarado' => json_encode(['efectivo' => 999])]));
        $this->esperarRechazoBd(fn () => DB::table('turnos_caja')->where('id', $t->id)->delete());
        $this->esperarRechazoBd(fn () => Pago::create(['documento_id' => $b->id, 'turno_id' => $t->id, 'monto' => 100, 'metodo' => 'efectivo', 'fecha' => now()->toDateString(), 'registrado_por' => $this->cajera->id]));

        // Tras cerrar, cobrar en efectivo exige un turno nuevo.
        $this->expectException(ValidationException::class);
        $this->docs->registrarPago($b, 100, 'efectivo', $this->cajera);
    }

    public function test_pantallas_y_permisos_de_caja(): void
    {
        $this->entrar($this->cajera)->get('/caja/turno')->assertOk();
        $this->entrar($this->cajera)->post('/caja/turno/abrir', ['caja_id' => $this->caja->id, 'monto_apertura' => 1000])->assertSessionHasNoErrors();
        $this->entrar($this->cajera)->post('/caja/turno/cerrar', ['declarado' => ['efectivo' => 1000, 'debito' => 0, 'credito' => 0]])->assertRedirect();

        $turno = app(Tenancy::class)->como($this->empresa, fn () => TurnoCaja::first());
        $this->entrar($this->cajera)->get("/caja/cierres/{$turno->id}")->assertOk();          // el propio
        $this->entrar($this->cajera)->get('/caja/cierres')->assertOk();                         // la cajera tiene caja.*
        $this->entrar($this->cajera)->post("/caja/cierres/{$turno->id}/aprobar")->assertForbidden();
        $this->entrar($this->ejecutiva)->get('/caja/turno')->assertForbidden();
    }

    public function test_flujo_http_de_cotizacion_a_documento(): void
    {
        $cliente = $this->cliente();
        $pid = $this->producto()->id;

        $this->entrar($this->ejecutiva)->post('/ventas/cotizaciones', ['cliente_id' => $cliente->id, 'forma_pago' => 'contado', 'lineas' => [['producto_id' => $pid, 'cantidad' => 4]]])->assertRedirect();
        $cot = app(Tenancy::class)->como($this->empresa, fn () => Cotizacion::latest('id')->first());
        $this->entrar($this->ejecutiva)->get("/ventas/cotizaciones/{$cot->id}")->assertOk();
        $this->entrar($this->ejecutiva)->post("/ventas/cotizaciones/{$cot->id}/enviar")->assertSessionHasNoErrors();
        $this->entrar($this->ejecutiva)->post("/ventas/cotizaciones/{$cot->id}/aceptar")->assertSessionHasNoErrors();
        $this->entrar($this->ejecutiva)->post("/ventas/cotizaciones/{$cot->id}/convertir", ['tipo_dte' => 33])->assertRedirect();

        $this->assertSame('convertida', app(Tenancy::class)->como($this->empresa, fn () => $cot->fresh()->estado));
        $this->entrar($this->ejecutiva)->get('/ventas/cotizaciones?estado=convertida')->assertOk();
        // El agente de contact center ve y crea cotizaciones, pero no las convierte en venta.
        $agente = $this->usuario($this->empresa, 'Agente de contact center', con2fa: false);
        $this->entrar($agente)->get('/buscar/productos?q=ce')->assertOk();
        $this->entrar($agente)->post("/ventas/cotizaciones/{$cot->id}/convertir", ['tipo_dte' => 33])->assertForbidden();
    }
}
