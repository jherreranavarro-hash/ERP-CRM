<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\Proveedor;
use App\Modules\Core\Tenancy\Tenancy;
use App\Rules\RutValido;
use App\Support\Exportacion\Csv;
use App\Support\Rut;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Configuración → Proveedores. RUT único por empresa y validado con módulo 11. */
class ProveedoresController extends Controller
{
    public function index(Request $request)
    {
        $q = Proveedor::query()->orderBy('razon_social');
        if ($b = $request->string('buscar')->trim()->value()) {
            $rut = Rut::normalizar($b);
            $q->where(fn ($q) => $q->where('razon_social', 'ilike', "%{$b}%")->when($rut && preg_match('/\d/', $rut), fn ($q) => $q->orWhere('rut', 'ilike', "%{$rut}%")));
        }
        $deudas = \App\Modules\Compras\Models\FacturaProveedor::where('estado', 'por_pagar')->groupBy('proveedor_id')->selectRaw('proveedor_id, sum(saldo) as deuda')->pluck('deuda', 'proveedor_id');

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('configuracion.proveedores.exportar'), 403);

            return Csv::descargar('proveedores', ['RUT', 'Razón social', 'Giro', 'Dirección', 'Comuna', 'Correo', 'Teléfono', 'Contacto', 'Plazo de pago', 'Deuda', 'Activo'],
                $q->get()->map(fn ($p) => [$p->rut, $p->razon_social, $p->giro, $p->direccion, $p->comuna, $p->email, $p->telefono, $p->contacto, $p->plazo_pago_dias, (int) ($deudas[$p->id] ?? 0), $p->activo]));
        }

        return Inertia::render('Configuracion/Proveedores/Index', [
            'proveedores' => $q->paginate(25)->withQueryString()->through(fn (Proveedor $p) => $p->only(['id', 'rut', 'razon_social', 'giro', 'direccion', 'comuna', 'email', 'telefono', 'contacto', 'plazo_pago_dias', 'activo']) + ['deuda' => (int) ($deudas[$p->id] ?? 0)]),
            'filtros' => $request->only('buscar'),
        ]);
    }

    public function store(Request $request)
    {
        $p = Proveedor::create($this->validar($request));

        return $request->wantsJson() ? response()->json(['id' => $p->id, 'razon_social' => $p->razon_social, 'rut' => $p->rut]) : back()->with('exito', 'Proveedor creado.');
    }

    public function update(Request $request, Proveedor $proveedor)
    {
        $proveedor->update($this->validar($request, $proveedor));

        return back()->with('exito', 'Proveedor actualizado.');
    }

    public function destroy(Proveedor $proveedor)
    {
        $proveedor->delete();

        return back()->with('exito', 'Proveedor eliminado (eliminación lógica): sus órdenes y facturas se conservan.');
    }

    /** Búsqueda para órdenes de compra y facturas. */
    public function buscar(Request $request)
    {
        $b = $request->string('q')->trim()->value();

        return Proveedor::query()->where('activo', true)
            ->when($b, fn ($q) => $q->where(fn ($q) => $q->where('razon_social', 'ilike', "%{$b}%")->when(preg_match('/\d/', (string) Rut::normalizar($b)), fn ($q) => $q->orWhere('rut', 'ilike', '%'.Rut::normalizar($b).'%'))))
            ->orderBy('razon_social')->limit(15)->get(['id', 'rut', 'razon_social', 'plazo_pago_dias']);
    }

    private function validar(Request $request, ?Proveedor $proveedor = null): array
    {
        $request->merge(['rut' => Rut::normalizar((string) $request->input('rut'))]);

        return $request->validate([
            'rut' => ['required', new RutValido, Rule::unique('proveedores')->where('empresa_id', app(Tenancy::class)->id())->ignore($proveedor?->id)],
            'razon_social' => ['required', 'string', 'max:100'],
            'giro' => ['nullable', 'string', 'max:80'],
            'direccion' => ['nullable', 'string', 'max:100'],
            'comuna' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'contacto' => ['nullable', 'string', 'max:100'],
            'plazo_pago_dias' => ['required', 'integer', 'min:0', 'max:365'],
            'activo' => ['boolean'],
        ]);
    }
}
