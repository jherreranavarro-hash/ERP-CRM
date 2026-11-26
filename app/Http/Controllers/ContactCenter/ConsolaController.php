<?php

namespace App\Http\Controllers\ContactCenter;

use App\Http\Controllers\Controller;
use App\Modules\ContactCenter\CentroContacto;
use App\Modules\ContactCenter\Models\Agente;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\Llamada;
use App\Modules\ContactCenter\Telefonia\Pbx;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Consola del agente: su estado, su cola de prospectos, la llamada en curso con el guion y la tipificación. */
class ConsolaController extends Controller
{
    public function __construct(private CentroContacto $cc) {}

    private function agente(Request $request): Agente
    {
        return Agente::with('campanas')->where('user_id', $request->user()->id)->where('activo', true)->first()
            ?? abort(403, 'No estás registrado como agente del contact center.');
    }

    public function show(Request $request, Pbx $pbx)
    {
        $a = $this->agente($request);
        $actual = Llamada::with('lead.campana.guion', 'campana.guion')->where('agente_id', $a->id)->where(fn ($q) => $q->whereIn('estado', Llamada::ACTIVAS)->orWhereNull('resultado')
            ->where('inicio', '>=', now()->subHours(12)))->latest('id')->first();
        $leads = Lead::with('campana:id,nombre,estado,horario,max_intentos')->where('agente_id', $a->id)->whereIn('estado', Lead::ABIERTOS)
            ->orderByRaw('proximo_contacto_at is null, proximo_contacto_at')->orderBy('intentos')->orderBy('id')->limit(100)->get();
        $guion = $actual?->campana?->guion ?? $actual?->lead?->campana?->guion;

        return Inertia::render('ContactCenter/Consola', [
            'agente' => $this->cc->filaAgente($a) + ['estado_desde' => $a->estado_desde?->toIso8601String()],
            'pausas' => Agente::PAUSAS,
            'leads' => $leads->map(fn (Lead $l) => ['id' => $l->id, 'nombre' => $l->nombre, 'telefono' => $l->telefono, 'comuna' => $l->comuna, 'estado' => $l->estado,
                'intentos' => $l->intentos, 'max' => $l->campana->max_intentos, 'campana' => $l->campana->nombre, 'activa' => $l->campana->estado === 'activa' && $l->campana->enHorario(),
                'proximo' => $l->proximo_contacto_at?->toIso8601String(), 'notas' => $l->notas]),
            'llamada' => $actual ? $this->cc->filaLlamada($actual) + ['numero_completo' => $actual->numero, 'resultado' => $actual->resultado, 'notas' => $actual->notas,
                'lead' => $actual->lead ? ['id' => $actual->lead->id, 'nombre' => $actual->lead->nombre, 'rut' => $actual->lead->rut, 'email' => $actual->lead->email, 'comuna' => $actual->lead->comuna,
                    'cliente_id' => $actual->lead->cliente_id, 'notas' => $actual->lead->notas] : null] : null,
            'guion' => $guion ? ['nombre' => $guion->nombre, 'pasos' => $guion->pasos] : null,
            'resultados' => Llamada::RESULTADOS,
            'simulada' => $pbx->simulada(),
            'hoy' => ['llamadas' => Llamada::where('agente_id', $a->id)->where('inicio', '>=', today())->count(),
                'contactadas' => Llamada::where('agente_id', $a->id)->where('inicio', '>=', today())->whereNotNull('contestada_at')->count()],
        ]);
    }

    public function estado(Request $request)
    {
        $d = $request->validate(['estado' => ['required', 'in:disponible,pausa,desconectado'], 'motivo' => ['nullable', 'string']]);
        $this->cc->cambiarEstado($this->agente($request), $d['estado'], $d['motivo'] ?? null);

        return back();
    }

    public function llamar(Request $request, Lead $lead)
    {
        $this->cc->llamar($this->agente($request), $lead, $request->user());

        return back();
    }

    public function colgar(Request $request, Llamada $llamada)
    {
        $this->cc->colgar($llamada, $request->user());

        return back();
    }

    /** Solo con la central simulada: dispara el evento que mandaría la central real. */
    public function simular(Request $request, Llamada $llamada, Pbx $pbx)
    {
        abort_unless($pbx->simulada(), 404);
        abort_unless($llamada->agente?->user_id === $request->user()->id, 403);
        $d = $request->validate(['evento' => ['required', Rule::in(['contestada', 'no_contesta', 'ocupado'])]]);
        $this->cc->evento($llamada->pbx_id, $d['evento']);

        return back();
    }

    public function tipificar(Request $request, Llamada $llamada)
    {
        $d = $request->validate([
            'resultado' => ['required', Rule::in(array_keys(Llamada::RESULTADOS))],
            'notas' => ['nullable', 'string', 'max:1000'],
            'proximo_contacto' => ['nullable', 'date', 'after:now'],
        ]);
        $this->cc->tipificar($llamada, $d, $request->user());

        return back()->with('exito', 'Gestión registrada.');
    }
}
