<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\RRHH\Anticipos;
use App\Modules\RRHH\Models\Anticipo;
use App\Modules\RRHH\Models\Empleado;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** RRHH → Registrar Anticipo (Quincena). */
class AnticiposController extends Controller
{
    public function __construct(private Anticipos $anticipos) {}

    public function index(Request $request)
    {
        $periodo = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('periodo')) ? $request->input('periodo') : now()->format('Y-m');
        $q = Anticipo::with('empleado:id,nombres,apellidos', 'registrador:id,name')->where('periodo', $periodo)->orderByDesc('fecha')->orderByDesc('id');

        return Inertia::render('RRHH/Anticipos', [
            'periodo' => $periodo,
            'anticipos' => $q->get()->map(fn (Anticipo $a) => ['id' => $a->id, 'empleado' => $a->empleado->nombreCompleto(), 'fecha' => $a->fecha->toDateString(), 'monto' => $a->monto,
                'estado' => $a->estado, 'registrador' => $a->registrador?->name]),
            'total' => (int) (clone $q)->where('estado', '<>', 'anulado')->sum('monto'),
            'empleados' => Empleado::with('contratoVigente:id,empleado_id,sueldo_base')->where('estado', 'activo')->whereHas('contratoVigente')->orderBy('apellidos')->get(['id', 'nombres', 'apellidos'])
                ->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombreCompleto(), 'sueldo' => $e->contratoVigente->sueldo_base]),
            'topePct' => (float) app(\App\Modules\Core\Parametros\Parametros::class)->valor('nomina.anticipo_max_pct'),
            'estados' => Anticipo::ESTADOS,
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['empleado_id' => ['required', 'integer'], 'fecha' => ['required', 'date', 'before_or_equal:today'], 'monto' => ['required', 'integer', 'min:1']]);
        $this->anticipos->registrar(Empleado::findOrFail($d['empleado_id']), $d, $request->user());

        return back()->with('exito', 'Anticipo registrado: se descuenta en la liquidación del mes.');
    }

    public function anular(Request $request, Anticipo $anticipo)
    {
        $this->anticipos->anular($anticipo, $request->user());

        return back()->with('exito', 'Anticipo anulado.');
    }
}
