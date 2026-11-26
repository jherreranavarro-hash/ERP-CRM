<?php

namespace App\Http\Controllers\ContactCenter;

use App\Http\Controllers\Controller;
use App\Modules\ContactCenter\Asignador;
use App\Modules\ContactCenter\Models\Agente;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Lead;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** VentasCRM → Asignación: reparto automático de prospectos y reasignación manual. */
class AsignacionController extends Controller
{
    public function index(Request $request)
    {
        $campanas = Campana::with('agentes.user:id,name')->whereIn('estado', ['borrador', 'activa', 'pausada'])->orderBy('nombre')->get();
        $sin = Lead::where('estado', 'nuevo')->whereNull('agente_id')->groupBy('campana_id')->selectRaw('campana_id, count(*) as n')->pluck('n', 'campana_id');
        $carga = Lead::whereIn('estado', Lead::ABIERTOS)->whereNotNull('agente_id')->groupBy('agente_id')->selectRaw('agente_id, count(*) as n')->pluck('n', 'agente_id');
        $campanaId = (int) $request->input('campana', $campanas->first()?->id);
        $leads = $campanaId ? Lead::with('agente.user:id,name')->where('campana_id', $campanaId)->whereIn('estado', Lead::ABIERTOS)->orderBy('agente_id')->orderBy('id')->paginate(50)->withQueryString() : null;

        return Inertia::render('ContactCenter/Asignacion', [
            'campanas' => $campanas->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'estado' => $c->estado, 'regla' => $c->asignacion, 'sin_asignar' => (int) ($sin[$c->id] ?? 0),
                'agentes' => $c->agentes->map(fn ($a) => ['id' => $a->id, 'nombre' => $a->nombre(), 'abiertos' => (int) ($carga[$a->id] ?? 0), 'capacidad' => $a->capacidad, 'zonas' => $a->zonas ?? []])]),
            'campanaId' => $campanaId ?: null,
            'leads' => $leads?->through(fn ($l) => ['id' => $l->id, 'nombre' => $l->nombre, 'comuna' => $l->comuna, 'estado' => $l->estado, 'agente_id' => $l->agente_id, 'agente' => $l->agente?->nombre()]),
            'reglas' => Campana::ASIGNACION, 'estadosLead' => Lead::ESTADOS,
        ]);
    }

    public function asignar(Request $request, Campana $campana, Asignador $asignador)
    {
        $r = $asignador->asignar($campana, $request->user());

        return back()->with('exito', "{$r['asignados']} prospectos asignados".($r['sin_agente'] ? "; {$r['sin_agente']} sin agente disponible (revisa zonas o capacidad)" : '').'.');
    }

    public function reasignar(Request $request, Asignador $asignador)
    {
        $d = $request->validate(['leads' => ['required', 'array', 'min:1', 'max:500'], 'leads.*' => ['integer'], 'agente_id' => ['required', 'integer']]);
        $n = $asignador->reasignar($d['leads'], Agente::findOrFail($d['agente_id']), $request->user());

        return back()->with('exito', "{$n} prospectos reasignados.");
    }
}
