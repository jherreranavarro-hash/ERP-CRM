<?php

namespace App\Http\Controllers\Caja;

use App\Http\Controllers\Controller;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\TurnoCaja;
use App\Modules\Caja\Turnos;
use App\Modules\Facturacion\Models\Pago;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Caja → Cierres de Caja: historial de turnos, cuadratura y aprobación de diferencias. */
class CierresController extends Controller
{
    public function __construct(private Turnos $turnos) {}

    public function index(Request $request)
    {
        $q = TurnoCaja::query()->with('caja.local:id,nombre', 'usuario:id,name', 'aprobador:id,name')->orderByDesc('abierto_at');
        if ($c = $request->integer('caja_id')) {
            $q->where('caja_id', $c);
        }
        match ($request->input('estado')) {
            'abierto' => $q->where('estado', 'abierto'),
            'cuadrado' => $q->where('estado', 'cerrado')->where('diferencia', 0)->where('requiere_aprobacion', false),
            'diferencia' => $q->where('estado', 'cerrado')->where(fn ($q) => $q->where('diferencia', '<>', 0)->orWhere('requiere_aprobacion', true)),
            'por_aprobar' => $q->where('requiere_aprobacion', true)->whereNull('aprobado_por'),
            default => null,
        };
        if ($d = $request->date('desde')) {
            $q->where('abierto_at', '>=', $d->startOfDay());
        }
        if ($h = $request->date('hasta')) {
            $q->where('abierto_at', '<=', $h->endOfDay());
        }

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('caja.cierres-de-caja.exportar'), 403);

            return Csv::descargar('cierres-de-caja', ['Caja', 'Local', 'Usuario', 'Apertura', 'Cierre', 'Fondo', 'Esperado', 'Declarado', 'Diferencia', 'Aprobado por'],
                $q->get()->map(fn (TurnoCaja $t) => [$t->caja->nombre, $t->caja->local?->nombre, $t->usuario->name, $t->abierto_at->toDateTimeString(), $t->cerrado_at?->toDateTimeString(),
                    $t->monto_apertura, $t->esperado ? array_sum($t->esperado) : null, $t->declarado ? array_sum($t->declarado) : null, $t->diferencia, $t->aprobador?->name]));
        }

        return Inertia::render('Caja/Cierres/Index', [
            'turnos' => $q->paginate(25)->withQueryString()->through(fn (TurnoCaja $t) => $this->fila($t)),
            'filtros' => $request->only('caja_id', 'estado', 'desde', 'hasta'),
            'cajas' => Caja::orderBy('nombre')->get(['id', 'nombre']),
            'pendientes' => TurnoCaja::where('requiere_aprobacion', true)->whereNull('aprobado_por')->count(),
        ]);
    }

    public function show(Request $request, TurnoCaja $turno)
    {
        // Sin acceso a Cierres de Caja, cada quien ve solo sus propios turnos.
        abort_unless($turno->usuario_id === $request->user()->id || $request->user()->tienePermiso('caja.cierres-de-caja.ver'), 403);
        $turno->load('caja.local:id,nombre', 'usuario:id,name', 'aprobador:id,name');
        $resumen = $turno->abierto() ? $this->turnos->resumen($turno) : null;
        $esperado = $turno->esperado ?? $resumen['esperado'];

        return Inertia::render('Caja/Cierres/Show', [
            'turno' => $this->fila($turno) + [
                // jsonb no guarda el orden de las claves: se ordena por medio (efectivo primero).
                'medios' => collect(\App\Modules\Facturacion\Documentos::MEDIOS_PRESENCIALES)->mapWithKeys(fn ($m) => [$m => $esperado[$m] ?? 0])->map(fn ($v, $m) => [
                    'medio' => Pago::METODOS[$m] ?? $m, 'esperado' => $v, 'declarado' => $turno->declarado[$m] ?? null,
                    'diferencia' => isset($turno->declarado[$m]) ? $turno->declarado[$m] - $v : null,
                ])->values(),
                'otros' => collect($this->turnos->resumen($turno)['otros'])->map(fn ($v, $m) => ['medio' => Pago::METODOS[$m] ?? $m, 'monto' => $v])->values(),
                'observacion' => $turno->observacion,
                'propio' => $turno->usuario_id === $request->user()->id,
                'pagos' => $turno->pagos()->with('documento:id,tipo_dte,folio')->orderBy('id')->get()->map(fn (Pago $p) => [
                    'id' => $p->id, 'documento_id' => $p->documento_id, 'documento' => $p->documento?->nombre(), 'monto' => $p->monto,
                    'metodo' => Pago::METODOS[$p->metodo] ?? $p->metodo, 'hora' => $p->created_at->toIso8601String(), 'referencia' => $p->referencia,
                ]),
            ],
        ]);
    }

    public function aprobar(Request $request, TurnoCaja $turno)
    {
        $this->turnos->aprobarDiferencia($turno, $request->user(), $request->validate(['comentario' => ['nullable', 'string', 'max:500']])['comentario'] ?? null);

        return back()->with('exito', 'Diferencia aprobada.');
    }

    private function fila(TurnoCaja $t): array
    {
        return [
            'id' => $t->id, 'caja' => $t->caja->nombre, 'local' => $t->caja->local?->nombre, 'usuario' => $t->usuario->name,
            'abierto_at' => $t->abierto_at->toIso8601String(), 'cerrado_at' => $t->cerrado_at?->toIso8601String(),
            'estado' => $t->estado, 'monto_apertura' => $t->monto_apertura,
            'esperado' => $t->esperado ? array_sum($t->esperado) : null, 'declarado' => $t->declarado ? array_sum($t->declarado) : null,
            'diferencia' => $t->diferencia, 'requiere_aprobacion' => $t->requiere_aprobacion,
            'aprobado_por' => $t->aprobador?->name, 'aprobado_at' => $t->aprobado_at?->toIso8601String(),
        ];
    }
}
