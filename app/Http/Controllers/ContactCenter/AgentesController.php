<?php

namespace App\Http\Controllers\ContactCenter;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\ContactCenter\Models\Agente;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Cola;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** VentasCRM → Agente: ficha de agente, habilidades, zonas, cola y campañas asignadas. */
class AgentesController extends Controller
{
    public function index(Tenancy $tenancy)
    {
        $agentes = Agente::with('user:id,name,email', 'cola:id,nombre', 'campanas:id,nombre')->orderBy('extension')->get();
        $abiertos = Lead::whereIn('estado', Lead::ABIERTOS)->whereNotNull('agente_id')->groupBy('agente_id')->selectRaw('agente_id, count(*) as n')->pluck('n', 'agente_id');

        return Inertia::render('ContactCenter/Agentes', [
            'agentes' => $agentes->map(fn (Agente $a) => [
                'id' => $a->id, 'user_id' => $a->user_id, 'nombre' => $a->nombre(), 'email' => $a->user?->email, 'extension' => $a->extension, 'habilidades' => $a->habilidades ?? [],
                'zonas' => $a->zonas ?? [], 'capacidad' => $a->capacidad, 'cola_id' => $a->cola_id, 'cola' => $a->cola?->nombre, 'estado' => $a->estado, 'activo' => $a->activo,
                'campanas' => $a->campanas->pluck('id'), 'campanas_nombres' => $a->campanas->pluck('nombre'), 'abiertos' => (int) ($abiertos[$a->id] ?? 0),
            ]),
            'usuarios' => User::whereIn('id', UsuarioEmpresa::where('empresa_id', $tenancy->id())->where('activo', true)->pluck('user_id'))->whereNotIn('id', $agentes->pluck('user_id'))
                ->orderBy('name')->get(['id', 'name']),
            'colas' => Cola::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'campanas' => Campana::whereIn('estado', ['borrador', 'activa', 'pausada'])->orderBy('nombre')->get(['id', 'nombre']),
            'estados' => Agente::ESTADOS,
            'soyAgente' => Agente::where('user_id', request()->user()->id)->where('activo', true)->exists(),
        ]);
    }

    public function store(Request $request)
    {
        $this->guardar($request, new Agente);

        return back()->with('exito', 'Agente creado.');
    }

    public function update(Request $request, Agente $agente)
    {
        $this->guardar($request, $agente);

        return back()->with('exito', 'Agente actualizado.');
    }

    private function guardar(Request $request, Agente $a): void
    {
        $e = app(Tenancy::class)->id();
        $d = $request->validate([
            'user_id' => [$a->exists ? 'prohibited' : 'required', 'integer', Rule::exists('usuario_empresa', 'user_id')->where('empresa_id', $e)->where('activo', true),
                Rule::unique('agentes', 'user_id')->where('empresa_id', $e)],
            'extension' => ['required', 'regex:/^\d{2,6}$/', Rule::unique('agentes', 'extension')->where('empresa_id', $e)->ignore($a->id)],
            'habilidades' => ['array', 'max:10'], 'habilidades.*' => ['string', 'max:30'],
            'zonas' => ['array', 'max:30'], 'zonas.*' => ['string', 'max:60'],
            'capacidad' => ['required', 'integer', 'min:1', 'max:1000'],
            'cola_id' => ['nullable', 'integer', Rule::exists('colas', 'id')->where('empresa_id', $e)],
            'campanas' => ['array'], 'campanas.*' => ['integer', Rule::exists('campanas', 'id')->where('empresa_id', $e)],
            'activo' => ['boolean'],
        ], ['extension.regex' => 'La extensión son 2 a 6 dígitos.', 'user_id.unique' => 'Esa persona ya es agente.']);
        $limpio = fn ($xs) => array_values(array_unique(array_filter(array_map(fn ($x) => trim($x), $xs ?? []))));
        $a->fill(collect($d)->only(['user_id', 'extension', 'capacidad', 'cola_id', 'activo'])->all() + ['habilidades' => $limpio($d['habilidades'] ?? []), 'zonas' => $limpio($d['zonas'] ?? [])]);
        if (! $a->exists) {
            $a->estado = 'desconectado';
        }
        $a->save();
        $a->campanas()->sync(collect($d['campanas'] ?? [])->mapWithKeys(fn ($id) => [$id => ['empresa_id' => $e]])->all());
    }
}
