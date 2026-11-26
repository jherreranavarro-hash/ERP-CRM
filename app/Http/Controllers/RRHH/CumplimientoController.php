<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\RRHH\Asistencia;
use App\Modules\RRHH\Ausencias;
use App\Modules\RRHH\Contratos;
use App\Modules\RRHH\Models\Contrato;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\EmpleadoDocumento;
use App\Modules\RRHH\Models\Nomina;
use Inertia\Inertia;

/**
 * RRHH → Cumplimiento Normativo: revisa en línea las obligaciones laborales
 * que el sistema puede verificar y lista cada incumplimiento con su persona.
 */
class CumplimientoController extends Controller
{
    public function index(Parametros $p, Contratos $contratos, Ausencias $ausencias, Asistencia $asistencia)
    {
        $activos = Empleado::with('contratoVigente', 'turno')->where('estado', 'activo')->orderBy('apellidos')->get();
        $imm = (int) $p->valor('nomina.imm');
        $max = (int) $p->valor('nomina.jornada_maxima');
        $item = fn ($e, string $detalle) => ['empleado_id' => $e->id, 'empleado' => $e->nombreCompleto(), 'detalle' => $detalle];
        $controles = [];

        $controles[] = ['clave' => 'contrato', 'titulo' => 'Contrato escriturado y firmado', 'norma' => 'Art. 9 del Código del Trabajo: el contrato se firma dentro de 15 días desde el ingreso.',
            'items' => $activos->filter(fn ($e) => ! $e->contratoVigente && $e->fecha_ingreso->diffInDays(now()) > 15)->map(fn ($e) => $item($e, 'Sin contrato vigente firmado desde el '.$e->fecha_ingreso->format('d-m-Y')))->values()];
        $controles[] = ['clave' => 'vencimiento', 'titulo' => 'Contratos a plazo por vencer', 'norma' => 'Art. 159 N° 4: al segundo vencimiento o tras un año continuo, el contrato pasa a indefinido.',
            'items' => $contratos->porVencer()->map(fn ($c) => ['empleado_id' => $c->empleado_id, 'empleado' => $c->empleado->nombreCompleto(), 'detalle' => 'Vence el '.$c->fecha_termino->format('d-m-Y')])->values()];
        $controles[] = ['clave' => 'minimo', 'titulo' => 'Sueldo sobre el ingreso mínimo', 'norma' => "Ingreso mínimo vigente: $".number_format($imm, 0, ',', '.').", proporcional en jornada parcial.",
            'items' => $activos->filter(fn ($e) => $e->contratoVigente && $e->contratoVigente->sueldo_base < ceil($imm * $e->contratoVigente->jornada_horas / $max))->map(fn ($e) => $item($e, 'Sueldo base $'.number_format($e->contratoVigente->sueldo_base, 0, ',', '.')))->values()];
        $controles[] = ['clave' => 'jornada', 'titulo' => 'Jornada ordinaria dentro del máximo legal', 'norma' => "Ley 21.561: máximo {$max} horas semanales.",
            'items' => $activos->filter(fn ($e) => ($e->contratoVigente?->jornada_horas ?? 0) > $max || ($e->turno && $e->turno->minutos() * count($e->turno->dias) > $max * 60))
                ->map(fn ($e) => $item($e, 'Contrato o turno sobre '.$max.' horas'))->values()];
        $controles[] = ['clave' => 'vacaciones', 'titulo' => 'Feriado acumulado', 'norma' => 'Art. 70: el feriado se puede acumular solo hasta por dos períodos consecutivos.',
            'items' => $activos->map(fn ($e) => [$e, $ausencias->saldoVacaciones($e)])->filter(fn ($x) => $x[1]['disponibles'] > 30)->map(fn ($x) => $item($x[0], $x[1]['disponibles'].' días hábiles acumulados'))->values()];
        $desde = now()->subDays(30)->toDateString();
        $controles[] = ['clave' => 'horas_extra', 'titulo' => 'Horas extra sobre el tope diario', 'norma' => 'Art. 31: máximo '.$p->valor('nomina.max_horas_extra_dia').' horas extraordinarias por día (últimos 30 días).',
            'items' => $activos->filter(fn ($e) => $e->turno)->map(fn ($e) => [$e, $asistencia->diario($e, $desde, now()->toDateString())->where('excedido', true)->count()])
                ->filter(fn ($x) => $x[1] > 0)->map(fn ($x) => $item($x[0], "{$x[1]} días sobre el tope"))->values()];
        $controles[] = ['clave' => 'documentos', 'titulo' => 'Expediente con cédula de identidad', 'norma' => 'Ficha de personal completa con copia de la cédula.',
            'items' => $activos->filter(fn ($e) => ! EmpleadoDocumento::where('empleado_id', $e->id)->where('tipo', 'cedula')->exists())->map(fn ($e) => $item($e, 'Falta la cédula'))->values()];
        $ultimo = now()->subMonthNoOverflow()->format('Y-m');
        $nomina = Nomina::where('periodo', $ultimo)->first();
        $controles[] = ['clave' => 'remuneraciones', 'titulo' => 'Remuneraciones del mes anterior pagadas', 'norma' => 'Art. 55: las remuneraciones se pagan con la periodicidad convenida, no mayor a un mes.',
            'items' => $nomina?->estado === 'pagada' || $activos->isEmpty() ? [] : [['empleado_id' => null, 'empleado' => 'Nómina '.$ultimo, 'detalle' => $nomina ? 'Está '.Nomina::ESTADOS[$nomina->estado] : 'No se ha generado']]];

        return Inertia::render('RRHH/Cumplimiento', ['controles' => $controles, 'activos' => $activos->count()]);
    }
}
