<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Liquidacion;
use App\Modules\RRHH\Models\Nomina;
use App\Modules\RRHH\Nominas;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** RRHH → Nómina y Liquidaciones. */
class NominasController extends Controller
{
    public function __construct(private Nominas $nominas) {}

    public function index()
    {
        return Inertia::render('RRHH/Nominas/Index', [
            'nominas' => Nomina::with('generador:id,name', 'aprobador:id,name')->withCount('liquidaciones')->orderByDesc('periodo')->paginate(24)->through(fn (Nomina $n) => [
                'id' => $n->id, 'periodo' => $n->periodo, 'estado' => $n->estado, 'personas' => $n->liquidaciones_count, 'haberes' => $n->total_haberes, 'liquido' => $n->total_liquido,
                'costo' => $n->total_haberes + $n->total_empleador, 'generador' => $n->generador?->name, 'aprobador' => $n->aprobador?->name, 'fecha_pago' => $n->fecha_pago?->toDateString(),
            ]),
            'sugerido' => now()->format('Y-m'),
            'estados' => Nomina::ESTADOS,
        ]);
    }

    public function generar(Request $request)
    {
        $d = $request->validate(['periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $n = $this->nominas->generar($d['periodo'], $request->user());

        return to_route('rrhh.nominas.show', $n)->with('exito', "Nómina {$n->periodo} calculada en borrador.");
    }

    public function show(Request $request, Nomina $nomina)
    {
        $nomina->load('generador:id,name', 'aprobador:id,name');
        $liqs = Liquidacion::with('empleado:id,nombres,apellidos,rut,afp,salud', 'contrato:id,cargo')->where('nomina_id', $nomina->id)->get()->sortBy(fn ($l) => $l->empleado->apellidos)->values();

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('rrhh.nomina-y-liquidaciones.exportar'), 403);

            return Csv::descargar("nomina-{$nomina->periodo}", ['RUT', 'Nombre', 'Días', 'Imponible', 'No imponible', 'AFP', 'Salud', 'Cesantía', 'Impuesto', 'Otros descuentos', 'Líquido', 'Costo empleador'],
                $liqs->map(function ($l) {
                    $d = collect($l->descuentos);
                    $m = fn ($pre) => (int) $d->filter(fn ($x) => str_starts_with($x['concepto'], $pre))->sum('monto');

                    return [$l->empleado->rut, $l->empleado->nombreCompleto(), $l->dias_trabajados, $l->imponible, $l->no_imponible, $m('AFP'), $m('Fonasa') + $m('Isapre'), $m('Seguro de cesantía'),
                        $l->impuesto, (int) $d->where('legal', false)->sum('monto'), $l->liquido, $l->costo_empleador];
                }));
        }

        return Inertia::render('RRHH/Nominas/Show', [
            'nomina' => ['id' => $nomina->id, 'periodo' => $nomina->periodo, 'estado' => $nomina->estado, 'haberes' => $nomina->total_haberes, 'descuentos' => $nomina->total_descuentos,
                'liquido' => $nomina->total_liquido, 'empleador' => $nomina->total_empleador, 'indicadores' => $nomina->indicadores, 'generador' => $nomina->generador?->name,
                'generado_por' => $nomina->generado_por, 'aprobador' => $nomina->aprobador?->name, 'aprobado_at' => $nomina->aprobado_at?->toIso8601String(), 'fecha_pago' => $nomina->fecha_pago?->toDateString()],
            'liquidaciones' => $liqs->map(fn (Liquidacion $l) => [
                'id' => $l->id, 'empleado' => $l->empleado->nombreCompleto(), 'rut' => $l->empleado->rut, 'cargo' => $l->contrato->cargo, 'dias' => $l->dias_trabajados,
                'horas_extra' => (float) $l->horas_extra, 'imponible' => $l->imponible, 'no_imponible' => $l->no_imponible, 'descuentos' => $l->total_descuentos, 'impuesto' => $l->impuesto,
                'liquido' => $l->liquido, 'costo' => $l->costo_empleador, 'haberes_detalle' => $l->haberes, 'descuentos_detalle' => $l->descuentos, 'aportes' => $l->aportes,
                'bonos' => array_values(array_map(fn ($h) => ['concepto' => $h['concepto'], 'monto' => $h['monto']], array_filter($l->haberes, fn ($h) => $h['bono'] ?? false))),
            ]),
            'afps' => Empleado::AFPS,
        ]);
    }

    public function bonos(Request $request, Liquidacion $liquidacion)
    {
        $d = $request->validate(['bonos' => ['present', 'array', 'max:10'], 'bonos.*.concepto' => ['required', 'string', 'max:60'], 'bonos.*.monto' => ['required', 'integer', 'min:1', 'max:100000000']]);
        $this->nominas->ajustarBonos($liquidacion, $d['bonos'], $request->user());

        return back()->with('exito', 'Liquidación recalculada.');
    }

    public function aprobar(Request $request, Nomina $nomina)
    {
        $this->nominas->aprobar($nomina, $request->user());

        return back()->with('exito', 'Nómina aprobada y contabilizada.');
    }

    public function pagar(Request $request, Nomina $nomina)
    {
        $d = $request->validate(['fecha_pago' => ['required', 'date', 'before_or_equal:today']]);
        $this->nominas->pagar($nomina, $d['fecha_pago'], $request->user());

        return back()->with('exito', 'Pago de la nómina registrado.');
    }

    public function pdf(Liquidacion $liquidacion)
    {
        $liquidacion->load('empleado', 'nomina');

        return response($this->nominas->pdf($liquidacion), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="liquidacion-'.$liquidacion->nomina->periodo.'-'.$liquidacion->empleado->rut.'.pdf"',
        ]);
    }
}
