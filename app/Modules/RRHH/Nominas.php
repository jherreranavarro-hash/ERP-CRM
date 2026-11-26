<?php

namespace App\Modules\RRHH;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Finanzas\Contabilidad;
use App\Modules\RRHH\Models\Comision;
use App\Modules\RRHH\Models\Contrato;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Liquidacion;
use App\Modules\RRHH\Models\Nomina;
use App\Modules\RRHH\Models\Prestamo;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Proceso mensual de remuneraciones: genera las liquidaciones en borrador con
 * los datos reales del mes (días, ausencias no pagadas, horas extra de la
 * asistencia, comisiones, cuotas de préstamos), se aprueba con cuatro ojos
 * (contabiliza y descuenta los préstamos) y se registra su pago.
 */
class Nominas
{
    public function __construct(
        private MotorNomina $motor,
        private Asistencia $asistencia,
        private Ausencias $ausencias,
        private Contabilidad $contabilidad,
        private Auditoria $auditoria,
        private Tenancy $tenancy,
    ) {}

    public function generar(string $periodo, User $user): Nomina
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo)) {
            throw ValidationException::withMessages(['periodo' => 'Período no válido (AAAA-MM).']);
        }
        $inicio = CarbonImmutable::parse($periodo.'-01');
        if ($inicio->gt(now()->startOfMonth())) {
            throw ValidationException::withMessages(['periodo' => 'No se genera la nómina de un mes futuro.']);
        }

        return DB::transaction(function () use ($periodo, $inicio, $user) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['nomina:'.$this->tenancy->id().':'.$periodo]);
            $n = Nomina::where('periodo', $periodo)->lockForUpdate()->first();
            if ($n && $n->estado !== 'borrador') {
                throw ValidationException::withMessages(['periodo' => 'La nómina de ese mes ya está '.Nomina::ESTADOS[$n->estado].'.']);
            }
            // Al regenerar se conservan los bonos ingresados a mano.
            $bonos = $n ? $n->liquidaciones()->get()->mapWithKeys(fn ($l) => [$l->empleado_id => array_values(array_filter($l->haberes, fn ($h) => $h['bono'] ?? false))]) : collect();
            $ind = $this->motor->indicadores();
            if ($n) {
                $n->liquidaciones()->delete();
                $n->forceFill(['indicadores' => $ind, 'generado_por' => $user->id])->save();
            } else {
                $n = Nomina::create(['periodo' => $periodo, 'estado' => 'borrador', 'indicadores' => $ind, 'generado_por' => $user->id]);
            }
            foreach ($this->contratosDelMes($inicio) as $c) {
                $this->liquidar($n, $c, $inicio, $ind, $bonos->get($c->empleado_id, []));
            }
            $this->totalizar($n);
            $this->auditoria->registrar('nomina.generar', $n, null, ['periodo' => $periodo, 'liquidaciones' => $n->liquidaciones()->count()]);

            return $n;
        });
    }

    /** Recalcula una liquidación con bonos imponibles ingresados por RRHH. @param list<array{concepto:string, monto:int}> $bonos */
    public function ajustarBonos(Liquidacion $l, array $bonos, User $user): Liquidacion
    {
        return DB::transaction(function () use ($l, $bonos, $user) {
            $n = Nomina::whereKey($l->nomina_id)->lockForUpdate()->firstOrFail();
            if ($n->estado !== 'borrador') {
                throw ValidationException::withMessages(['nomina' => 'La nómina ya no está en borrador.']);
            }
            $c = Contrato::with('empleado')->findOrFail($l->contrato_id);
            $bonos = array_values(array_filter(array_map(fn ($b) => ['concepto' => mb_substr(trim($b['concepto'] ?? ''), 0, 60), 'monto' => (int) ($b['monto'] ?? 0)], $bonos), fn ($b) => $b['concepto'] !== '' && $b['monto'] > 0));
            $l->delete();
            $nueva = $this->liquidar($n, $c, CarbonImmutable::parse($n->periodo.'-01'), $n->indicadores, $bonos);
            $this->totalizar($n);
            $this->auditoria->registrar('nomina.bonos', $nueva, null, ['empleado' => $c->empleado->nombreCompleto(), 'bonos' => $bonos]);

            return $nueva;
        });
    }

    public function aprobar(Nomina $nomina, User $user): Nomina
    {
        return DB::transaction(function () use ($nomina, $user) {
            $n = Nomina::whereKey($nomina->id)->lockForUpdate()->firstOrFail();
            if ($n->estado !== 'borrador') {
                throw ValidationException::withMessages(['nomina' => 'La nómina ya está aprobada.']);
            }
            if ($n->generado_por === $user->id) {
                throw ValidationException::withMessages(['nomina' => 'Quien generó la nómina no la aprueba: debe revisarla otra persona.']);
            }
            $liqs = $n->liquidaciones()->with('empleado')->get();
            if ($liqs->isEmpty()) {
                throw ValidationException::withMessages(['nomina' => 'La nómina no tiene liquidaciones.']);
            }
            $fecha = CarbonImmutable::parse($n->periodo.'-01')->endOfMonth()->min(CarbonImmutable::today())->toDateString();
            foreach ($liqs as $l) {
                $this->contabilizar($l, $n, $fecha, $user);
                $this->descontarPrestamos($l->empleado_id, (int) collect($l->descuentos)->where('concepto', 'Cuota de préstamo')->sum('monto'), $n->periodo);
            }
            $n->forceFill(['estado' => 'aprobada', 'aprobado_por' => $user->id, 'aprobado_at' => now()])->save();
            $this->auditoria->registrar('nomina.aprobar', $n, null, ['periodo' => $n->periodo, 'liquido' => $n->total_liquido]);

            return $n;
        });
    }

    public function pagar(Nomina $nomina, string $fecha, User $user): Nomina
    {
        return DB::transaction(function () use ($nomina, $fecha, $user) {
            $n = Nomina::whereKey($nomina->id)->lockForUpdate()->firstOrFail();
            if ($n->estado !== 'aprobada') {
                throw ValidationException::withMessages(['nomina' => 'Solo se paga una nómina aprobada.']);
            }
            $n->forceFill(['estado' => 'pagada', 'fecha_pago' => $fecha])->save();
            $this->contabilidad->automatico('nomina_pago', $n->id, $fecha, "Pago de remuneraciones {$n->periodo}", [
                ['remuneraciones_por_pagar', $n->total_liquido, 0], ['banco', 0, $n->total_liquido],
            ], $user);
            $this->auditoria->registrar('nomina.pagar', $n, null, ['periodo' => $n->periodo, 'fecha' => $fecha]);

            return $n;
        });
    }

    /** Liquidación en PDF (con los indicadores con que se calculó). */
    public function pdf(Liquidacion $l): string
    {
        $l->loadMissing('empleado.local', 'nomina', 'contrato');
        $html = view('pdf.liquidacion', ['l' => $l, 'empresa' => $this->tenancy->empresa(), 'palabras' => Palabras::pesos($l->liquido)])->render();
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** Contrato que rige a cada persona en el mes (el último que se cruza con el período). */
    private function contratosDelMes(CarbonImmutable $inicio)
    {
        $fin = $inicio->endOfMonth();

        return Contrato::with('empleado')->whereIn('estado', ['vigente', 'terminado'])
            ->where('fecha_inicio', '<=', $fin->toDateString())
            ->where(fn ($q) => $q->whereNull('fecha_termino')->orWhere('fecha_termino', '>=', $inicio->toDateString()))
            ->whereNotNull('firmado_at')
            ->orderBy('empleado_id')->orderByDesc('fecha_inicio')->get()->unique('empleado_id');
    }

    private function liquidar(Nomina $n, Contrato $c, CarbonImmutable $inicio, array $ind, array $bonos): Liquidacion
    {
        $e = $c->empleado;
        $fin = $inicio->endOfMonth();
        // Base de 30 días: se descuentan los días antes del ingreso, después del término y los no pagados.
        $desde = CarbonImmutable::parse($c->fecha_inicio)->max($inicio);
        $hasta = $c->fecha_termino ? CarbonImmutable::parse($c->fecha_termino)->min($fin) : $fin;
        $fuera = ($desde->day - 1) + ($hasta->isSameDay($fin) ? 0 : $fin->day - $hasta->day);
        $dias = $hasta->lt($desde) ? 0 : max(0, 30 - $fuera);
        $dias = max(0, $dias - $this->ausencias->diasNoPagados($e, $desde, $hasta));
        $periodo = $inicio->format('Y-m');
        $cuota = Prestamo::where('empleado_id', $e->id)->where('estado', 'vigente')->get()->sum(fn ($p) => Prestamos::cuota($p, $periodo));

        $r = $this->motor->calcular($e, $c, [
            'dias_trabajados' => $dias,
            'horas_extra' => $e->turno_id ? $this->asistencia->horasExtra($e, $periodo) : '0',
            'comisiones' => (int) Comision::where('empleado_id', $e->id)->where('periodo', $periodo)->sum('monto'),
            'bonos' => $bonos,
            'cuota_prestamo' => $cuota,
        ], $ind);
        // Marca los bonos para conservarlos si se regenera la nómina.
        $conceptos = array_column($bonos, 'concepto');
        $r['haberes'] = array_map(fn ($h) => $h + (in_array($h['concepto'], $conceptos, true) && $h['imponible'] ? ['bono' => true] : []), $r['haberes']);

        return Liquidacion::create(['nomina_id' => $n->id, 'empleado_id' => $e->id, 'contrato_id' => $c->id] + $r);
    }

    private function totalizar(Nomina $n): void
    {
        $t = Liquidacion::where('nomina_id', $n->id)->selectRaw('coalesce(sum(imponible + no_imponible), 0) as h, coalesce(sum(total_descuentos), 0) as d, coalesce(sum(liquido), 0) as l, coalesce(sum(costo_empleador - imponible - no_imponible), 0) as a')->first();
        $n->forceFill(['total_haberes' => (int) $t->h, 'total_descuentos' => (int) $t->d, 'total_liquido' => (int) $t->l, 'total_empleador' => (int) $t->a])->save();
    }

    private function contabilizar(Liquidacion $l, Nomina $n, string $fecha, User $user): void
    {
        $legales = (int) collect($l->descuentos)->where('legal', true)->sum('monto') - $l->impuesto;
        $aportes = (int) collect($l->aportes)->sum('monto');
        $cuota = (int) collect($l->descuentos)->where('concepto', 'Cuota de préstamo')->sum('monto');
        $this->contabilidad->automatico('liquidacion', $l->id, $fecha, "Remuneraciones {$n->periodo} · {$l->empleado->nombreCompleto()}", [
            ['remuneraciones', $l->imponible + $l->no_imponible, 0],
            ['leyes_sociales', $aportes, 0],
            ['remuneraciones_por_pagar', 0, $l->liquido],
            ['leyes_sociales_por_pagar', 0, $legales + $aportes],
            ['impuesto_unico_por_pagar', 0, $l->impuesto],
            ['prestamos_personal', 0, $cuota],
        ], $user, $l->empleado->centro_costo_id);
    }

    /** Rebaja la cuota descontada de los préstamos vigentes, del más antiguo al más nuevo. */
    private function descontarPrestamos(int $empleadoId, int $monto, string $periodo): void
    {
        foreach (Prestamo::where('empleado_id', $empleadoId)->where('estado', 'vigente')->where('primer_periodo', '<=', $periodo)->orderBy('id')->lockForUpdate()->get() as $p) {
            if ($monto <= 0) {
                break;
            }
            $baja = min($monto, $p->saldo);
            $monto -= $baja;
            $p->forceFill(['saldo' => $p->saldo - $baja, 'estado' => $p->saldo - $baja === 0 ? 'pagado' : 'vigente'])->save();
        }
    }
}
