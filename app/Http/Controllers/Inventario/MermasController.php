<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Inventario\Mermas;
use App\Modules\Inventario\Models\Merma;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Inventario → Mermas: registro con motivo y responsable; aprobación sobre el umbral. */
class MermasController extends Controller
{
    public function __construct(private Mermas $mermas) {}

    public function index(Request $request)
    {
        $q = Merma::with('producto:id,sku,nombre,unidad', 'local:id,nombre', 'responsable:id,name', 'aprobador:id,name')->orderByDesc('id');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }

        return Inertia::render('Inventario/Mermas', [
            'mermas' => $q->paginate(25)->withQueryString()->through(fn (Merma $m) => [
                'id' => $m->id, 'fecha' => $m->created_at->toIso8601String(), 'producto' => $m->producto?->nombre, 'sku' => $m->producto?->sku, 'unidad' => $m->producto?->unidad,
                'local' => $m->local?->nombre, 'cantidad' => (float) $m->cantidad, 'motivo' => Merma::MOTIVOS[$m->motivo], 'detalle' => $m->detalle,
                'costo_total' => $m->costo_total, 'estado' => $m->estado, 'responsable' => $m->responsable?->name, 'aprobador' => $m->aprobador?->name,
                'motivo_rechazo' => $m->motivo_rechazo, 'propia' => $m->responsable_id === $request->user()->id,
            ]),
            'filtros' => $request->only('estado'),
            'motivos' => Merma::MOTIVOS,
            'locales' => Local::where('activo', true)->orderByDesc('es_bodega')->orderBy('nombre')->get(['id', 'nombre']),
            'umbral' => (int) app(Parametros::class)->valor('inventario.merma_aprobacion_monto'),
            'pendientes' => Merma::where('estado', 'por_aprobar')->count(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'local_id' => ['required', 'integer', 'exists:locales,id'],
            'producto_id' => ['required', 'integer'],
            'cantidad' => ['required', 'numeric', 'gt:0'],
            'motivo' => ['required', Rule::in(array_keys(Merma::MOTIVOS))],
            'detalle' => ['required', 'string', 'max:300'],
        ]);
        $m = $this->mermas->registrar($d['local_id'], $d['producto_id'], (string) $d['cantidad'], $d['motivo'], $d['detalle'], $request->user());

        return back()->with('exito', $m->estado === 'por_aprobar'
            ? 'Merma registrada por $'.number_format($m->costo_total, 0, ',', '.').': supera el umbral y queda pendiente de aprobación.'
            : 'Merma registrada: el stock ya se descontó.');
    }

    public function aprobar(Request $request, Merma $merma)
    {
        $this->mermas->aprobar($merma, $request->user());

        return back()->with('exito', 'Merma aprobada: el stock ya se descontó.');
    }

    public function rechazar(Request $request, Merma $merma)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->mermas->rechazar($merma, $request->user(), $d['motivo']);

        return back()->with('exito', 'Merma rechazada: el stock no cambia.');
    }
}
