<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Local;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Configuración → Local. Tabla con RLS: solo se ven los locales de la empresa activa. */
class LocalesController extends Controller
{
    public function index(Request $request)
    {
        $q = Local::query()->orderBy('codigo');
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => $q->where('nombre', 'ilike', "%{$b}%")->orWhere('codigo', 'ilike', "%{$b}%")->orWhere('comuna', 'ilike', "%{$b}%"));
        }
        $request->input('estado') === 'eliminados' ? $q->onlyTrashed() : null;

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('configuracion.local.exportar'), 403);

            return Csv::descargar('locales', ['Código', 'Nombre', 'Dirección', 'Comuna', 'Bodega', 'Activo'],
                $q->get()->map(fn ($l) => [$l->codigo, $l->nombre, $l->direccion, $l->comuna, $l->es_bodega, $l->activo]));
        }

        return Inertia::render('Configuracion/Locales/Index', [
            'locales' => $q->paginate(25)->withQueryString()->through(fn ($l) => $l->only(['id', 'codigo', 'nombre', 'direccion', 'comuna', 'telefono', 'es_bodega', 'activo']) + ['eliminado' => $l->trashed()]),
            'filtros' => $request->only('buscar', 'estado'),
        ]);
    }

    public function store(Request $request)
    {
        Local::create($this->validar($request));

        return back()->with('exito', 'Local creado.');
    }

    public function update(Request $request, Local $local)
    {
        $local->update($this->validar($request, $local));

        return back()->with('exito', 'Local actualizado.');
    }

    public function destroy(Local $local)
    {
        $local->delete();

        return back()->with('exito', 'Local eliminado (eliminación lógica).');
    }

    public function restore(int $id)
    {
        Local::onlyTrashed()->findOrFail($id)->restore();

        return back()->with('exito', 'Local restaurado.');
    }

    private function validar(Request $request, ?Local $local = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:20', Rule::unique('locales')->where('empresa_id', app(\App\Modules\Core\Tenancy\Tenancy::class)->id())->ignore($local?->id)],
            'nombre' => ['required', 'string', 'max:100'],
            'direccion' => ['nullable', 'string', 'max:150'],
            'comuna' => ['nullable', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'es_bodega' => ['boolean'],
            'activo' => ['boolean'],
        ]);
    }
}
