<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\RRHH\Asistencia;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Marca;
use App\Support\Exportacion\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** RRHH → Control de Asistencia: marcas del reloj control, correcciones y horas extra. */
class AsistenciaController extends Controller
{
    public function __construct(private Asistencia $asistencia) {}

    public function index(Request $request)
    {
        $empleados = Empleado::where('estado', 'activo')->orderBy('apellidos')->get(['id', 'nombres', 'apellidos', 'turno_id']);
        $e = $empleados->firstWhere('id', $request->integer('empleado')) ?? $empleados->first();
        $desde = $request->input('desde', now()->startOfMonth()->toDateString());
        $hasta = $request->input('hasta', now()->toDateString());
        if (CarbonImmutable::parse($desde)->diffInDays(CarbonImmutable::parse($hasta)) > 62) {
            $hasta = CarbonImmutable::parse($desde)->addDays(62)->toDateString();
        }
        $dias = $e ? $this->asistencia->diario(Empleado::find($e->id), $desde, $hasta) : collect();

        if ($request->boolean('exportar') && $e) {
            abort_unless($request->user()->tienePermiso('rrhh.control-de-asistencia.exportar'), 403);

            return Csv::descargar('asistencia-'.$e->id, ['Fecha', 'Entrada', 'Salida', 'Minutos trabajados', 'Minutos turno', 'Atraso', 'Extra', 'Estado'],
                $dias->map(fn ($d) => [$d['fecha'], $d['entrada'], $d['salida'], $d['trabajados'], $d['turno'], $d['atraso'], $d['extra'], $d['estado']]));
        }

        return Inertia::render('RRHH/Asistencia', [
            'empleados' => $empleados->map(fn ($x) => ['id' => $x->id, 'nombre' => $x->nombreCompleto(), 'turno' => (bool) $x->turno_id]),
            'empleado' => $e?->id,
            'filtros' => ['desde' => $desde, 'hasta' => $hasta],
            'dias' => $dias->values(),
            'totales' => ['trabajados' => $dias->sum('trabajados'), 'extra' => $dias->sum('extra'), 'atraso' => $dias->sum('atraso'), 'ausencias' => $dias->where('estado', 'ausente')->count()],
            'marcas' => $e ? Marca::with('registrador:id,name')->where('empleado_id', $e->id)->latest('momento')->limit(15)->get()->map(fn ($m) => [
                'id' => $m->id, 'tipo' => $m->tipo, 'momento' => $m->momento->toIso8601String(), 'origen' => Marca::ORIGENES[$m->origen], 'motivo' => $m->motivo, 'registrador' => $m->registrador?->name]) : [],
        ]);
    }

    public function marcar(Request $request)
    {
        $d = $request->validate([
            'empleado_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(['entrada', 'salida'])],
            'momento' => ['required', 'date', 'before_or_equal:now'],
            'motivo' => ['required', 'string', 'max:200'],
        ]);
        $this->asistencia->marcar(Empleado::findOrFail($d['empleado_id']), $d['tipo'], $d['momento'], 'manual', $request->user(), $d['motivo']);

        return back()->with('exito', 'Marca manual registrada (queda en la auditoría).');
    }

    public function importar(Request $request)
    {
        $request->validate(['archivo' => ['required', 'file', 'max:5120', 'mimes:csv,txt']], ['archivo.mimes' => 'Sube el archivo del reloj control en CSV.']);
        $r = $this->asistencia->importar((string) file_get_contents($request->file('archivo')->getRealPath()), $request->user());

        return back()->with('exito', "{$r['nuevas']} marcas nuevas".($r['repetidas'] ? ", {$r['repetidas']} ya importadas" : '').($r['desconocidas'] ? '. RUT sin ficha activa: '.implode(', ', array_slice($r['desconocidas'], 0, 5)) : '.'));
    }
}
