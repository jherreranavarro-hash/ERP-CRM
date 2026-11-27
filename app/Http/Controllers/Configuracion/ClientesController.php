<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Core\Tenancy\Tenancy;
use App\Rules\RutValido;
use App\Support\Exportacion\Csv;
use App\Support\Rut;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Configuración → Clientes. RUT único por empresa y validado con módulo 11. */
class ClientesController extends Controller
{
    public function index(Request $request)
    {
        $q = Cliente::query()->orderBy('razon_social');
        if ($b = $request->string('buscar')->trim()->value()) {
            $rut = Rut::normalizar($b);
            $q->where(fn ($q) => $q->where('razon_social', 'ilike', "%{$b}%")->when($rut && preg_match('/\d/', $rut), fn ($q) => $q->orWhere('rut', 'ilike', "%{$rut}%")));
        }
        if ($request->input('credito') === '1') {
            $q->where('tiene_credito', true);
        }

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('configuracion.clientes.exportar'), 403);

            return Csv::descargar('clientes', ['RUT', 'Razón social', 'Giro', 'Dirección', 'Comuna', 'Lista', 'Crédito', 'Cupo', 'Plazo'],
                $q->get()->map(fn ($c) => [$c->rut, $c->razon_social, $c->giro, $c->direccion, $c->comuna, $c->lista_precio, $c->tiene_credito, $c->cupo_credito, $c->plazo_dias]));
        }

        return Inertia::render('Configuracion/Clientes/Index', [
            'clientes' => $q->paginate(25)->withQueryString()->through(fn (Cliente $c) => $c->only(['id', 'rut', 'razon_social', 'giro', 'direccion', 'comuna', 'ciudad', 'email', 'telefono', 'lista_precio', 'tiene_credito', 'cupo_credito', 'plazo_dias', 'activo'])),
            'filtros' => $request->only('buscar', 'credito'),
            'plazos' => app(\App\Modules\Core\Parametros\Parametros::class)->valor('credito.plazos_dias'),
            'listas' => app(\App\Modules\Comercial\ListasPrecios::class)->todas()->where('activa', true)->values()->map->only(['codigo', 'nombre']),
        ]);
    }

    public function store(Request $request)
    {
        $c = Cliente::create($this->validar($request));

        return $request->wantsJson() ? response()->json(['id' => $c->id]) : back()->with('exito', 'Cliente creado.');
    }

    public function update(Request $request, Cliente $cliente)
    {
        $cliente->update($this->validar($request, $cliente));

        return back()->with('exito', 'Cliente actualizado.');
    }

    public function destroy(Cliente $cliente)
    {
        $cliente->delete();

        return back()->with('exito', 'Cliente eliminado (eliminación lógica).');
    }

    /** Búsqueda para el formulario de documentos. */
    public function buscar(Request $request)
    {
        $b = $request->string('q')->trim()->value();

        return Cliente::query()->where('activo', true)
            ->when($b, fn ($q) => $q->where(fn ($q) => $q->where('razon_social', 'ilike', "%{$b}%")->when(preg_match('/\d/', (string) Rut::normalizar($b)), fn ($q) => $q->orWhere('rut', 'ilike', '%'.Rut::normalizar($b).'%'))))
            ->orderBy('razon_social')->limit(15)
            ->get(['id', 'rut', 'razon_social', 'giro', 'direccion', 'comuna', 'lista_precio', 'tiene_credito', 'cupo_credito', 'plazo_dias']);
    }

    private function validar(Request $request, ?Cliente $cliente = null): array
    {
        $request->merge(['rut' => Rut::normalizar((string) $request->input('rut'))]);
        $d = $request->validate([
            'rut' => ['required', new RutValido, Rule::unique('clientes')->where('empresa_id', app(Tenancy::class)->id())->ignore($cliente?->id)],
            'razon_social' => ['required', 'string', 'max:100'],
            'giro' => ['nullable', 'string', 'max:80'],
            'direccion' => ['nullable', 'string', 'max:70'],
            'comuna' => ['nullable', 'string', 'max:40'],
            'ciudad' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'lista_precio' => ['required', Rule::in(app(\App\Modules\Comercial\ListasPrecios::class)->codigos())],
            'tiene_credito' => ['boolean'],
            'cupo_credito' => ['nullable', 'integer', 'min:0'],
            'plazo_dias' => ['nullable', 'integer', 'min:0', 'max:365'],
            'activo' => ['boolean'],
        ]);
        // Crédito y cupo los define quien tiene permiso sobre cuentas corrientes.
        if (! $request->user()->tienePermiso('configuracion.cuentas-corrientes.editar')) {
            unset($d['tiene_credito'], $d['cupo_credito'], $d['plazo_dias']);
        }

        return $d;
    }
}
