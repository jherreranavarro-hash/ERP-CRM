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
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Facturacion\Sii\ProveedorDteSimulado;
use App\Modules\Finanzas\Contabilidad;
use App\Modules\Finanzas\Models\Asiento;
use App\Modules\Inventario\Recepciones;
use App\Modules\RRHH\Asistencia;
use App\Modules\RRHH\Ausencias;
use App\Modules\RRHH\Contratos;
use App\Modules\RRHH\Finiquitos;
use App\Modules\RRHH\Models\Comision;
use App\Modules\RRHH\Models\Contrato;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Liquidacion;
use App\Modules\RRHH\Models\Marca;
use App\Modules\RRHH\Models\PlantillaContrato;
use App\Modules\RRHH\Models\Turno;
use App\Modules\RRHH\MotorNomina;
use App\Modules\RRHH\Nominas;
use App\Modules\RRHH\Palabras;
use App\Modules\RRHH\Personal;
use App\Modules\RRHH\Prestamos;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RrhhTest extends TestCase
{
    private Empresa $empresa;

    private User $rrhh;

    private User $jefe;

    private Local $local;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->empresa();
        $this->rrhh = $this->usuario($this->empresa, 'Analista de RRHH');
        $this->jefe = $this->usuario($this->empresa, 'Gerente');
        app(Tenancy::class)->establecer($this->empresa);
        $this->local = Local::create(['codigo' => 'M', 'nombre' => 'Matriz']);
    }

    private function falla(callable $fn, ?string $campo = null): void
    {
        try {
            $fn();
            $this->fail('Debió rechazarse.');
        } catch (ValidationException $e) {
            if ($campo) {
                $this->assertArrayHasKey($campo, $e->errors());
            }
            $this->addToAssertionCount(1);
        }
    }

    private function empleado(array $e = [], array $c = []): Empleado
    {
        $emp = app(Personal::class)->guardar($e + ['rut' => '12.345.678-5', 'nombres' => 'Ana', 'apellidos' => 'Rojas', 'cargo' => 'Vendedora', 'fecha_ingreso' => now()->subYears(2)->toDateString(),
            'afp' => 'habitat', 'salud' => 'fonasa', 'local_id' => $this->local->id]);
        $svc = app(Contratos::class);
        $ct = $svc->guardar($emp, $c + ['tipo' => 'indefinido', 'cargo' => $emp->cargo, 'jornada_horas' => 42, 'sueldo_base' => 800000, 'gratificacion' => true,
            'colacion' => 50000, 'movilizacion' => 40000, 'fecha_inicio' => $emp->fecha_ingreso->toDateString(), 'texto' => 'Contrato de prueba'], $this->rrhh);
        $svc->sincronizarFirma($svc->enviarAFirma($ct, $this->rrhh), $this->rrhh);

        return $emp->fresh();
    }

    public function test_motor_calcula_leyes_sociales_e_impuesto(): void
    {
        $m = app(MotorNomina::class);
        $ind = $m->indicadores();
        $e = new Empleado(['afp' => 'habitat', 'salud' => 'fonasa', 'plan_isapre_uf' => 0]);
        $c = new Contrato(['tipo' => 'indefinido', 'jornada_horas' => 42, 'sueldo_base' => 800000, 'gratificacion' => true, 'colacion' => 50000, 'movilizacion' => 40000]);
        $r = $m->calcular($e, $c, ['dias_trabajados' => 30, 'horas_extra' => 0, 'comisiones' => 0], $ind);
        // Gratificación 25% (200.000, bajo el tope de 4,75 IMM / 12); imponible 1.000.000.
        $this->assertSame(1000000, $r['imponible']);
        $this->assertSame(90000, $r['no_imponible']);
        $desc = collect($r['descuentos'])->pluck('monto', 'concepto');
        $this->assertSame(112700, $desc['AFP Habitat (11.27%)']);
        $this->assertSame(70000, $desc['Fonasa (7%)']);
        $this->assertSame(6000, $desc['Seguro de cesantía (0.6%)']);
        $this->assertSame(811300, $r['tributable']);
        $this->assertSame(0, $r['impuesto']);                          // bajo 13,5 UTM: exento
        $this->assertSame(1090000 - 188700, $r['liquido']);

        // Sueldo alto sin gratificación: tramo del 4%.
        $c2 = new Contrato(['tipo' => 'plazo_fijo', 'jornada_horas' => 42, 'sueldo_base' => 2000000, 'gratificacion' => false, 'colacion' => 0, 'movilizacion' => 0]);
        $r2 = $m->calcular($e, $c2, ['dias_trabajados' => 30, 'horas_extra' => 0, 'comisiones' => 0], $ind);
        $this->assertSame(2000000 - 225400 - 140000, $r2['tributable']);   // plazo fijo: sin cesantía del trabajador
        $this->assertSame((int) round(1634600 * 0.04 - 0.54 * 69500), $r2['impuesto']);

        // Tabla continua en cada límite y horas extra con recargo del 50%.
        foreach ([13.5, 30, 50, 70, 90, 120, 310] as $limite) {
            $t = (int) round($limite * 69500);
            $this->assertLessThanOrEqual(2, abs($m->impuesto($t + 1, 69500) - $m->impuesto($t, 69500)), "Salto en {$limite} UTM");
        }
        $r3 = $m->calcular($e, $c, ['dias_trabajados' => 15, 'horas_extra' => '10', 'comisiones' => 0], $ind);
        $this->assertSame(400000, $r3['haberes'][0]['monto']);
        $this->assertSame((int) round(800000 * 28 / (30 * 4 * 42) * 1.5 * 10), $r3['haberes'][1]['monto']);
        $this->assertSame('quinientos treinta y nueve mil pesos', Palabras::pesos(539000));
        $this->assertSame('un millón de pesos', Palabras::pesos(1000000));
    }

    public function test_contrato_desde_plantilla_firma_y_reemplaza_al_anterior(): void
    {
        $p = PlantillaContrato::create(['nombre' => 'Indefinido', 'cuerpo' => 'Entre {{empresa}} y {{nombre}}, RUT {{rut}}, por {{sueldo}} ({{sueldo_palabras}}).']);
        $e = $this->empleado();
        $svc = app(Contratos::class);
        $this->falla(fn () => $svc->guardar($e, ['tipo' => 'indefinido', 'cargo' => 'X', 'jornada_horas' => 45, 'sueldo_base' => 900000, 'fecha_inicio' => now()->toDateString()], $this->rrhh), 'jornada_horas');
        $this->falla(fn () => $svc->guardar($e, ['tipo' => 'indefinido', 'cargo' => 'X', 'jornada_horas' => 42, 'sueldo_base' => 300000, 'fecha_inicio' => now()->toDateString()], $this->rrhh), 'sueldo_base');
        $this->falla(fn () => $svc->guardar($e, ['tipo' => 'plazo_fijo', 'cargo' => 'X', 'jornada_horas' => 42, 'sueldo_base' => 900000, 'fecha_inicio' => now()->toDateString()], $this->rrhh), 'fecha_termino');
        // Jornada parcial: mínimo proporcional.
        $parcial = $svc->guardar($e, ['tipo' => 'indefinido', 'cargo' => 'Part time', 'jornada_horas' => 21, 'sueldo_base' => 269500, 'fecha_inicio' => now()->toDateString()], $this->rrhh);
        $parcial->delete();

        $nuevo = $svc->guardar($e, ['tipo' => 'indefinido', 'cargo' => 'Jefa de local', 'jornada_horas' => 42, 'sueldo_base' => 1200000, 'fecha_inicio' => now()->toDateString(), 'plantilla_id' => $p->id], $this->rrhh);
        $this->assertStringContainsString('Ana Rojas, RUT 12.345.678-5, por $1.200.000 (un millón doscientos mil pesos)', $nuevo->texto);
        $svc->sincronizarFirma($svc->enviarAFirma($nuevo, $this->rrhh), $this->rrhh);
        $this->assertSame('vigente', $nuevo->fresh()->estado);
        $this->assertSame(1, Contrato::where('empleado_id', $e->id)->where('estado', 'vigente')->count());
        $this->assertSame('terminado', Contrato::where('empleado_id', $e->id)->where('cargo', 'Vendedora')->value('estado'));
        $this->assertSame('Jefa de local', $e->fresh()->cargo);
    }

    public function test_documentos_se_guardan_cifrados(): void
    {
        $e = $this->empleado();
        $archivo = \Illuminate\Http\UploadedFile::fake()->createWithContent('cedula.pdf', '%PDF-1.4 contenido sensible');
        $doc = app(Personal::class)->adjuntar($e, $archivo, 'cedula', $this->rrhh);
        $crudo = \Illuminate\Support\Facades\Storage::disk('local')->get($doc->getAttributes()['ruta']);
        $this->assertStringNotContainsString('contenido sensible', $crudo);
        $this->assertSame('%PDF-1.4 contenido sensible', app(Personal::class)->contenido($doc));
    }

    public function test_asistencia_calcula_horas_extra_con_tope_y_marcas_inmutables(): void
    {
        $turno = Turno::create(['nombre' => 'Diurno', 'hora_inicio' => '09:00', 'hora_fin' => '18:00', 'colacion_min' => 60, 'dias' => [1, 2, 3, 4, 5]]);
        $e = $this->empleado(['turno_id' => $turno->id]);
        $lunes = CarbonImmutable::parse('last monday')->subWeek()->toDateString();
        $martes = CarbonImmutable::parse($lunes)->addDay()->toDateString();
        $csv = "RUT;Fecha;Tipo\n12345678-5;{$lunes} 09:10;E\n12345678-5;{$lunes} 19:00;S\n12345678-5;{$martes} 08:55;E\n12345678-5;{$martes} 21:30;S\n99999999-9;{$lunes} 09:00;E\n";
        $svc = app(Asistencia::class);
        $r = $svc->importar($csv, $this->rrhh);
        $this->assertSame(4, $r['nuevas']);
        $this->assertSame(['99999999-9'], $r['desconocidas']);
        $this->assertSame(4, $svc->importar($csv, $this->rrhh)['repetidas']);

        $dias = $svc->diario($e, $lunes, $martes)->keyBy('fecha');
        $this->assertSame(10, $dias[$lunes]['atraso']);
        $this->assertSame(530 - 480, $dias[$lunes]['extra']);       // 9:10–19:00 menos colación = 530 min
        $this->assertSame(120, $dias[$martes]['extra']);            // 695 min trabajados: tope de 2 horas diarias
        $this->assertTrue($dias[$martes]['excedido']);

        $this->falla(fn () => $svc->marcar($e, 'entrada', now()->toDateTimeString(), 'manual', $this->rrhh), 'motivo');
        $m = $svc->marcar($e, 'salida', now()->toDateTimeString(), 'manual', $this->rrhh, 'Olvidó marcar');
        $this->esperarRechazoBd(fn () => DB::table('marcas')->where('id', $m->id)->update(['momento' => now()->subHour()]));
        $this->esperarRechazoBd(fn () => DB::table('marcas')->where('id', $m->id)->delete());
    }

    public function test_vacaciones_por_antiguedad_y_cuatro_ojos(): void
    {
        $e = $this->empleado();
        $svc = app(Ausencias::class);
        $saldo = $svc->saldoVacaciones($e);
        $this->assertSame(30, $saldo['disponibles']);              // 2 años × 15 días hábiles

        $e2 = $this->empleado(['rut' => '11.111.111-1', 'fecha_ingreso' => now()->subYears(6)->subDay()->toDateString(), 'anios_previos' => 10]);
        $this->assertSame(5, $svc->saldoVacaciones($e2)['progresivos']); // con 10 años previos: +1 día cada 3 años nuevos

        $lunes = CarbonImmutable::parse('next monday');
        $a = $svc->solicitar($e, ['tipo' => 'vacaciones', 'desde' => $lunes->toDateString(), 'hasta' => $lunes->addDays(6)->toDateString()], $this->rrhh);
        $this->assertSame(5, $a->dias_habiles);
        $this->falla(fn () => $svc->solicitar($e, ['tipo' => 'permiso_con_goce', 'desde' => $lunes->addDays(2)->toDateString(), 'hasta' => $lunes->addDays(3)->toDateString()], $this->rrhh), 'desde');
        $this->falla(fn () => $svc->solicitar($e, ['tipo' => 'vacaciones', 'desde' => $lunes->addWeeks(2)->toDateString(), 'hasta' => $lunes->addWeeks(8)->toDateString()], $this->rrhh), 'hasta');
        $this->falla(fn () => $svc->resolver($a, true, $this->rrhh), 'ausencia');
        $svc->resolver($a, true, $this->jefe);
        $this->assertSame(25, $svc->saldoVacaciones($e)['disponibles']);
        $this->esperarRechazoBd(fn () => DB::table('ausencias')->where('id', $a->id)->update(['resuelto_por' => $this->rrhh->id]));
    }

    public function test_nomina_completa_con_prestamo_comision_y_asientos(): void
    {
        foreach ([33] as $tipo) {
            FolioRango::create(['tipo_dte' => $tipo, 'desde' => 1, 'hasta' => 100, 'siguiente' => 1]);
        }
        ProveedorDteSimulado::caer(false);
        app(Parametros::class)->proponer('modulos.facturacion', true, 'empresa', 'Pruebas', $this->jefe);
        $vendedor = $this->usuario($this->empresa, 'Ejecutiva comercial', con2fa: false);
        $inicioMesAnterior = now()->subMonthNoOverflow()->startOfMonth();
        $e = $this->empleado(['user_id' => $vendedor->id, 'fecha_ingreso' => $inicioMesAnterior->copy()->subYear()->toDateString()], ['comision_pct' => 2]);

        // Comisión: el vendedor emite una factura.
        $cemento = Producto::create(['sku' => 'CEM', 'nombre' => 'Cemento', 'precio_neto' => '5000', 'costo' => '3000']);
        $r = app(Recepciones::class)->guardar(['local_id' => $this->local->id, 'proveedor_nombre' => 'P', 'lineas' => [['producto_id' => $cemento->id, 'cantidad' => '100', 'costo_unitario' => '3000']]], $this->jefe);
        app(Recepciones::class)->confirmar($r, $this->jefe);
        $cliente = Cliente::firstOrCreate(['rut' => '76543210-3'], ['razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción', 'direccion' => 'Av. 1', 'comuna' => 'Providencia', 'lista_precio' => 'meson']);
        $docs = app(Documentos::class);
        $doc = $docs->emitir($docs->guardar(['tipo_dte' => 33, 'cliente_id' => $cliente->id, 'local_id' => $this->local->id, 'lineas' => [['producto_id' => $cemento->id, 'cantidad' => '20']]], $vendedor), $vendedor);
        $com = Comision::where('documento_id', $doc->id)->firstOrFail();
        $this->assertSame(2000, $com->monto);                       // 2% de 100.000 neto
        $this->assertSame('pendiente', $com->estado);
        $periodo = $com->periodo;
        $com->forceFill(['estado' => 'aprobada', 'aprobado_por' => $this->jefe->id])->save();

        // Regla por categoría: pisa la tasa del contrato.
        $cat = \App\Modules\Comercial\Models\Categoria::create(['nombre' => 'Cementos']);
        $cemento->update(['categoria_id' => $cat->id]);
        \App\Modules\RRHH\Models\ReglaComision::create(['categoria_id' => $cat->id, 'tasa' => 5]);
        $doc2 = $docs->emitir($docs->guardar(['tipo_dte' => 33, 'cliente_id' => $cliente->id, 'local_id' => $this->local->id, 'lineas' => [['producto_id' => $cemento->id, 'cantidad' => '10']]], $vendedor), $vendedor);
        $this->assertSame(2500, Comision::where('documento_id', $doc2->id)->value('monto')); // 5% de 50.000, queda pendiente: no entra a la nómina

        // Anticipo de quincena con tope del 50%.
        $ant = app(\App\Modules\RRHH\Anticipos::class);
        $this->falla(fn () => $ant->registrar($e, ['fecha' => $periodo.'-15', 'monto' => 500000], $this->rrhh), 'monto');
        $ant->registrar($e, ['fecha' => $periodo.'-15', 'monto' => 150000], $this->rrhh);

        // Préstamo con tope del 15% y cuatro ojos.
        $pr = app(Prestamos::class);
        $this->falla(fn () => $pr->solicitar($e, ['monto' => 600000, 'cuotas' => 2, 'primer_periodo' => $periodo, 'motivo' => 'Urgencia'], $this->rrhh), 'cuotas');
        $p = $pr->solicitar($e, ['monto' => 300000, 'cuotas' => 3, 'primer_periodo' => $periodo, 'motivo' => 'Urgencia'], $this->rrhh);
        $this->falla(fn () => $pr->resolver($p, true, $this->rrhh), 'prestamo');
        $pr->resolver($p, true, $this->jefe);

        $svc = app(Nominas::class);
        $n = $svc->generar($periodo, $this->rrhh);
        $l = $n->liquidaciones()->firstOrFail();
        $haberes = collect($l->haberes)->pluck('monto', 'concepto');
        $this->assertSame(2000, $haberes['Comisiones por ventas']);
        $this->assertSame(100000, collect($l->descuentos)->firstWhere('concepto', 'Cuota de préstamo')['monto']);
        $this->assertSame(150000, collect($l->descuentos)->firstWhere('concepto', 'Anticipo de sueldo')['monto']);

        $l = $svc->ajustarBonos($l, [['concepto' => 'Bono de metas', 'monto' => 50000]], $this->rrhh);
        $this->assertSame(50000, collect($l->haberes)->firstWhere('concepto', 'Bono de metas')['monto']);
        $n = $svc->generar($periodo, $this->rrhh);                  // al regenerar se conserva el bono
        $this->assertNotNull(collect($n->liquidaciones()->first()->haberes)->firstWhere('concepto', 'Bono de metas'));

        $this->falla(fn () => $svc->aprobar($n, $this->rrhh), 'nomina');
        $svc->aprobar($n, $this->jefe);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertSame(200000, $p->fresh()->saldo);
        $this->assertSame('descontado', \App\Modules\RRHH\Models\Anticipo::value('estado'));
        $this->assertSame(0, app(Contabilidad::class)->saldos(null, now()->addDay()->toDateString())->firstWhere('codigo', '1.1.07')->saldo);
        $asiento = Asiento::where('origen_tipo', 'liquidacion')->with('lineas')->firstOrFail();
        $this->assertSame((int) $asiento->lineas->sum('debe'), (int) $asiento->lineas->sum('haber'));
        $this->esperarRechazoBd(fn () => DB::table('liquidaciones')->where('nomina_id', $n->id)->update(['liquido' => 1]));
        $this->falla(fn () => $svc->generar($periodo, $this->rrhh), 'periodo');

        $svc->pagar($n->fresh(), now()->toDateString(), $this->jefe);
        $this->assertSame(0, app(Contabilidad::class)->saldos(null, now()->addDay()->toDateString())->firstWhere('codigo', '2.1.05')->saldo);
        $this->assertStringStartsWith('%PDF', $svc->pdf(Liquidacion::firstOrFail()));

        // Pantallas con datos reales, vistas por el analista de RRHH.
        $liq = Liquidacion::firstOrFail()->id;
        foreach (["/rrhh/nominas", "/rrhh/nominas/{$n->id}", "/rrhh/ausencias?estado=todas", "/rrhh/prestamos", "/rrhh/anticipos?periodo={$periodo}",
            "/rrhh/comisiones?periodo={$periodo}", "/rrhh/indicadores", "/rrhh/cumplimiento", "/rrhh/finiquitos", "/rrhh/contratos", "/rrhh/personal", "/rrhh/personal/{$e->id}", "/rrhh/contratos/crear?empleado={$e->id}", "/rrhh/asistencia?empleado={$e->id}", "/rrhh/ausencias/saldo/{$e->id}"] as $ruta) {
            $this->entrar($this->rrhh)->get($ruta)->assertOk();
        }
        $this->entrar($this->rrhh)->get("/rrhh/liquidaciones/{$liq}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('Líquido', $this->entrar($this->rrhh)->get("/rrhh/nominas/{$n->id}?exportar=1")->streamedContent());
    }

    public function test_finiquito_por_necesidades_de_la_empresa(): void
    {
        $e = $this->empleado(['fecha_ingreso' => now()->subYears(3)->subMonths(7)->toDateString()]);
        $svc = app(Finiquitos::class);
        $f = $svc->calcular($e, ['causal' => '161-1', 'fecha_termino' => now()->toDateString(), 'aviso_previo' => false], $this->rrhh);
        $base = 800000 + 50000 + 40000;
        $c = collect($f->conceptos)->pluck('monto', 'concepto');
        $this->assertSame($base * 4, $c['Indemnización por años de servicio (4 × base)']); // 3 años y 7 meses: la fracción sobre 6 meses cuenta
        $this->assertSame($base, $c['Indemnización sustitutiva del aviso previo']);
        $this->assertNotNull(collect($f->conceptos)->firstWhere('tipo', 'feriado'));

        $renuncia = $this->empleado(['rut' => '11.111.111-1']);
        $f2 = $svc->calcular($renuncia, ['causal' => '159-2', 'fecha_termino' => now()->toDateString()], $this->rrhh);
        $this->assertNull(collect($f2->conceptos)->firstWhere('tipo', 'indemnizacion'));

        $this->falla(fn () => $svc->aprobar($f, $this->rrhh), 'finiquito');
        $svc->aprobar($f, $this->jefe);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->assertSame('desvinculado', $e->fresh()->estado);
        $this->assertSame(0, Contrato::where('empleado_id', $e->id)->where('estado', 'vigente')->count());
        $svc->firmar($f->fresh(), now()->toDateString(), $this->jefe);
        $this->assertSame('firmado', $f->fresh()->estado);
    }
}
