<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Local;
use App\Modules\Finanzas\Models\CentroCosto;
use App\Modules\RRHH\Ausencias;
use App\Modules\RRHH\Models\Ausencia;
use App\Modules\RRHH\Models\Contrato;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\EmpleadoDocumento;
use App\Modules\RRHH\Models\Prestamo;
use App\Modules\RRHH\Models\Turno;
use App\Modules\RRHH\Personal;
use App\Support\Exportacion\Csv;
use App\Support\Rut;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** RRHH → Ficha de Personal: expediente con historial de contratos y documentos cifrados. */
class PersonalController extends Controller
{
    public function __construct(private Personal $personal) {}

    public function index(Request $request)
    {
        $q = Empleado::with('local:id,nombre', 'contratoVigente:id,empleado_id,tipo,sueldo_base,fecha_termino')->orderBy('apellidos')->orderBy('nombres');
        $estado = $request->input('estado', 'activo');
        if (array_key_exists($estado, Empleado::ESTADOS)) {
            $q->where('estado', $estado);
        }
        if ($l = $request->integer('local_id')) {
            $q->where('local_id', $l);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $rut = Rut::normalizar($b);
            $q->where(fn ($q) => $q->whereRaw("nombres || ' ' || apellidos ilike ?", ["%{$b}%"])->orWhere('cargo', 'ilike', "%{$b}%")
                ->when($rut && preg_match('/\d/', $rut), fn ($q) => $q->orWhere('rut', 'ilike', "%{$rut}%")));
        }
        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('rrhh.ficha-de-personal.exportar'), 403);

            return Csv::descargar('personal', ['RUT', 'Nombre', 'Cargo', 'Local', 'Ingreso', 'AFP', 'Salud', 'Estado'], $q->get()->map(fn ($e) => [
                $e->rut, $e->nombreCompleto(), $e->cargo, $e->local?->nombre, $e->fecha_ingreso->toDateString(), Empleado::AFPS[$e->afp] ?? $e->afp, $e->salud, Empleado::ESTADOS[$e->estado]]));
        }

        return Inertia::render('RRHH/Personal/Index', [
            'empleados' => $q->paginate(30)->withQueryString()->through(fn (Empleado $e) => [
                'id' => $e->id, 'rut' => $e->rut, 'nombre' => $e->nombreCompleto(), 'cargo' => $e->cargo, 'local' => $e->local?->nombre,
                'ingreso' => $e->fecha_ingreso->toDateString(), 'estado' => $e->estado,
                'contrato' => $e->contratoVigente ? ['tipo' => Contrato::TIPOS[$e->contratoVigente->tipo], 'termino' => $e->contratoVigente->fecha_termino?->toDateString()] : null,
            ]),
            'filtros' => ['estado' => $estado, 'local_id' => $request->input('local_id'), 'buscar' => $request->input('buscar')],
            'locales' => Local::orderBy('nombre')->get(['id', 'nombre']),
            'resumen' => ['activos' => Empleado::where('estado', 'activo')->count(), 'sin_contrato' => Empleado::where('estado', 'activo')->whereDoesntHave('contratoVigente')->count()],
        ]);
    }

    public function create()
    {
        return Inertia::render('RRHH/Personal/Form', $this->opciones() + ['empleado' => null]);
    }

    public function store(Request $request)
    {
        $e = $this->personal->guardar($this->validar($request));

        return to_route('rrhh.personal.show', $e)->with('exito', 'Ficha creada. Ahora redacta su contrato.');
    }

    public function edit(Empleado $empleado)
    {
        return Inertia::render('RRHH/Personal/Form', $this->opciones() + ['empleado' => $empleado->only(['id', 'rut', 'nombres', 'apellidos', 'email', 'telefono', 'direccion', 'comuna', 'nacionalidad',
            'local_id', 'centro_costo_id', 'turno_id', 'user_id', 'cargo', 'anios_previos', 'afp', 'salud', 'banco', 'cuenta_banco']) + [
            'fecha_nacimiento' => $empleado->fecha_nacimiento?->toDateString(), 'fecha_ingreso' => $empleado->fecha_ingreso->toDateString(), 'plan_isapre_uf' => (float) $empleado->plan_isapre_uf]]);
    }

    public function update(Request $request, Empleado $empleado)
    {
        $this->personal->guardar($this->validar($request), $empleado);

        return to_route('rrhh.personal.show', $empleado)->with('exito', 'Ficha actualizada.');
    }

    public function show(Empleado $empleado, Ausencias $ausencias)
    {
        $empleado->load('local:id,nombre', 'centro:id,codigo,nombre', 'turno:id,nombre,hora_inicio,hora_fin', 'usuario:id,name,email', 'contratos', 'documentos.subidor:id,name');

        return Inertia::render('RRHH/Personal/Show', [
            'empleado' => [
                'id' => $empleado->id, 'rut' => $empleado->rut, 'nombre' => $empleado->nombreCompleto(), 'cargo' => $empleado->cargo, 'estado' => $empleado->estado,
                'email' => $empleado->email, 'telefono' => $empleado->telefono, 'direccion' => trim(($empleado->direccion ?? '').', '.($empleado->comuna ?? ''), ', '),
                'nacimiento' => $empleado->fecha_nacimiento?->toDateString(), 'ingreso' => $empleado->fecha_ingreso->toDateString(), 'egreso' => $empleado->fecha_egreso?->toDateString(),
                'afp' => Empleado::AFPS[$empleado->afp] ?? $empleado->afp, 'salud' => $empleado->salud === 'fonasa' ? 'Fonasa' : ucfirst($empleado->salud).($empleado->plan_isapre_uf > 0 ? ' · plan '.rtrim(rtrim((string) $empleado->plan_isapre_uf, '0'), '.').' UF' : ''),
                'local' => $empleado->local?->nombre, 'centro' => $empleado->centro ? "{$empleado->centro->codigo} · {$empleado->centro->nombre}" : null,
                'turno' => $empleado->turno ? "{$empleado->turno->nombre} (".substr($empleado->turno->hora_inicio, 0, 5).'–'.substr($empleado->turno->hora_fin, 0, 5).')' : null,
                'usuario' => $empleado->usuario?->name, 'banco' => trim(($empleado->banco ?? '').' '.($empleado->cuenta_banco ?? '')) ?: null,
            ],
            'contratos' => $empleado->contratos->map(fn (Contrato $c) => ['id' => $c->id, 'tipo' => Contrato::TIPOS[$c->tipo], 'cargo' => $c->cargo, 'sueldo' => $c->sueldo_base,
                'inicio' => $c->fecha_inicio->toDateString(), 'termino' => $c->fecha_termino?->toDateString(), 'estado' => $c->estado]),
            'documentos' => $empleado->documentos->map(fn ($d) => ['id' => $d->id, 'tipo' => EmpleadoDocumento::TIPOS[$d->tipo], 'nombre' => $d->nombre, 'bytes' => $d->bytes,
                'subidor' => $d->subidor?->name, 'fecha' => $d->created_at->toIso8601String()]),
            'vacaciones' => $ausencias->saldoVacaciones($empleado),
            'ausencias' => Ausencia::where('empleado_id', $empleado->id)->latest('desde')->limit(8)->get()->map(fn ($a) => ['id' => $a->id, 'tipo' => Ausencia::TIPOS[$a->tipo],
                'desde' => $a->desde->toDateString(), 'hasta' => $a->hasta->toDateString(), 'dias' => $a->dias_habiles, 'estado' => $a->estado]),
            'prestamos' => Prestamo::where('empleado_id', $empleado->id)->whereIn('estado', ['por_aprobar', 'vigente'])->get(['id', 'monto', 'saldo', 'valor_cuota', 'estado']),
            'tiposDocumento' => EmpleadoDocumento::TIPOS,
        ]);
    }

    public function adjuntar(Request $request, Empleado $empleado)
    {
        $d = $request->validate(['archivo' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'], 'tipo' => ['required', Rule::in(array_keys(EmpleadoDocumento::TIPOS))]],
            ['archivo.mimes' => 'Sube un PDF, imagen o documento de Word.']);
        $this->personal->adjuntar($empleado, $d['archivo'], $d['tipo'], $request->user());

        return back()->with('exito', 'Documento guardado (cifrado).');
    }

    public function documento(EmpleadoDocumento $documento)
    {
        return response($this->personal->contenido($documento), 200, [
            'Content-Type' => $documento->mime,
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $documento->nombre).'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function opciones(): array
    {
        return [
            'locales' => Local::orderBy('nombre')->get(['id', 'nombre']),
            'centros' => CentroCosto::where('activo', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
            'turnos' => Turno::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'usuarios' => User::whereHas('membresias', fn ($q) => $q->where('empresa_id', app(\App\Modules\Core\Tenancy\Tenancy::class)->id()))->orderBy('name')->get(['id', 'name', 'email']),
            'afps' => Empleado::AFPS,
        ];
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'rut' => ['required', 'string', 'max:12'],
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            'fecha_nacimiento' => ['nullable', 'date', 'before:-15 years'],
            'email' => ['nullable', 'email', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'direccion' => ['nullable', 'string', 'max:150'],
            'comuna' => ['nullable', 'string', 'max:60'],
            'nacionalidad' => ['required', 'string', 'max:40'],
            'local_id' => ['nullable', 'integer', 'exists:locales,id'],
            'centro_costo_id' => ['nullable', 'integer', 'exists:centros_costo,id'],
            'turno_id' => ['nullable', 'integer', 'exists:turnos,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'cargo' => ['required', 'string', 'max:80'],
            'fecha_ingreso' => ['required', 'date'],
            'anios_previos' => ['required', 'integer', 'min:0', 'max:50'],
            'afp' => ['required', Rule::in(array_keys(Empleado::AFPS))],
            'salud' => ['required', 'string', 'max:30'],
            'plan_isapre_uf' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'banco' => ['nullable', 'string', 'max:60'],
            'cuenta_banco' => ['nullable', 'string', 'max:30'],
        ], ['fecha_nacimiento.before' => 'La persona debe tener al menos 15 años.']);
    }
}
