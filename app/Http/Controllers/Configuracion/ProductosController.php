<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Models\Categoria;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Tenancy\Tenancy;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Configuración → Producto y Categoría. Precios netos; el de boleta se deriva. */
class ProductosController extends Controller
{
    public function index(Request $request)
    {
        $q = Producto::query()->with('categoria:id,nombre')->orderBy('nombre');
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => $q->where('nombre', 'ilike', "%{$b}%")->orWhere('sku', 'ilike', "%{$b}%"));
        }
        if ($cat = $request->integer('categoria')) {
            $q->where('categoria_id', $cat);
        }

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('configuracion.producto.exportar'), 403);

            return Csv::descargar('productos', ['SKU', 'Nombre', 'Categoría', 'Unidad', 'Precio neto', 'Precio mayorista', 'Exento'],
                $q->get()->map(fn ($p) => [$p->sku, $p->nombre, $p->categoria?->nombre, $p->unidad, $p->precio_neto, $p->precio_mayorista, $p->exento]));
        }

        $verCosto = $request->user()->tienePermiso('finanzas.rentabilidad.ver') || $request->user()->es_superadmin;

        return Inertia::render('Configuracion/Productos/Index', [
            'productos' => $q->paginate(25)->withQueryString()->through(fn (Producto $p) => $p->only(['id', 'sku', 'nombre', 'categoria_id', 'unidad', 'precio_neto', 'precio_mayorista', 'exento', 'activo'])
                + ['categoria' => $p->categoria?->nombre, 'costo' => $verCosto ? $p->costo : null]),
            'categorias' => Categoria::orderBy('nombre')->get(['id', 'nombre', 'activo']),
            'filtros' => $request->only('buscar', 'categoria'),
            'verCosto' => $verCosto,
        ]);
    }

    public function store(Request $request)
    {
        Producto::create($this->validar($request));

        return back()->with('exito', 'Producto creado.');
    }

    public function update(Request $request, Producto $producto)
    {
        $producto->update($this->validar($request, $producto));

        return back()->with('exito', 'Producto actualizado.');
    }

    public function destroy(Producto $producto)
    {
        $producto->delete();

        return back()->with('exito', 'Producto eliminado (eliminación lógica).');
    }

    public function guardarCategoria(Request $request)
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'max:80', Rule::unique('categorias')->where('empresa_id', app(Tenancy::class)->id())]]);
        Categoria::create($d);

        return back()->with('exito', 'Categoría creada.');
    }

    /** Búsqueda para el formulario de documentos. */
    public function buscar(Request $request)
    {
        $b = $request->string('q')->trim()->value();

        return Producto::query()->where('activo', true)
            ->when($b, fn ($q) => $q->where(fn ($q) => $q->where('nombre', 'ilike', "%{$b}%")->orWhere('sku', 'ilike', "%{$b}%")))
            ->orderBy('nombre')->limit(15)
            ->get(['id', 'sku', 'nombre', 'unidad', 'precio_neto', 'precio_mayorista', 'exento']);
    }

    private function validar(Request $request, ?Producto $p = null): array
    {
        $d = $request->validate([
            'sku' => ['required', 'string', 'max:40', Rule::unique('productos')->where('empresa_id', app(Tenancy::class)->id())->ignore($p?->id)],
            'nombre' => ['required', 'string', 'max:80'],
            'categoria_id' => ['nullable', Rule::exists('categorias', 'id')->where('empresa_id', app(Tenancy::class)->id())],
            'unidad' => ['required', 'string', 'max:10'],
            'precio_neto' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'precio_mayorista' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'costo' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'exento' => ['boolean'],
            'activo' => ['boolean'],
        ]);
        if (! ($request->user()->tienePermiso('finanzas.rentabilidad.ver') || $request->user()->es_superadmin)) {
            unset($d['costo']);
        }

        return $d;
    }
}
