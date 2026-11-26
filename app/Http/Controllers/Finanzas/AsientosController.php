<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Finanzas\Contabilidad;
use App\Modules\Finanzas\Models\Asiento;
use App\Modules\Finanzas\Models\CentroCosto;
use App\Modules\Finanzas\Models\CuentaContable;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Finanzas → Asientos Contables: libro diario, asientos manuales, reversos y plan de cuentas. */
class AsientosController extends Controller
{
    public function __construct(private Contabilidad $contabilidad) {}

    public function index(Request $request)
    {
        $this->contabilidad->asegurarPlan();
        $q = Asiento::with('creador:id,name')->withCount('lineas')->orderByDesc('fecha')->orderByDesc('numero');
        if ($t = $request->input('tipo')) {
            $q->where('tipo', $t);
        }
        if ($d = $request->input('desde')) {
            $q->where('fecha', '>=', $d);
        }
        if ($h = $request->input('hasta')) {
            $q->where('fecha', '<=', $h);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => ctype_digit($b) ? $q->where('numero', (int) $b) : $q->where('glosa', 'ilike', "%{$b}%"));
        }
        if ($c = $request->integer('cuenta_id')) {
            $q->whereHas('lineas', fn ($l) => $l->where('cuenta_id', $c));
        }

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.asientos-contables.exportar'), 403);
            $q->with('lineas.cuenta:id,codigo,nombre', 'lineas.centro:id,codigo');

            return Csv::descargar('libro-diario', ['N°', 'Fecha', 'Glosa', 'Tipo', 'Cuenta', 'Nombre cuenta', 'Centro', 'Debe', 'Haber'],
                $q->get()->flatMap(fn (Asiento $a) => $a->lineas->map(fn ($l) => [$a->numero, $a->fecha->toDateString(), $a->glosa, Asiento::TIPOS[$a->tipo], $l->cuenta?->codigo, $l->cuenta?->nombre, $l->centro?->codigo, $l->debe, $l->haber])));
        }

        return Inertia::render('Finanzas/Asientos/Index', [
            'asientos' => $q->paginate(30)->withQueryString()->through(fn (Asiento $a) => [
                'id' => $a->id, 'numero' => $a->numero, 'fecha' => $a->fecha->toDateString(), 'glosa' => $a->glosa, 'tipo' => $a->tipo, 'total' => $a->total,
                'lineas' => $a->lineas_count, 'creador' => $a->creador?->name, 'origen' => $this->origen($a),
            ]),
            'filtros' => $request->only('tipo', 'desde', 'hasta', 'buscar', 'cuenta_id'),
            'tipos' => Asiento::TIPOS,
            'cuentas' => CuentaContable::orderBy('codigo')->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function create()
    {
        $this->contabilidad->asegurarPlan();

        return Inertia::render('Finanzas/Asientos/Form', $this->opciones());
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'fecha' => ['required', 'date'],
            'glosa' => ['required', 'string', 'max:200'],
            'lineas' => ['required', 'array', 'min:2', 'max:100'],
            'lineas.*.cuenta_id' => ['required', 'integer'],
            'lineas.*.centro_costo_id' => ['nullable', 'integer'],
            'lineas.*.debe' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'lineas.*.haber' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'lineas.*.glosa' => ['nullable', 'string', 'max:200'],
        ], [], ['lineas' => 'líneas']);
        $a = $this->contabilidad->registrar($d['fecha'], $d['glosa'], $d['lineas'], $request->user());

        return to_route('finanzas.asientos.show', $a)->with('exito', "Asiento N° {$a->numero} contabilizado.");
    }

    public function show(Asiento $asiento)
    {
        $asiento->load('lineas.cuenta:id,codigo,nombre', 'lineas.centro:id,codigo,nombre', 'creador:id,name', 'revierte:id,numero', 'reverso:id,numero,revierte_id');

        return Inertia::render('Finanzas/Asientos/Show', [
            'asiento' => [
                'id' => $asiento->id, 'numero' => $asiento->numero, 'fecha' => $asiento->fecha->toDateString(), 'glosa' => $asiento->glosa, 'tipo' => $asiento->tipo,
                'total' => $asiento->total, 'creador' => $asiento->creador?->name, 'creado_at' => $asiento->created_at?->toIso8601String(), 'origen' => $this->origen($asiento),
                'revierte' => $asiento->revierte ? ['id' => $asiento->revierte->id, 'numero' => $asiento->revierte->numero] : null,
                'reverso' => $asiento->reverso ? ['id' => $asiento->reverso->id, 'numero' => $asiento->reverso->numero] : null,
                'lineas' => $asiento->lineas->map(fn ($l) => ['id' => $l->id, 'cuenta' => $l->cuenta?->codigo.' · '.$l->cuenta?->nombre, 'cuenta_id' => $l->cuenta_id,
                    'centro' => $l->centro ? "{$l->centro->codigo} · {$l->centro->nombre}" : null, 'debe' => $l->debe, 'haber' => $l->haber, 'glosa' => $l->glosa])->all(),
            ],
            'tipos' => Asiento::TIPOS,
        ]);
    }

    public function revertir(Request $request, Asiento $asiento)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:150']]);
        $r = $this->contabilidad->revertir($asiento, $request->user(), $d['motivo']);

        return to_route('finanzas.asientos.show', $r)->with('exito', "Reverso contabilizado como asiento N° {$r->numero}.");
    }

    /* Plan de cuentas */

    public function plan()
    {
        $this->contabilidad->asegurarPlan();

        return Inertia::render('Finanzas/PlanCuentas', [
            'cuentas' => CuentaContable::withCount('lineas')->orderBy('codigo')->get()->map(fn ($c) => ['id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre, 'tipo' => $c->tipo,
                'clave' => $c->clave, 'activa' => $c->activa, 'movimientos' => $c->lineas_count]),
            'tipos' => CuentaContable::TIPOS,
        ]);
    }

    public function guardarCuenta(Request $request, ?CuentaContable $cuenta = null)
    {
        $d = $request->validate([
            'codigo' => ['required', 'string', 'max:20', 'regex:/^[0-9.]+$/', Rule::unique('cuentas_contables')->where('empresa_id', app(Tenancy::class)->id())->ignore($cuenta?->id)],
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', Rule::in(array_keys(CuentaContable::TIPOS))],
            'activa' => ['boolean'],
        ], ['codigo.regex' => 'El código usa solo números y puntos (ej. 5.1.10).']);
        if ($cuenta) {
            // Con movimientos no cambia de tipo (alteraría balances pasados); las cuentas de asientos automáticos no se desactivan.
            if ($cuenta->tipo !== $d['tipo'] && $cuenta->lineas()->exists()) {
                return back()->withErrors(['tipo' => 'La cuenta ya tiene movimientos: no puede cambiar de tipo.']);
            }
            if ($cuenta->clave && ! ($d['activa'] ?? true)) {
                return back()->withErrors(['activa' => 'Esta cuenta la usan los asientos automáticos: no se puede desactivar.']);
            }
            $cuenta->update($d);
        } else {
            CuentaContable::create($d);
        }

        return back()->with('exito', $cuenta ? 'Cuenta actualizada.' : 'Cuenta creada.');
    }

    private function opciones(): array
    {
        return [
            'cuentas' => CuentaContable::where('activa', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'tipo']),
            'centros' => CentroCosto::where('activo', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
        ];
    }

    private function origen(Asiento $a): ?array
    {
        return match ($a->origen_tipo) {
            'documento' => ['texto' => 'Documento tributario', 'url' => "/caja/documentos/{$a->origen_id}"],
            'pago' => ['texto' => 'Cobro', 'url' => null],
            'factura_proveedor' => ['texto' => 'Factura de proveedor', 'url' => "/compras/facturas/{$a->origen_id}"],
            'pago_proveedor' => ['texto' => 'Pago a proveedor', 'url' => null],
            'honorario', 'honorario_pago' => ['texto' => 'Boleta de honorarios', 'url' => '/finanzas/honorarios'],
            default => null,
        };
    }
}
