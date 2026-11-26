<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Prestamo;
use App\Modules\RRHH\Prestamos;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** RRHH → Gestión de Préstamos. */
class PrestamosController extends Controller
{
    public function __construct(private Prestamos $prestamos) {}

    public function index(Request $request)
    {
        $uid = $request->user()->id;
        $q = Prestamo::with('empleado:id,nombres,apellidos,user_id', 'solicitante:id,name')->orderByRaw("estado = 'por_aprobar' desc")->orderByDesc('id');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }

        return Inertia::render('RRHH/Prestamos', [
            'prestamos' => $q->paginate(30)->withQueryString()->through(fn (Prestamo $p) => [
                'id' => $p->id, 'empleado' => $p->empleado->nombreCompleto(), 'empleado_id' => $p->empleado_id, 'monto' => $p->monto, 'cuotas' => $p->cuotas, 'valor_cuota' => $p->valor_cuota,
                'saldo' => $p->saldo, 'primer_periodo' => $p->primer_periodo, 'motivo' => $p->motivo, 'estado' => $p->estado, 'solicitante' => $p->solicitante?->name,
                'propio' => $p->solicitado_por === $uid || $p->empleado->user_id === $uid,
            ]),
            'empleados' => Empleado::where('estado', 'activo')->whereHas('contratoVigente')->orderBy('apellidos')->get(['id', 'nombres', 'apellidos'])->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombreCompleto()]),
            'filtros' => $request->only('estado'),
            'estados' => Prestamo::ESTADOS,
            'totales' => ['vigente' => (int) Prestamo::where('estado', 'vigente')->sum('saldo'), 'por_aprobar' => Prestamo::where('estado', 'por_aprobar')->count()],
            'topePct' => (float) app(\App\Modules\Core\Parametros\Parametros::class)->valor('nomina.prestamo_max_cuota_pct'),
            'sugerido' => now()->addMonthNoOverflow()->format('Y-m'),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'empleado_id' => ['required', 'integer'],
            'monto' => ['required', 'integer', 'min:1', 'max:100000000'],
            'cuotas' => ['required', 'integer', 'min:1', 'max:48'],
            'primer_periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'motivo' => ['required', 'string', 'max:200'],
        ]);
        $this->prestamos->solicitar(Empleado::findOrFail($d['empleado_id']), $d, $request->user());

        return back()->with('exito', 'Préstamo solicitado: lo aprueba otra persona.');
    }

    public function aprobar(Request $request, Prestamo $prestamo)
    {
        $this->prestamos->resolver($prestamo, true, $request->user());

        return back()->with('exito', 'Préstamo aprobado y contabilizado.');
    }

    public function rechazar(Request $request, Prestamo $prestamo)
    {
        $this->prestamos->resolver($prestamo, false, $request->user());

        return back()->with('exito', 'Préstamo rechazado.');
    }
}
