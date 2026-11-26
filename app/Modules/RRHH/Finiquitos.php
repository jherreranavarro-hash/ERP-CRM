<?php

namespace App\Modules\RRHH;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\Finanzas\Contabilidad;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Finiquito;
use App\Modules\RRHH\Models\Liquidacion;
use App\Modules\RRHH\Models\Prestamo;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Desvinculaciones y finiquitos: calcula el feriado proporcional, la
 * indemnización por años de servicio (30 días por año, tope de 11 años y de
 * 90 UF de base) y la sustitutiva del aviso previo según la causal; se
 * aprueba con cuatro ojos, se contabiliza y termina contrato y ficha.
 */
class Finiquitos
{
    public function __construct(private Parametros $parametros, private Ausencias $ausencias, private Contabilidad $contabilidad, private Auditoria $auditoria) {}

    public function calcular(Empleado $e, array $d, User $user): Finiquito
    {
        $e->loadMissing('contratoVigente');
        $c = $e->contratoVigente ?? throw ValidationException::withMessages(['empleado' => 'La persona no tiene un contrato vigente.']);
        if (! array_key_exists($d['causal'], Finiquito::CAUSALES)) {
            throw ValidationException::withMessages(['causal' => 'Causal no válida.']);
        }
        if (Finiquito::where('empleado_id', $e->id)->whereIn('estado', ['borrador', 'aprobado'])->exists()) {
            throw ValidationException::withMessages(['empleado' => 'Ya hay un finiquito en curso para esta persona.']);
        }
        $termino = CarbonImmutable::parse($d['fecha_termino']);
        if ($termino->lt(CarbonImmutable::parse($c->fecha_inicio))) {
            throw ValidationException::withMessages(['fecha_termino' => 'El término es anterior al inicio del contrato.']);
        }
        $avisoPrevio = (bool) ($d['aviso_previo'] ?? false);

        $uf = (string) $this->parametros->valor('nomina.uf');
        $tope = Calculadora::red(BigDecimal::of((string) $this->parametros->valor('nomina.tope_indemnizacion_uf'))->multipliedBy($uf));
        // Última remuneración mensual (art. 172): lo imponible y no imponible habitual, sin horas extra.
        $ultima = Liquidacion::where('empleado_id', $e->id)->whereHas('nomina', fn ($q) => $q->whereIn('estado', ['aprobada', 'pagada']))->latest('id')->first();
        $mensual = $ultima && $ultima->dias_trabajados === 30
            ? $ultima->imponible + $ultima->no_imponible - (int) collect($ultima->haberes)->filter(fn ($h) => str_starts_with($h['concepto'], 'Horas extra'))->sum('monto')
            : $c->sueldo_base + $c->colacion + $c->movilizacion;
        $base = min($mensual, $tope);

        $ingreso = CarbonImmutable::parse($e->fecha_ingreso);
        $meses = (int) $ingreso->diffInMonths($termino->addDay());
        $anios = round($meses / 12, 2);
        $conceptos = [];

        // Feriado proporcional y pendiente: días hábiles por usar, llevados a días corridos desde el día siguiente al término.
        $vac = $this->ausencias->saldoVacaciones($e, $termino);
        $pendientes = max(0, $vac['devengados'] + $vac['progresivos'] - $vac['tomados']);
        if ($pendientes > 0) {
            $corridos = $this->corridos($termino, $pendientes);
            $diario = BigDecimal::of($c->sueldo_base)->dividedBy(30, 6, RoundingMode::HALF_UP);
            $conceptos[] = ['concepto' => 'Feriado pendiente y proporcional ('.rtrim(rtrim(number_format($pendientes, 2, ',', ''), '0'), ',').' días hábiles = '.rtrim(rtrim(number_format($corridos, 2, ',', ''), '0'), ',').' corridos)',
                'monto' => Calculadora::red($diario->multipliedBy((string) $corridos)), 'tipo' => 'feriado'];
        }

        if (in_array($d['causal'], Finiquito::CON_INDEMNIZACION, true)) {
            // Años de servicio: fracción superior a 6 meses cuenta como año; tope 11 años; solo con más de un año.
            $completos = intdiv($meses, 12) + ($meses % 12 > 6 ? 1 : 0);
            if ($meses >= 12 && $completos > 0) {
                $n = min(11, $completos);
                $conceptos[] = ['concepto' => "Indemnización por años de servicio ({$n} × base)", 'monto' => $base * $n, 'tipo' => 'indemnizacion'];
            }
            if (! $avisoPrevio) {
                $conceptos[] = ['concepto' => 'Indemnización sustitutiva del aviso previo', 'monto' => $base, 'tipo' => 'indemnizacion'];
            }
        }
        $saldoPrestamos = (int) Prestamo::where('empleado_id', $e->id)->where('estado', 'vigente')->sum('saldo');
        if ($saldoPrestamos > 0) {
            $haberes = array_sum(array_column($conceptos, 'monto'));
            // Se descuenta solo con acuerdo expreso del trabajador y sin dejar el finiquito en negativo.
            $conceptos[] = ['concepto' => 'Descuento saldo de préstamo', 'monto' => -min($saldoPrestamos, $haberes), 'tipo' => 'prestamo'];
        }
        $conceptos = array_values(array_filter($conceptos, fn ($x) => $x['monto'] !== 0));

        $f = Finiquito::create(['empleado_id' => $e->id, 'contrato_id' => $c->id, 'causal' => $d['causal'], 'fecha_termino' => $termino->toDateString(),
            'aviso_previo' => $avisoPrevio, 'base_calculo' => $base, 'anios_servicio' => $anios, 'conceptos' => $conceptos,
            'total' => array_sum(array_column($conceptos, 'monto')), 'estado' => 'borrador', 'creado_por' => $user->id]);
        $this->auditoria->registrar('finiquito.calcular', $f, null, ['empleado' => $e->nombreCompleto(), 'causal' => $d['causal'], 'total' => $f->total]);

        return $f;
    }

    public function aprobar(Finiquito $finiquito, User $user): Finiquito
    {
        return DB::transaction(function () use ($finiquito, $user) {
            $f = Finiquito::with('empleado', 'contrato')->whereKey($finiquito->id)->lockForUpdate()->firstOrFail();
            if ($f->estado !== 'borrador') {
                throw ValidationException::withMessages(['finiquito' => 'El finiquito ya está resuelto.']);
            }
            if ($f->creado_por === $user->id) {
                throw ValidationException::withMessages(['finiquito' => 'Quien lo calculó no lo aprueba: debe revisarlo otra persona.']);
            }
            $c = collect($f->conceptos);
            $indem = (int) $c->where('tipo', 'indemnizacion')->sum('monto');
            $feriado = (int) $c->where('tipo', 'feriado')->sum('monto');
            $prestamo = -(int) $c->where('tipo', 'prestamo')->sum('monto');
            $this->contabilidad->automatico('finiquito', $f->id, $f->fecha_termino->toDateString(), "Finiquito · {$f->empleado->nombreCompleto()}", [
                ['indemnizaciones', $indem, 0], ['remuneraciones', $feriado, 0],
                ['prestamos_personal', 0, $prestamo], ['finiquitos_por_pagar', 0, $f->total],
            ], $user, $f->empleado->centro_costo_id);
            if ($prestamo > 0) {
                $resta = $prestamo;
                foreach (Prestamo::where('empleado_id', $f->empleado_id)->where('estado', 'vigente')->orderBy('id')->get() as $p) {
                    $baja = min($resta, $p->saldo);
                    $resta -= $baja;
                    $p->forceFill(['saldo' => $p->saldo - $baja, 'estado' => $p->saldo === $baja ? 'pagado' : 'vigente'])->save();
                }
            }
            $f->contrato->forceFill(['estado' => 'terminado', 'fecha_termino' => $f->fecha_termino])->save();
            $f->empleado->forceFill(['estado' => 'desvinculado', 'fecha_egreso' => $f->fecha_termino])->save();
            $f->forceFill(['estado' => 'aprobado', 'aprobado_por' => $user->id, 'aprobado_at' => now()])->save();
            $this->auditoria->registrar('finiquito.aprobar', $f, null, ['empleado' => $f->empleado->nombreCompleto(), 'total' => $f->total]);

            return $f;
        });
    }

    /** Ratificado ante ministro de fe y pagado. */
    public function firmar(Finiquito $finiquito, string $fechaPago, User $user): Finiquito
    {
        return DB::transaction(function () use ($finiquito, $fechaPago, $user) {
            $f = Finiquito::with('empleado')->whereKey($finiquito->id)->lockForUpdate()->firstOrFail();
            if ($f->estado !== 'aprobado') {
                throw ValidationException::withMessages(['finiquito' => 'Solo se firma un finiquito aprobado.']);
            }
            $f->forceFill(['estado' => 'firmado'])->save();
            if ($f->total > 0) {
                $this->contabilidad->automatico('finiquito_pago', $f->id, $fechaPago, "Pago finiquito · {$f->empleado->nombreCompleto()}", [
                    ['finiquitos_por_pagar', $f->total, 0], ['banco', 0, $f->total],
                ], $user);
            }
            $this->auditoria->registrar('finiquito.firmar', $f);

            return $f;
        });
    }

    public function anular(Finiquito $finiquito, User $user): Finiquito
    {
        $f = Finiquito::findOrFail($finiquito->id);
        if ($f->estado !== 'borrador') {
            throw ValidationException::withMessages(['finiquito' => 'Solo se anula un finiquito en borrador.']);
        }
        $f->forceFill(['estado' => 'anulado'])->save();
        $this->auditoria->registrar('finiquito.anular', $f);

        return $f;
    }

    /** Días hábiles pendientes llevados a corridos: se cuentan desde el día siguiente al término, sumando fines de semana. */
    private function corridos(CarbonImmutable $termino, float $habiles): float
    {
        $enteros = (int) floor($habiles);
        $d = $termino;
        $corridos = 0;
        while ($enteros > 0) {
            $d = $d->addDay();
            $corridos++;
            if ($d->isWeekday()) {
                $enteros--;
            }
        }

        return $corridos + ($habiles - floor($habiles));
    }
}
