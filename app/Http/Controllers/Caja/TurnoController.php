<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\TurnoCaja;
use App\Modules\Caja\Turnos;
use App\Modules\Core\Models\Local;
use App\Modules\Facturacion\Models\Pago;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Caja → Caja: el turno del usuario (apertura, cobros del turno y cierre). */
class TurnoController extends Controller
{
    public function __construct(private Turnos $turnos) {}

    public function index(Request $request)
    {
        $turno = $this->turnos->abierto($request->user());
        $ocupadas = TurnoCaja::where('estado', 'abierto')->with('usuario:id,name')->get()->keyBy('caja_id');

        return Inertia::render('Caja/Turno', [
            'turno' => $turno ? [
                'id' => $turno->id,
                'caja' => $turno->caja->nombre,
                'local' => $turno->caja->local?->nombre,
                'abierto_at' => $turno->abierto_at->toIso8601String(),
                'monto_apertura' => $turno->monto_apertura,
                'resumen' => $this->turnos->resumen($turno),
                'pagos' => $turno->pagos()->with('documento:id,tipo_dte,folio')->latest('id')->limit(50)->get()->map(fn (Pago $p) => [
                    'id' => $p->id, 'documento_id' => $p->documento_id, 'documento' => $p->documento?->nombre(), 'monto' => $p->monto,
                    'metodo' => Pago::METODOS[$p->metodo] ?? $p->metodo, 'hora' => $p->created_at->toIso8601String(),
                ]),
            ] : null,
            'cajas' => Caja::with('local:id,nombre')->where('activo', true)->orderBy('local_id')->orderBy('nombre')->get()->map(fn (Caja $c) => [
                'id' => $c->id, 'nombre' => $c->nombre, 'local' => $c->local?->nombre, 'ocupada_por' => $ocupadas[$c->id]->usuario->name ?? null,
            ]),
            'locales' => $request->user()->tienePermiso('configuracion.local.editar') ? Local::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']) : [],
            'medios' => ['efectivo' => 'Efectivo', 'debito' => 'Débito', 'credito' => 'Crédito'],
        ]);
    }

    public function abrir(Request $request)
    {
        $d = $request->validate(['caja_id' => ['required', 'integer'], 'monto_apertura' => ['required', 'integer', 'min:0', 'max:100000000']]);
        $turno = $this->turnos->abrir(Caja::findOrFail($d['caja_id']), (int) $d['monto_apertura'], $request->user());

        return back()->with('exito', "Turno abierto en {$turno->caja->nombre}.");
    }

    public function cerrar(Request $request)
    {
        $d = $request->validate([
            'declarado' => ['required', 'array'],
            'declarado.*' => ['nullable', 'integer', 'min:0'],
            'observacion' => ['nullable', 'string', 'max:300'],
        ]);
        $turno = $this->turnos->abierto($request->user()) ?? abort(404, 'No tienes un turno abierto.');
        $t = $this->turnos->cerrar($turno, $d['declarado'], $request->user(), $d['observacion'] ?? null);

        $cuadra = $t->declarado == $t->esperado;

        return to_route('caja.cierres.show', $t)->with('exito', match (true) {
            $cuadra => 'Turno cerrado: la caja cuadra.',
            $t->requiere_aprobacion => 'Turno cerrado con diferencia: queda pendiente de aprobación.',
            default => 'Turno cerrado con diferencia dentro de la tolerancia.',
        });
    }

    public function guardarCaja(Request $request)
    {
        $d = $request->validate([
            'local_id' => ['required', 'integer', Rule::exists('locales', 'id')],
            'nombre' => ['required', 'string', 'max:60'],
        ]);
        abort_if(Caja::where('local_id', $d['local_id'])->where('nombre', $d['nombre'])->exists(), 422, 'Ya existe una caja con ese nombre en el local.');
        Caja::create($d);

        return back()->with('exito', 'Caja creada.');
    }
}
