<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\ListasPrecios;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\ListaPrecio;
use App\Modules\Comercial\Models\ListaPrecioItem;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Auditoria\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Configuración → Listas de precios: mesón (base), mayorista y listas propias con vigencia. */
class ListasPreciosController extends Controller
{
    public function __construct(private ListasPrecios $listas, private Auditoria $auditoria) {}

    public function index(Request $request)
    {
        $listas = $this->listas->todas();
        $clientes = Cliente::selectRaw('lista_precio, count(*) as n')->groupBy('lista_precio')->pluck('n', 'lista_precio');
        $items = ListaPrecioItem::selectRaw('lista_id, count(*) as n')->groupBy('lista_id')->pluck('n', 'lista_id');
        $con = $listas->firstWhere('codigo', 'mayorista') ? Producto::whereNotNull('precio_mayorista')->count() : 0;

        $sel = $listas->firstWhere('id', $request->integer('lista')) ?? null;

        return Inertia::render('Configuracion/ListasPrecios', [
            'listas' => $listas->values()->map(fn (ListaPrecio $l) => $l->only(['id', 'codigo', 'nombre', 'regla', 'porcentaje', 'activa', 'sistema']) + [
                'vigente_desde' => $l->vigente_desde?->toDateString(), 'vigente_hasta' => $l->vigente_hasta?->toDateString(),
                'vigente' => $l->vigente(), 'clientes' => (int) ($clientes[$l->codigo] ?? 0),
                'productos' => $l->codigo === 'mayorista' ? $con : (int) ($items[$l->id] ?? 0),
            ]),
            'reglas' => ListaPrecio::REGLAS,
            'seleccionada' => $sel && $sel->regla === 'propio' && $sel->codigo !== 'mayorista' ? [
                'id' => $sel->id, 'nombre' => $sel->nombre,
                'items' => ListaPrecioItem::where('lista_id', $sel->id)->with('producto:id,sku,nombre,precio_neto')->orderBy('id')->get()
                    ->map(fn ($i) => ['id' => $i->id, 'producto_id' => $i->producto_id, 'sku' => $i->producto?->sku, 'nombre' => $i->producto?->nombre,
                        'meson' => (string) $i->producto?->precio_neto, 'precio' => (string) $i->precio_neto]),
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $d = $this->validar($request, null);
        $l = ListaPrecio::create($d);
        $this->listas->olvidar();
        $this->auditoria->registrar('lista_precio.crear', $l, null, $d);

        return back()->with('exito', "Lista {$l->nombre} creada.");
    }

    public function update(Request $request, ListaPrecio $lista)
    {
        $d = $this->validar($request, $lista);
        $antes = $lista->only(array_keys($d));
        if ($lista->sistema) {
            // Mesón es la base y mayorista conserva su código; ambas siempre activas.
            unset($d['codigo'], $d['activa']);
            if ($lista->codigo === 'meson') {
                $d = array_intersect_key($d, ['nombre' => 1]);
            }
        }
        if (isset($d['activa']) && ! $d['activa'] && Cliente::where('lista_precio', $lista->codigo)->exists()) {
            throw ValidationException::withMessages(['activa' => 'Hay clientes con esta lista: cámbialos antes de desactivarla.']);
        }
        $lista->update($d);
        $this->listas->olvidar();
        $this->auditoria->registrar('lista_precio.editar', $lista, $antes, $d);

        return back()->with('exito', 'Lista guardada.');
    }

    public function destroy(ListaPrecio $lista)
    {
        if ($lista->sistema) {
            throw ValidationException::withMessages(['lista' => 'Mesón y mayorista no se eliminan.']);
        }
        if (Cliente::where('lista_precio', $lista->codigo)->exists()) {
            throw ValidationException::withMessages(['lista' => 'Hay clientes con esta lista: cámbialos antes de eliminarla.']);
        }
        $this->auditoria->registrar('lista_precio.eliminar', $lista, $lista->only(['codigo', 'nombre', 'regla', 'porcentaje']), null);
        $lista->delete();

        return to_route('configuracion.listas-precios.index')->with('exito', 'Lista eliminada.');
    }

    /** Agrega o actualiza el precio propio de un producto. */
    public function guardarItem(Request $request, ListaPrecio $lista)
    {
        abort_unless($lista->regla === 'propio' && $lista->codigo !== 'mayorista', 422, 'Esta lista no tiene precios propios.');
        $d = $request->validate(['producto_id' => ['required', 'integer', Rule::exists('productos', 'id')], 'precio' => ['required', 'numeric', 'min:0', 'max:999999999']]);
        $item = ListaPrecioItem::firstOrNew(['lista_id' => $lista->id, 'producto_id' => $d['producto_id']]);
        $antes = $item->exists ? ['precio' => (string) $item->precio_neto] : null;
        $item->precio_neto = $d['precio'];
        $item->save();
        $this->auditoria->registrar('lista_precio.precio', $lista, $antes, ['producto_id' => $d['producto_id'], 'precio' => $d['precio']]);

        return back()->with('exito', 'Precio guardado.');
    }

    public function quitarItem(ListaPrecio $lista, ListaPrecioItem $item)
    {
        abort_unless($item->lista_id === $lista->id, 404);
        $this->auditoria->registrar('lista_precio.quitar', $lista, ['producto_id' => $item->producto_id, 'precio' => (string) $item->precio_neto], null);
        $item->delete();

        return back()->with('exito', 'El producto vuelve al precio mesón en esta lista.');
    }

    /** Precio de un producto en cada lista (para comparar). */
    public function simular(Request $request)
    {
        $p = Producto::findOrFail($request->integer('producto_id'));

        return ['producto' => $p->only(['id', 'sku', 'nombre']), 'precios' => $this->listas->todas()->values()->map(fn ($l) => [
            'codigo' => $l->codigo, 'nombre' => $l->nombre, 'vigente' => $l->vigente(), 'precio' => $this->listas->precio($p, $l->codigo),
        ])];
    }

    private function validar(Request $request, ?ListaPrecio $lista): array
    {
        $d = $request->validate([
            'codigo' => [$lista ? 'sometimes' : 'required', 'string', 'regex:/^[a-z0-9_]{2,12}$/', Rule::unique('listas_precios', 'codigo')->where('empresa_id', app(\App\Modules\Core\Tenancy\Tenancy::class)->id())->ignore($lista?->id)],
            'nombre' => ['required', 'string', 'max:60'],
            'regla' => [$lista?->codigo === 'meson' ? 'nullable' : 'required', Rule::in(['porcentaje', 'propio'])],
            'porcentaje' => ['nullable', 'required_if:regla,porcentaje', 'numeric', 'between:0,90'],
            'vigente_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'activa' => ['boolean'],
        ], ['codigo.regex' => 'Usa de 2 a 12 letras minúsculas, números o guion bajo.', 'porcentaje.required_if' => 'Indica el % bajo mesón.']);
        if (($d['regla'] ?? null) !== 'porcentaje') {
            $d['porcentaje'] = null;
        }
        if ($lista?->codigo === 'meson') {
            unset($d['regla'], $d['porcentaje']);
        }

        return $d;
    }
}
