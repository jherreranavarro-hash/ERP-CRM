<?php

namespace App\Http\Controllers\ContactCenter;

use App\Http\Controllers\Controller;
use App\Modules\ContactCenter\Calidad;
use App\Modules\ContactCenter\CentroContacto;
use App\Modules\ContactCenter\Models\Agente;
use App\Modules\ContactCenter\Models\AuditoriaCalidad;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\CriterioCalidad;
use App\Modules\ContactCenter\Models\GrabacionAcceso;
use App\Modules\ContactCenter\Models\Llamada;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

/** VentasCRM → Auditoría (calidad con checklist) y Grabaciones (acceso con permiso, motivo y registro). */
class CalidadController extends Controller
{
    public function __construct(private Calidad $calidad) {}

    private function llamadas(Request $request)
    {
        $q = Llamada::with('agente.user:id,name', 'campana:id,nombre', 'lead:id,nombre', 'auditoria')->whereNotNull('contestada_at')->whereNotIn('estado', Llamada::ACTIVAS)->orderByDesc('inicio');
        foreach (['agente' => 'agente_id', 'campana' => 'campana_id'] as $f => $col) {
            if ($v = $request->integer($f)) {
                $q->where($col, $v);
            }
        }
        if ($d = $request->input('desde')) {
            $q->where('inicio', '>=', $d);
        }
        if ($h = $request->input('hasta')) {
            $q->where('inicio', '<', \Carbon\CarbonImmutable::parse($h)->addDay());
        }

        return $q;
    }

    private function fila(Llamada $l): array
    {
        return ['id' => $l->id, 'inicio' => $l->inicio->toIso8601String(), 'agente' => $l->agente?->nombre(), 'agente_user_id' => $l->agente?->user_id, 'campana' => $l->campana?->nombre,
            'contacto' => $l->lead?->nombre, 'numero' => CentroContacto::enmascarar($l->numero), 'direccion' => $l->direccion, 'duracion' => $l->duracion_seg, 'resultado' => $l->resultado,
            'grabacion' => (bool) $l->grabacion_ref, 'puntaje' => $l->auditoria?->puntaje, 'auditoria_id' => $l->auditoria?->id];
    }

    private function filtros(): array
    {
        return ['agentes' => Agente::with('user:id,name')->orderBy('extension')->get()->map(fn ($a) => ['id' => $a->id, 'nombre' => $a->nombre()]),
            'campanas' => Campana::orderBy('nombre')->get(['id', 'nombre']), 'resultados' => Llamada::RESULTADOS];
    }

    public function auditoria(Request $request)
    {
        $promedios = AuditoriaCalidad::query()->groupBy('agente_id')->selectRaw('agente_id, avg(puntaje) as promedio, count(*) as n')->get()->keyBy('agente_id');

        return Inertia::render('ContactCenter/Auditoria', $this->filtros() + [
            'llamadas' => $this->llamadas($request)->paginate(30)->withQueryString()->through(fn ($l) => $this->fila($l)),
            'criterios' => CriterioCalidad::orderBy('orden')->orderBy('id')->get(['id', 'nombre', 'peso', 'critico', 'activo', 'orden']),
            'promedios' => Agente::with('user:id,name')->whereIn('id', $promedios->keys())->get()->map(fn ($a) => ['agente' => $a->nombre(), 'promedio' => round((float) $promedios[$a->id]->promedio, 1),
                'evaluaciones' => (int) $promedios[$a->id]->n])->sortByDesc('promedio')->values(),
            'filtrosActivos' => $request->only('agente', 'campana', 'desde', 'hasta'),
        ]);
    }

    public function verAuditoria(AuditoriaCalidad $auditoria)
    {
        $auditoria->load('auditor:id,name');

        return ['checklist' => $auditoria->checklist, 'puntaje' => $auditoria->puntaje, 'comentario' => $auditoria->comentario, 'auditor' => $auditoria->auditor?->name,
            'fecha' => $auditoria->created_at->toIso8601String()];
    }

    public function evaluar(Request $request, Llamada $llamada)
    {
        $d = $request->validate(['cumple' => ['present', 'array'], 'cumple.*' => ['boolean'], 'comentario' => ['nullable', 'string', 'max:1000']]);
        $a = $this->calidad->evaluar($llamada, $d['cumple'], $d['comentario'] ?? null, $request->user());

        return back()->with('exito', "Evaluación registrada: {$a->puntaje} puntos.");
    }

    public function guardarCriterio(Request $request, ?CriterioCalidad $criterio = null)
    {
        $d = $request->validate(['nombre' => ['required', 'string', 'max:120'], 'peso' => ['required', 'integer', 'min:1', 'max:100'], 'critico' => ['boolean'], 'activo' => ['boolean'], 'orden' => ['integer', 'min:0', 'max:999']]);
        $criterio ? $criterio->update($d) : CriterioCalidad::create($d);

        return back()->with('exito', 'Criterio guardado: aplica a las próximas evaluaciones.');
    }

    public function grabaciones(Request $request)
    {
        $q = $this->llamadas($request)->whereNotNull('grabacion_ref');
        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('ventascrm.grabaciones.exportar'), 403);

            return Csv::descargar('accesos-grabaciones', ['Fecha', 'Usuario', 'Llamada', 'Acción', 'Motivo', 'IP'], GrabacionAcceso::with('user:id,name')->latest('id')->limit(5000)->get()
                ->map(fn ($g) => [$g->created_at->toDateTimeString(), $g->user?->name, $g->llamada_id, $g->accion, $g->motivo, $g->ip]));
        }

        return Inertia::render('ContactCenter/Grabaciones', $this->filtros() + [
            'llamadas' => $q->paginate(30)->withQueryString()->through(fn ($l) => $this->fila($l)),
            'accesos' => GrabacionAcceso::with('user:id,name')->latest('id')->limit(30)->get()->map(fn ($g) => ['id' => $g->id, 'fecha' => $g->created_at->toIso8601String(), 'usuario' => $g->user?->name,
                'llamada_id' => $g->llamada_id, 'accion' => $g->accion, 'motivo' => $g->motivo]),
            'puedeEscuchar' => $request->user()->tienePermiso('ventascrm.grabaciones.escuchar'),
            'filtrosActivos' => $request->only('agente', 'campana', 'desde', 'hasta'),
        ]);
    }

    /** Registra el acceso con su motivo y entrega un enlace de un solo uso, válido 5 minutos, para el audio. */
    public function solicitarGrabacion(Request $request, Llamada $llamada)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:200'], 'accion' => ['required', 'in:escuchar,descargar']]);
        $audio = $this->calidad->grabacion($llamada, $request->user(), $d['motivo'], $d['accion'], $request->ip());
        $token = Str::random(40);
        Cache::put("grabacion:{$token}", ['user' => $request->user()->id, 'llamada' => $llamada->id, 'accion' => $d['accion'], 'audio' => base64_encode($audio)], now()->addMinutes(5));

        return response()->json(['url' => route('ventas.grabaciones.audio', ['llamada' => $llamada->id, 'token' => $token])]);
    }

    public function audio(Request $request, Llamada $llamada)
    {
        $t = Cache::pull('grabacion:'.$request->string('token'));
        abort_unless($t && $t['user'] === $request->user()->id && $t['llamada'] === $llamada->id, 403);

        return response(base64_decode($t['audio']), 200, ['Content-Type' => 'audio/wav', 'Cache-Control' => 'no-store',
            'Content-Disposition' => ($t['accion'] === 'descargar' ? 'attachment' : 'inline').'; filename="llamada-'.$llamada->id.'.wav"']);
    }
}
