<?php

namespace App\Http\Controllers\ContactCenter;

use App\Http\Controllers\Controller;
use App\Modules\ContactCenter\CentroContacto;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Cola;
use App\Modules\ContactCenter\Models\Ivr;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** VentasCRM → Tiempo Real (wallboard por WebSocket) y Panel de Control (colas, IVR, horarios de campaña). */
class OperacionController extends Controller
{
    public function tiempoReal(CentroContacto $cc, Tenancy $tenancy)
    {
        return Inertia::render('ContactCenter/TiempoReal', $cc->tablero() + [
            'canal' => 'contact-center.'.$tenancy->id(),
            'conexion' => \App\Support\TiempoReal::conexion(),
        ]);
    }

    public function panel()
    {
        return Inertia::render('ContactCenter/Panel', [
            'colas' => Cola::withCount('agentes')->with('desborde:id,nombre')->orderBy('nombre')->get()->map(fn ($c) => $c->only(['id', 'nombre', 'estrategia', 'espera_max_seg', 'nivel_servicio_seg', 'ivr_desborde_id', 'activo'])
                + ['agentes' => $c->agentes_count, 'desborde' => $c->desborde?->nombre]),
            'campanas' => Campana::whereIn('estado', ['borrador', 'activa', 'pausada'])->orderBy('nombre')->get()->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'estado' => $c->estado,
                'horario' => $c->horario, 'max_intentos' => $c->max_intentos, 'en_horario' => $c->enHorario()]),
            'ivrs' => Ivr::orderBy('nombre')->get(['id', 'nombre', 'activo']),
            'estrategias' => Cola::ESTRATEGIAS,
        ]);
    }

    public function guardarCola(Request $request, ?Cola $cola = null)
    {
        $e = app(Tenancy::class)->id();
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:60', Rule::unique('colas')->where('empresa_id', $e)->ignore($cola?->id)],
            'estrategia' => ['required', Rule::in(array_keys(Cola::ESTRATEGIAS))],
            'espera_max_seg' => ['required', 'integer', 'min:10', 'max:3600'],
            'nivel_servicio_seg' => ['required', 'integer', 'min:5', 'max:600'],
            'ivr_desborde_id' => ['nullable', 'integer', Rule::exists('ivrs', 'id')->where('empresa_id', $e)],
            'activo' => ['boolean'],
        ]);
        $cola ? $cola->update($d) : Cola::create($d);

        return back()->with('exito', 'Cola guardada.');
    }

    public function horario(Request $request, Campana $campana, Auditoria $auditoria)
    {
        $d = $request->validate([
            'horario.dias' => ['required', 'array', 'min:1'], 'horario.dias.*' => ['integer', 'between:1,7'],
            'horario.desde' => ['required', 'date_format:H:i'], 'horario.hasta' => ['required', 'date_format:H:i', 'after:horario.desde'],
            'max_intentos' => ['required', 'integer', 'min:1', 'max:20'],
        ]);
        $antes = $campana->only(['horario', 'max_intentos']);
        $dias = array_values(array_unique(array_map('intval', $d['horario']['dias'])));
        sort($dias);
        $campana->forceFill(['horario' => ['dias' => $dias, 'desde' => $d['horario']['desde'], 'hasta' => $d['horario']['hasta']], 'max_intentos' => $d['max_intentos']])->save();
        $auditoria->registrar('campana.horario', $campana, $antes, $campana->only(['horario', 'max_intentos']));

        return back()->with('exito', 'Horario de discado actualizado.');
    }
}
