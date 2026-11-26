<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Turno;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** RRHH → Gestión de Turnos: horarios y asignación de personas. */
class TurnosController extends Controller
{
    public function index()
    {
        $max = (int) app(\App\Modules\Core\Parametros\Parametros::class)->valor('nomina.jornada_maxima');

        return Inertia::render('RRHH/Turnos', [
            'turnos' => Turno::withCount(['empleados' => fn ($q) => $q->where('estado', 'activo')])->orderBy('nombre')->get()->map(fn (Turno $t) => [
                'id' => $t->id, 'nombre' => $t->nombre, 'hora_inicio' => substr($t->hora_inicio, 0, 5), 'hora_fin' => substr($t->hora_fin, 0, 5), 'colacion_min' => $t->colacion_min,
                'dias' => $t->dias, 'activo' => $t->activo, 'personas' => $t->empleados_count, 'semanal' => round($t->minutos() * count($t->dias) / 60, 1),
            ]),
            'sinTurno' => Empleado::where('estado', 'activo')->whereNull('turno_id')->orderBy('apellidos')->get(['id', 'nombres', 'apellidos', 'cargo'])
                ->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombreCompleto(), 'cargo' => $e->cargo]),
            'jornadaMaxima' => $max,
        ]);
    }

    public function store(Request $request)
    {
        Turno::create($this->validar($request));

        return back()->with('exito', 'Turno creado.');
    }

    public function update(Request $request, Turno $turno)
    {
        $turno->update($this->validar($request, $turno));

        return back()->with('exito', 'Turno actualizado.');
    }

    public function asignar(Request $request, Turno $turno)
    {
        $d = $request->validate(['empleados' => ['required', 'array', 'min:1'], 'empleados.*' => ['integer']]);
        $n = Empleado::where('estado', 'activo')->whereIn('id', $d['empleados'])->update(['turno_id' => $turno->id]);

        return back()->with('exito', "{$n} personas asignadas a {$turno->nombre}.");
    }

    private function validar(Request $request, ?Turno $t = null): array
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:60', Rule::unique('turnos')->where('empresa_id', app(\App\Modules\Core\Tenancy\Tenancy::class)->id())->ignore($t?->id)],
            'hora_inicio' => ['required', 'date_format:H:i'],
            'hora_fin' => ['required', 'date_format:H:i', 'different:hora_inicio'],
            'colacion_min' => ['required', 'integer', 'min:0', 'max:120'],
            'dias' => ['required', 'array', 'min:1'],
            'dias.*' => ['integer', 'between:1,7'],
            'activo' => ['boolean'],
        ]);
        $d['dias'] = array_values(array_unique(array_map('intval', $d['dias'])));
        sort($d['dias']);
        $min = (new Turno($d))->minutos();
        $max = (int) app(\App\Modules\Core\Parametros\Parametros::class)->valor('nomina.jornada_maxima');
        if ($min * count($d['dias']) > $max * 60) {
            throw \Illuminate\Validation\ValidationException::withMessages(['dias' => 'El turno suma '.round($min * count($d['dias']) / 60, 1)." horas semanales: supera la jornada máxima de {$max} horas."]);
        }

        return $d;
    }
}
