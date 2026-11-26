<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Finanzas\Honorarios;
use App\Modules\Finanzas\Models\CentroCosto;
use App\Modules\Finanzas\Models\Honorario;
use App\Support\Exportacion\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Finanzas → Libro de Honorarios: boletas de honorarios recibidas, retención y pago. */
class HonorariosController extends Controller
{
    public function __construct(private Honorarios $honorarios) {}

    public function index(Request $request)
    {
        $mes = CarbonImmutable::createFromFormat('Y-m', $request->input('mes', now()->format('Y-m')))->startOfMonth();
        $q = Honorario::with('centro:id,codigo,nombre')->whereBetween('fecha', [$mes->toDateString(), $mes->endOfMonth()->toDateString()])->orderBy('fecha')->orderBy('id');
        $vigentes = (clone $q)->where('estado', '<>', 'anulada');

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.libro-de-honorarios.exportar'), 403);

            return Csv::descargar('libro-honorarios-'.$mes->format('Y-m'), ['Fecha', 'N° boleta', 'RUT', 'Nombre', 'Bruto', 'Retención', 'Líquido', 'Estado'],
                $q->get()->map(fn (Honorario $h) => [$h->fecha->toDateString(), $h->folio, $h->rut, $h->nombre, $h->bruto, $h->retencion, $h->liquido, Honorario::ESTADOS[$h->estado]]));
        }

        return Inertia::render('Finanzas/Honorarios', [
            'mes' => $mes->format('Y-m'),
            'totales' => ['cantidad' => (clone $vigentes)->count(), 'bruto' => (int) (clone $vigentes)->sum('bruto'), 'retencion' => (int) (clone $vigentes)->sum('retencion'), 'liquido' => (int) (clone $vigentes)->sum('liquido')],
            'boletas' => $q->paginate(50)->withQueryString()->through(fn (Honorario $h) => [
                'id' => $h->id, 'fecha' => $h->fecha->toDateString(), 'folio' => $h->folio, 'rut' => $h->rut, 'nombre' => $h->nombre, 'descripcion' => $h->descripcion,
                'bruto' => $h->bruto, 'tasa' => (float) $h->tasa_retencion, 'retencion' => $h->retencion, 'liquido' => $h->liquido, 'estado' => $h->estado,
                'fecha_pago' => $h->fecha_pago?->toDateString(), 'centro' => $h->centro ? "{$h->centro->codigo} · {$h->centro->nombre}" : null,
            ]),
            'tasa' => (float) app(Parametros::class)->valor('tributario.retencion_honorarios_pct'),
            'centros' => CentroCosto::where('activo', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
            'estados' => Honorario::ESTADOS,
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'rut' => ['required', 'string', 'max:12'],
            'nombre' => ['required', 'string', 'max:100'],
            'folio' => ['required', 'integer', 'min:1', 'max:9999999999'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'descripcion' => ['required', 'string', 'max:200'],
            'bruto' => ['required', 'integer', 'min:1', 'max:10000000000'],
            'centro_costo_id' => ['nullable', 'integer', 'exists:centros_costo,id'],
        ]);
        $h = $this->honorarios->registrar($d, $request->user());

        return back()->with('exito', "Boleta N° {$h->folio} registrada: retención $".number_format($h->retencion, 0, ',', '.').', líquido a pagar $'.number_format($h->liquido, 0, ',', '.').'.');
    }

    public function pagar(Request $request, Honorario $honorario)
    {
        $d = $request->validate(['fecha_pago' => ['required', 'date', 'before_or_equal:today']]);
        $this->honorarios->pagar($honorario, $d['fecha_pago'], $request->user());

        return back()->with('exito', 'Pago de la boleta registrado.');
    }

    public function anular(Request $request, Honorario $honorario)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->honorarios->anular($honorario, $d['motivo'], $request->user());

        return back()->with('exito', 'Boleta anulada: su asiento quedó revertido.');
    }
}
