<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\RRHH\Finiquitos;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Finiquito;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** RRHH → Desvinculaciones y Finiquitos. */
class FiniquitosController extends Controller
{
    public function __construct(private Finiquitos $finiquitos) {}

    public function index(Request $request)
    {
        $uid = $request->user()->id;

        return Inertia::render('RRHH/Finiquitos', [
            'finiquitos' => Finiquito::with('empleado:id,nombres,apellidos,rut')->orderByDesc('id')->paginate(30)->through(fn (Finiquito $f) => [
                'id' => $f->id, 'empleado' => $f->empleado->nombreCompleto(), 'empleado_id' => $f->empleado_id, 'causal' => $f->causal, 'fecha' => $f->fecha_termino->toDateString(),
                'anios' => (float) $f->anios_servicio, 'base' => $f->base_calculo, 'conceptos' => $f->conceptos, 'total' => $f->total, 'estado' => $f->estado,
                'aviso_previo' => $f->aviso_previo, 'propio' => $f->creado_por === $uid,
            ]),
            'empleados' => Empleado::where('estado', 'activo')->whereHas('contratoVigente')->orderBy('apellidos')->get(['id', 'nombres', 'apellidos'])->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombreCompleto()]),
            'causales' => Finiquito::CAUSALES,
            'conIndemnizacion' => Finiquito::CON_INDEMNIZACION,
            'estados' => Finiquito::ESTADOS,
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'empleado_id' => ['required', 'integer'],
            'causal' => ['required', Rule::in(array_keys(Finiquito::CAUSALES))],
            'fecha_termino' => ['required', 'date'],
            'aviso_previo' => ['boolean'],
        ]);
        $this->finiquitos->calcular(Empleado::findOrFail($d['empleado_id']), $d, $request->user());

        return back()->with('exito', 'Finiquito calculado en borrador: lo aprueba otra persona.');
    }

    public function aprobar(Request $request, Finiquito $finiquito)
    {
        $this->finiquitos->aprobar($finiquito, $request->user());

        return back()->with('exito', 'Finiquito aprobado: contrato terminado y ficha desvinculada.');
    }

    public function firmar(Request $request, Finiquito $finiquito)
    {
        $d = $request->validate(['fecha_pago' => ['required', 'date', 'before_or_equal:today']]);
        $this->finiquitos->firmar($finiquito, $d['fecha_pago'], $request->user());

        return back()->with('exito', 'Finiquito firmado y pagado.');
    }

    public function anular(Request $request, Finiquito $finiquito)
    {
        $this->finiquitos->anular($finiquito, $request->user());

        return back()->with('exito', 'Finiquito anulado.');
    }
}
