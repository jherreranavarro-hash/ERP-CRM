<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\RRHH\Contratos;
use App\Modules\RRHH\Models\Contrato;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\PlantillaContrato;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** RRHH → Gestión de Contratos: plantillas, firma electrónica y alertas de vencimiento. */
class ContratosController extends Controller
{
    public function __construct(private Contratos $contratos) {}

    public function index(Request $request)
    {
        $q = Contrato::with('empleado:id,nombres,apellidos,rut')->orderByDesc('id');
        if ($e = $request->input('estado')) {
            $q->where('estado', $e);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => $q->whereHas('empleado', fn ($q) => $q->whereRaw("nombres || ' ' || apellidos ilike ?", ["%{$b}%"]))->orWhere('cargo', 'ilike', "%{$b}%"));
        }
        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('rrhh.gestion-de-contratos.exportar'), 403);

            return Csv::descargar('contratos', ['Persona', 'RUT', 'Tipo', 'Cargo', 'Jornada', 'Sueldo base', 'Inicio', 'Término', 'Estado'], $q->get()->map(fn ($c) => [
                $c->empleado->nombreCompleto(), $c->empleado->rut, Contrato::TIPOS[$c->tipo], $c->cargo, $c->jornada_horas, $c->sueldo_base, $c->fecha_inicio->toDateString(), $c->fecha_termino?->toDateString(), Contrato::ESTADOS[$c->estado]]));
        }

        return Inertia::render('RRHH/Contratos/Index', [
            'contratos' => $q->paginate(30)->withQueryString()->through(fn (Contrato $c) => $this->fila($c)),
            'porVencer' => $this->contratos->porVencer()->map(fn ($c) => $this->fila($c)),
            'filtros' => $request->only('estado', 'buscar'),
            'estados' => Contrato::ESTADOS,
            'diasAviso' => (int) app(Parametros::class)->valor('rrhh.dias_aviso_contrato'),
        ]);
    }

    public function create(Request $request)
    {
        $e = Empleado::with('contratoVigente')->findOrFail($request->integer('empleado'));
        $v = $e->contratoVigente;

        return Inertia::render('RRHH/Contratos/Form', $this->opciones() + [
            'empleado' => ['id' => $e->id, 'nombre' => $e->nombreCompleto(), 'cargo' => $e->cargo],
            'contrato' => null,
            'base' => $v ? $v->only(['tipo', 'cargo', 'jornada_horas', 'sueldo_base', 'gratificacion', 'colacion', 'movilizacion']) + ['comision_pct' => (float) $v->comision_pct] : null,
        ]);
    }

    public function store(Request $request)
    {
        $e = Empleado::findOrFail($request->integer('empleado_id'));
        $c = $this->contratos->guardar($e, $this->validar($request), $request->user());

        return to_route('rrhh.contratos.show', $c)->with('exito', 'Contrato redactado. Revísalo y envíalo a firma.');
    }

    public function edit(Contrato $contrato)
    {
        abort_unless($contrato->estado === 'borrador', 404);
        $contrato->load('empleado');

        return Inertia::render('RRHH/Contratos/Form', $this->opciones() + [
            'empleado' => ['id' => $contrato->empleado->id, 'nombre' => $contrato->empleado->nombreCompleto(), 'cargo' => $contrato->empleado->cargo],
            'contrato' => $contrato->only(['id', 'tipo', 'cargo', 'jornada_horas', 'sueldo_base', 'gratificacion', 'colacion', 'movilizacion', 'plantilla_id', 'texto'])
                + ['comision_pct' => (float) $contrato->comision_pct, 'fecha_inicio' => $contrato->fecha_inicio->toDateString(), 'fecha_termino' => $contrato->fecha_termino?->toDateString()],
            'base' => null,
        ]);
    }

    public function update(Request $request, Contrato $contrato)
    {
        $contrato->load('empleado');
        $this->contratos->guardar($contrato->empleado, $this->validar($request), $request->user(), $contrato);

        return to_route('rrhh.contratos.show', $contrato)->with('exito', 'Contrato actualizado.');
    }

    public function show(Contrato $contrato)
    {
        $contrato->load('empleado:id,nombres,apellidos,rut', 'plantilla:id,nombre');

        return Inertia::render('RRHH/Contratos/Show', ['contrato' => $this->fila($contrato) + [
            'jornada' => $contrato->jornada_horas, 'gratificacion' => $contrato->gratificacion, 'colacion' => $contrato->colacion, 'movilizacion' => $contrato->movilizacion,
            'comision' => (float) $contrato->comision_pct, 'texto' => $contrato->texto, 'plantilla' => $contrato->plantilla?->nombre, 'firma_id' => $contrato->firma_id,
            'firmado_at' => $contrato->firmado_at?->toIso8601String(),
        ]]);
    }

    public function enviar(Request $request, Contrato $contrato)
    {
        $this->contratos->enviarAFirma($contrato, $request->user());

        return back()->with('exito', 'Enviado a firma electrónica. Consulta su estado para activarlo.');
    }

    public function sincronizar(Request $request, Contrato $contrato)
    {
        $c = $this->contratos->sincronizarFirma($contrato, $request->user());

        return back()->with('exito', $c->estado === 'vigente' ? 'Contrato firmado: quedó vigente.' : 'La firma sigue pendiente.');
    }

    public function plantillas()
    {
        return Inertia::render('RRHH/Contratos/Plantillas', [
            'plantillas' => PlantillaContrato::orderBy('nombre')->get(['id', 'nombre', 'cuerpo', 'activa']),
            'variables' => PlantillaContrato::VARIABLES,
        ]);
    }

    public function guardarPlantilla(Request $request, ?PlantillaContrato $plantilla = null)
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:80', Rule::unique('plantillas_contrato')->where('empresa_id', app(\App\Modules\Core\Tenancy\Tenancy::class)->id())->ignore($plantilla?->id)],
            'cuerpo' => ['required', 'string', 'max:30000'],
            'activa' => ['boolean'],
        ]);
        $plantilla ? $plantilla->update($d) : PlantillaContrato::create($d);

        return back()->with('exito', 'Plantilla guardada.');
    }

    private function fila(Contrato $c): array
    {
        return ['id' => $c->id, 'empleado_id' => $c->empleado_id, 'empleado' => $c->empleado?->nombreCompleto(), 'rut' => $c->empleado?->rut, 'tipo' => $c->tipo,
            'cargo' => $c->cargo, 'sueldo' => $c->sueldo_base, 'inicio' => $c->fecha_inicio->toDateString(), 'termino' => $c->fecha_termino?->toDateString(), 'estado' => $c->estado];
    }

    private function opciones(): array
    {
        return [
            'plantillas' => PlantillaContrato::where('activa', true)->orderBy('nombre')->get(['id', 'nombre']),
            'tipos' => Contrato::TIPOS,
            'jornadaMaxima' => (int) app(Parametros::class)->valor('nomina.jornada_maxima'),
            'imm' => (int) app(Parametros::class)->valor('nomina.imm'),
        ];
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Contrato::TIPOS))],
            'cargo' => ['required', 'string', 'max:80'],
            'jornada_horas' => ['required', 'integer', 'min:1', 'max:45'],
            'sueldo_base' => ['required', 'integer', 'min:1', 'max:100000000'],
            'gratificacion' => ['boolean'],
            'colacion' => ['required', 'integer', 'min:0', 'max:10000000'],
            'movilizacion' => ['required', 'integer', 'min:0', 'max:10000000'],
            'comision_pct' => ['required', 'numeric', 'min:0', 'max:30'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_termino' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'plantilla_id' => ['nullable', 'integer'],
            'texto' => ['nullable', 'string', 'max:30000'],
        ]);
    }
}
