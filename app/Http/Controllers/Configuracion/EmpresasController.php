<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Empresa;
use App\Rules\RutValido;
use App\Support\Exportacion\Csv;
use App\Support\Rut;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Configuración → Negocio: datos del emisor de cada empresa (tenant). */
class EmpresasController extends Controller
{
    public function index(Request $request)
    {
        $q = $this->filtrar($request);

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('configuracion.negocio.exportar'), 403);

            return Csv::descargar('empresas', ['RUT', 'Razón social', 'Nombre fantasía', 'Giro', 'Comuna', 'Activa'],
                $q->get()->map(fn ($e) => [Rut::formatear($e->rut), $e->razon_social, $e->nombre_fantasia, $e->giro, $e->comuna, $e->activo]));
        }

        return Inertia::render('Configuracion/Empresas/Index', [
            'empresas' => $q->paginate(25)->withQueryString()->through(fn ($e) => [
                'id' => $e->id, 'rut' => Rut::formatear($e->rut), 'razon_social' => $e->razon_social,
                'nombre_fantasia' => $e->nombre_fantasia, 'comuna' => $e->comuna, 'activo' => $e->activo,
                'eliminada' => $e->trashed(), 'es_activa' => $e->id === app(\App\Modules\Core\Tenancy\Tenancy::class)->id(),
            ]),
            'filtros' => $request->only('buscar', 'estado'),
            'puedeEditarTodas' => $request->user()->es_superadmin,
        ]);
    }

    public function create()
    {
        abort_unless(request()->user()->es_superadmin, 403, 'Solo SuperAdmin crea empresas.');

        return Inertia::render('Configuracion/Empresas/Form', ['empresa' => null]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->es_superadmin, 403, 'Solo SuperAdmin crea empresas.');
        $e = Empresa::create($this->validar($request));

        return redirect()->route('configuracion.empresas.index')->with('exito', "Empresa {$e->razon_social} creada.");
    }

    public function edit(Empresa $empresa)
    {
        $this->autorizar($empresa);

        return Inertia::render('Configuracion/Empresas/Form', ['empresa' => $empresa->only([
            'id', 'rut', 'razon_social', 'nombre_fantasia', 'giro', 'acteco', 'direccion', 'comuna', 'ciudad',
            'telefono', 'email', 'resolucion_sii_numero', 'activo',
        ]) + ['resolucion_sii_fecha' => $empresa->resolucion_sii_fecha?->toDateString()]]);
    }

    public function update(Request $request, Empresa $empresa)
    {
        $this->autorizar($empresa);
        $empresa->update($this->validar($request, $empresa));

        return redirect()->route('configuracion.empresas.index')->with('exito', 'Empresa actualizada.');
    }

    public function destroy(Empresa $empresa)
    {
        abort_unless(request()->user()->es_superadmin, 403);
        abort_if($empresa->id === app(\App\Modules\Core\Tenancy\Tenancy::class)->id(), 422, 'No puedes eliminar la empresa activa.');
        $empresa->update(['activo' => false]);
        $empresa->delete();

        return back()->with('exito', 'Empresa eliminada (eliminación lógica).');
    }

    private function filtrar(Request $request)
    {
        $user = $request->user();
        $q = Empresa::query()->orderBy('razon_social');
        if (! $user->es_superadmin) {
            $q->whereIn('id', $user->empresas()->pluck('empresas.id'));
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => $q->where('razon_social', 'ilike', "%{$b}%")->orWhere('nombre_fantasia', 'ilike', "%{$b}%")
                ->orWhere('rut', 'ilike', '%'.preg_replace('/[^0-9kK]/', '', $b).'%'));
        }
        match ($request->input('estado')) {
            'inactivas' => $q->where('activo', false),
            'eliminadas' => $q->onlyTrashed(),
            default => $q->where('activo', true),
        };

        return $q;
    }

    private function autorizar(Empresa $empresa): void
    {
        $user = request()->user();
        abort_unless($user->tienePermiso('configuracion.negocio.editar'), 403);
        abort_unless($user->es_superadmin || $empresa->id === app(\App\Modules\Core\Tenancy\Tenancy::class)->id(), 403, 'Solo puedes editar la empresa activa.');
    }

    private function validar(Request $request, ?Empresa $empresa = null): array
    {
        $request->merge(['rut' => Rut::normalizar($request->input('rut'))]);

        return $request->validate([
            'rut' => ['required', new RutValido, Rule::unique('empresas', 'rut')->ignore($empresa?->id)],
            'razon_social' => ['required', 'string', 'max:150'],
            'nombre_fantasia' => ['nullable', 'string', 'max:100'],
            'giro' => ['nullable', 'string', 'max:150'],
            'acteco' => ['nullable', 'string', 'max:10'],
            'direccion' => ['nullable', 'string', 'max:150'],
            'comuna' => ['nullable', 'string', 'max:80'],
            'ciudad' => ['nullable', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'resolucion_sii_numero' => ['nullable', 'integer', 'min:0'],
            'resolucion_sii_fecha' => ['nullable', 'date'],
            'activo' => ['boolean'],
        ]);
    }
}
