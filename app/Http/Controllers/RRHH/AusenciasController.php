<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\RRHH\Ausencias;
use App\Modules\RRHH\Models\Ausencia;
use App\Modules\RRHH\Models\Empleado;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** RRHH → Vacaciones y Ausencias: solicitud, aprobación y saldo por antigüedad. */
class AusenciasController extends Controller
{
    public function __construct(private Ausencias $ausencias) {}

    public function index(Request $request)
    {
        $estado = $request->input('estado', 'solicitada');
        $q = Ausencia::with('empleado:id,nombres,apellidos,user_id', 'solicitante:id,name', 'resolutor:id,name')->orderByDesc('desde');
        if (array_key_exists($estado, Ausencia::ESTADOS)) {
            $q->where('estado', $estado);
        }
        if ($t = $request->input('tipo')) {
            $q->where('tipo', $t);
        }
        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('rrhh.vacaciones-y-ausencias.exportar'), 403);

            return Csv::descargar('ausencias', ['Persona', 'Tipo', 'Desde', 'Hasta', 'Días hábiles', 'Estado'], $q->get()->map(fn ($a) => [$a->empleado->nombreCompleto(), Ausencia::TIPOS[$a->tipo], $a->desde->toDateString(), $a->hasta->toDateString(), $a->dias_habiles, Ausencia::ESTADOS[$a->estado]]));
        }
        $uid = $request->user()->id;

        return Inertia::render('RRHH/Ausencias', [
            'ausencias' => $q->paginate(30)->withQueryString()->through(fn (Ausencia $a) => [
                'id' => $a->id, 'empleado_id' => $a->empleado_id, 'empleado' => $a->empleado->nombreCompleto(), 'tipo' => $a->tipo, 'desde' => $a->desde->toDateString(), 'hasta' => $a->hasta->toDateString(),
                'dias' => $a->dias_habiles, 'detalle' => $a->detalle, 'estado' => $a->estado, 'solicitante' => $a->solicitante?->name, 'resolutor' => $a->resolutor?->name,
                'motivo_rechazo' => $a->motivo_rechazo, 'propia' => $a->solicitado_por === $uid || $a->empleado->user_id === $uid,
            ]),
            'empleados' => Empleado::where('estado', 'activo')->orderBy('apellidos')->get(['id', 'nombres', 'apellidos'])->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombreCompleto()]),
            'filtros' => ['estado' => $estado, 'tipo' => $request->input('tipo')],
            'tipos' => Ausencia::TIPOS,
            'estados' => Ausencia::ESTADOS,
            'pendientes' => Ausencia::where('estado', 'solicitada')->count(),
        ]);
    }

    public function saldo(Empleado $empleado)
    {
        return $this->ausencias->saldoVacaciones($empleado);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'empleado_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(array_keys(Ausencia::TIPOS))],
            'desde' => ['required', 'date'],
            'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'detalle' => ['nullable', 'string', 'max:200'],
        ]);
        $this->ausencias->solicitar(Empleado::findOrFail($d['empleado_id']), $d, $request->user());

        return back()->with('exito', 'Solicitud registrada: la aprueba otra persona.');
    }

    public function aprobar(Request $request, Ausencia $ausencia)
    {
        $this->ausencias->resolver($ausencia, true, $request->user());

        return back()->with('exito', 'Ausencia aprobada.');
    }

    public function rechazar(Request $request, Ausencia $ausencia)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->ausencias->resolver($ausencia, false, $request->user(), $d['motivo']);

        return back()->with('exito', 'Solicitud rechazada.');
    }

    public function anular(Request $request, Ausencia $ausencia)
    {
        $this->ausencias->anular($ausencia, $request->user());

        return back()->with('exito', 'Ausencia anulada.');
    }
}
