<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Core\Models\Local;
use App\Modules\Inventario\Models\Recepcion;
use App\Modules\Inventario\Recepciones;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Inventario → Recepción de Mercadería. */
class RecepcionesController extends Controller
{
    public function __construct(private Recepciones $recepciones) {}

    public function index(Request $request)
    {
        $q = Recepcion::with('local:id,nombre', 'creador:id,name')->orderByDesc('numero');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => ctype_digit($b) ? $q->where('numero', (int) $b) : $q->where('proveedor_nombre', 'ilike', "%{$b}%")->orWhere('documento_proveedor', 'ilike', "%{$b}%"));
        }

        return Inertia::render('Inventario/Recepciones/Index', [
            'recepciones' => $q->paginate(25)->withQueryString()->through(fn (Recepcion $r) => [
                'id' => $r->id, 'numero' => $r->numero, 'fecha' => $r->fecha->toDateString(), 'proveedor' => $r->proveedor_nombre, 'documento' => $r->documento_proveedor,
                'local' => $r->local?->nombre, 'costo_total' => $r->costo_total, 'estado' => $r->estado, 'creador' => $r->creador?->name,
            ]),
            'filtros' => $request->only('estado', 'buscar'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Inventario/Recepciones/Form', ['recepcion' => null, 'locales' => $this->locales(), 'orden' => $this->orden($request->integer('orden_compra_id') ?: null)]);
    }

    public function store(Request $request)
    {
        $r = $this->recepciones->guardar($this->validar($request), $request->user());

        return to_route('inventario.recepciones.show', $r)->with('exito', "Recepción N° {$r->numero} guardada. Revísala y confírmala para ingresar el stock.");
    }

    public function show(Recepcion $recepcion)
    {
        return Inertia::render('Inventario/Recepciones/Show', ['recepcion' => $this->detalle($recepcion)]);
    }

    public function edit(Recepcion $recepcion)
    {
        abort_unless($recepcion->estado === 'borrador', 404);

        return Inertia::render('Inventario/Recepciones/Form', ['recepcion' => $this->detalle($recepcion), 'locales' => $this->locales(), 'orden' => $this->orden($recepcion->orden_compra_id)]);
    }

    public function update(Request $request, Recepcion $recepcion)
    {
        $this->recepciones->guardar($this->validar($request), $request->user(), $recepcion);

        return to_route('inventario.recepciones.show', $recepcion)->with('exito', 'Recepción actualizada.');
    }

    public function destroy(Recepcion $recepcion)
    {
        abort_unless($recepcion->estado === 'borrador', 403, 'Una recepción confirmada no se elimina.');
        $recepcion->delete();

        return to_route('inventario.recepciones.index')->with('exito', 'Recepción eliminada.');
    }

    public function confirmar(Request $request, Recepcion $recepcion)
    {
        $r = $this->recepciones->confirmar($recepcion, $request->user());

        return back()->with('exito', "Recepción N° {$r->numero} confirmada: el stock ya está disponible.");
    }

    private function validar(Request $request): array
    {
        $conOc = $request->filled('orden_compra_id');

        return $request->validate([
            'orden_compra_id' => ['nullable', 'integer'],
            'local_id' => [$conOc ? 'nullable' : 'required', 'integer', 'exists:locales,id'],
            'proveedor_nombre' => [$conOc ? 'nullable' : 'required', 'string', 'max:100'],
            'proveedor_rut' => ['nullable', 'string', 'max:12'],
            'documento_proveedor' => ['nullable', 'string', 'max:40'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'lineas' => ['required', 'array', 'min:1', 'max:200'],
            'lineas.*.producto_id' => ['required', 'integer'],
            'lineas.*.orden_compra_linea_id' => ['nullable', 'integer'],
            'lineas.*.cantidad' => ['required', 'numeric', $conOc ? 'min:0' : 'gt:0'],
            'lineas.*.costo_unitario' => ['required', 'numeric', 'min:0'],
        ], [], ['lineas' => 'productos']);
    }

    private function locales()
    {
        return Local::where('activo', true)->orderByDesc('es_bodega')->orderBy('nombre')->get(['id', 'nombre']);
    }

    private function detalle(Recepcion $r): array
    {
        $r->load('lineas.producto:id,sku,nombre,unidad', 'local:id,nombre', 'creador:id,name', 'confirmador:id,name', 'ordenCompra:id,numero');
        $dec = \App\Modules\Inventario\Inventario::dec(...);

        return [
            'id' => $r->id, 'numero' => $r->numero, 'estado' => $r->estado, 'fecha' => $r->fecha->toDateString(), 'local_id' => $r->local_id, 'local' => $r->local?->nombre,
            'proveedor_nombre' => $r->proveedor_nombre, 'proveedor_rut' => $r->proveedor_rut, 'documento_proveedor' => $r->documento_proveedor,
            'observaciones' => $r->observaciones, 'costo_total' => $r->costo_total, 'creador' => $r->creador?->name, 'confirmador' => $r->confirmador?->name,
            'confirmado_at' => $r->confirmado_at?->toIso8601String(), 'oc' => $r->ordenCompra ? ['id' => $r->ordenCompra->id, 'numero' => $r->ordenCompra->numero] : null,
            'lineas' => $r->lineas->map(fn ($l) => ['producto_id' => $l->producto_id, 'orden_compra_linea_id' => $l->orden_compra_linea_id, 'sku' => $l->producto?->sku, 'nombre' => $l->producto?->nombre, 'unidad' => $l->producto?->unidad,
                'cantidad' => $dec($l->cantidad), 'costo_unitario' => $dec($l->costo_unitario)])->all(),
        ];
    }

    /** Orden de compra a recibir, con lo pendiente por línea. */
    private function orden(?int $id): ?array
    {
        if (! $id) {
            return null;
        }
        $o = OrdenCompra::with('proveedor:id,razon_social,rut', 'local:id,nombre', 'lineas.producto:id,sku,nombre,unidad')->findOrFail($id);
        $dec = \App\Modules\Inventario\Inventario::dec(...);

        return [
            'id' => $o->id, 'numero' => $o->numero, 'estado' => $o->estado, 'recibible' => in_array($o->estado, OrdenCompra::RECIBIBLE, true),
            'proveedor' => $o->proveedor?->razon_social, 'rut' => $o->proveedor?->rut, 'local_id' => $o->local_id, 'local' => $o->local?->nombre,
            'lineas' => $o->lineas->map(fn ($l) => ['orden_compra_linea_id' => $l->id, 'producto_id' => $l->producto_id, 'sku' => $l->producto?->sku, 'nombre' => $l->producto?->nombre,
                'unidad' => $l->producto?->unidad, 'pedida' => $dec($l->cantidad), 'pendiente' => $dec($l->pendiente()), 'costo_unitario' => $dec($l->costo_unitario)])->all(),
        ];
    }
}
