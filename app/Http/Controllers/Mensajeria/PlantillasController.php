<?php

namespace App\Http\Controllers\Mensajeria;

use App\Http\Controllers\Controller;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Mensajeria\Models\Mensaje;
use App\Modules\Mensajeria\Models\Plantilla;
use App\Modules\Mensajeria\Plantillas;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** VentasCRM → Plantillas de WhatsApp: borrador, revisión de Meta y uso. */
class PlantillasController extends Controller
{
    public function __construct(private Plantillas $plantillas) {}

    public function index()
    {
        $usos = Mensaje::whereNotNull('plantilla_id')->selectRaw('plantilla_id, count(*) as n')->groupBy('plantilla_id')->pluck('n', 'plantilla_id');

        return Inertia::render('Mensajeria/Plantillas', [
            'activa' => Integracion::activa('whatsapp'),
            'plantillas' => Plantilla::orderByRaw("array_position(array['pendiente','rechazada','borrador','aprobada']::varchar[], estado)")->orderBy('nombre')->get()
                ->map(fn (Plantilla $p) => $p->only(['id', 'nombre', 'idioma', 'categoria', 'cuerpo', 'estado', 'motivo_rechazo']) + [
                    'variables' => $p->variables(), 'usos' => (int) ($usos[$p->id] ?? 0), 'revisada_at' => $p->revisada_at ? \Illuminate\Support\Carbon::parse($p->revisada_at)->toIso8601String() : null,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $p = $this->plantillas->guardar($this->validar($request), $request->user());

        return back()->with('exito', "Plantilla {$p->nombre} guardada en borrador.");
    }

    public function update(Request $request, Plantilla $plantilla)
    {
        $this->plantillas->guardar($this->validar($request, $plantilla), $request->user(), $plantilla);

        return back()->with('exito', 'Plantilla guardada en borrador. Envíala a revisión cuando esté lista.');
    }

    public function revision(Plantilla $plantilla)
    {
        $this->plantillas->enviarARevision($plantilla);

        return back()->with('exito', 'Enviada a revisión de Meta. El estado se actualiza solo cada 15 minutos.');
    }

    public function actualizar()
    {
        $n = $this->plantillas->actualizarPendientes();

        return back()->with('exito', $n ? "{$n} plantillas con respuesta de Meta." : 'Meta aún no responde las plantillas en revisión.');
    }

    public function destroy(Plantilla $plantilla)
    {
        $this->plantillas->eliminar($plantilla);

        return back()->with('exito', 'Plantilla eliminada.');
    }

    private function validar(Request $request, ?Plantilla $p = null): array
    {
        return $request->validate([
            'nombre' => ['required', 'regex:/^[a-z0-9_]{1,60}$/', Rule::unique('wa_plantillas', 'nombre')->where('empresa_id', app(\App\Modules\Core\Tenancy\Tenancy::class)->id())->where('idioma', $request->input('idioma'))->ignore($p?->id)],
            'idioma' => ['required', Rule::in(['es', 'es_CL', 'en'])],
            'categoria' => ['required', Rule::in(['utility', 'marketing'])],
            'cuerpo' => ['required', 'string', 'min:10', 'max:1024'],
        ], ['nombre.regex' => 'Usa minúsculas, números y guion bajo (así lo exige Meta).', 'nombre.unique' => 'Ya existe una plantilla con ese nombre e idioma.']);
    }
}
