<?php

namespace App\Http\Controllers\ContactCenter;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Models\Categoria;
use App\Modules\ContactCenter\Models\Guion;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** VentasCRM → Script: guiones de venta por pasos, por campaña o categoría de producto. */
class GuionesController extends Controller
{
    public function index()
    {
        return Inertia::render('ContactCenter/Guiones', [
            'guiones' => Guion::withCount('campanas')->with('categoria:id,nombre')->orderBy('nombre')->get()->map(fn ($g) => ['id' => $g->id, 'nombre' => $g->nombre, 'categoria_id' => $g->categoria_id,
                'categoria' => $g->categoria?->nombre, 'pasos' => $g->pasos, 'version' => $g->version, 'activo' => $g->activo, 'campanas' => $g->campanas_count,
                'actualizado' => $g->updated_at?->toIso8601String()]),
            'categorias' => Categoria::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request)
    {
        Guion::create($this->validar($request) + ['version' => 1, 'actualizado_por' => $request->user()->id]);

        return back()->with('exito', 'Guion creado.');
    }

    public function update(Request $request, Guion $guion)
    {
        $d = $this->validar($request, $guion);
        $guion->fill($d + ['actualizado_por' => $request->user()->id]);
        if ($guion->isDirty('pasos')) {
            $guion->version++;
        }
        $guion->save();

        return back()->with('exito', "Guion guardado (versión {$guion->version}).");
    }

    private function validar(Request $request, ?Guion $g = null): array
    {
        $e = app(Tenancy::class)->id();

        return $request->validate([
            'nombre' => ['required', 'string', 'max:100', Rule::unique('guiones')->where('empresa_id', $e)->ignore($g?->id)],
            'categoria_id' => ['nullable', 'integer', Rule::exists('categorias', 'id')->where('empresa_id', $e)],
            'pasos' => ['required', 'array', 'min:1', 'max:30'],
            'pasos.*.titulo' => ['required', 'string', 'max:80'],
            'pasos.*.texto' => ['required', 'string', 'max:3000'],
            'pasos.*.respuestas' => ['array', 'max:6'],
            'pasos.*.respuestas.*.texto' => ['required', 'string', 'max:80'],
            'pasos.*.respuestas.*.siguiente' => ['nullable', 'integer', 'min:0', 'max:29'],
            'activo' => ['boolean'],
        ]);
    }
}
