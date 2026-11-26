<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\PagoProveedor;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Models\Pago;
use App\Modules\Finanzas\Conciliacion;
use App\Modules\Finanzas\Contabilidad;
use App\Modules\Finanzas\Models\Cartola;
use App\Modules\Finanzas\Models\CentroCosto;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\CuentaContable;
use App\Modules\Finanzas\Models\MovimientoBancario;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Finanzas → Conciliación Bancaria: cartolas, cruce automático y manual, contabilización de lo no registrado. */
class ConciliacionController extends Controller
{
    public function __construct(private Conciliacion $conciliacion) {}

    public function index(Request $request, Contabilidad $contabilidad)
    {
        $contabilidad->asegurarPlan();
        $cuentas = CuentaBancaria::orderBy('nombre')->get();
        $cuenta = $cuentas->firstWhere('id', $request->integer('cuenta')) ?? $cuentas->first();
        $estado = $request->input('estado', 'pendiente');
        $q = MovimientoBancario::with('pago.documento:id,tipo_dte,folio,receptor_razon_social', 'pagoProveedor.factura:id,tipo_dte,folio,proveedor_id', 'pagoProveedor.factura.proveedor:id,razon_social', 'asiento:id,numero')
            ->where('cuenta_bancaria_id', $cuenta?->id ?? 0)->orderByDesc('fecha')->orderByDesc('id');
        if (in_array($estado, array_keys(MovimientoBancario::ESTADOS), true)) {
            $q->where('estado', $estado);
        }
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->where(fn ($q) => $q->where('glosa', 'ilike', "%{$b}%")->orWhere('documento', 'ilike', "%{$b}%"));
        }

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.conciliacion-bancaria.exportar'), 403);

            return Csv::descargar('conciliacion', ['Fecha', 'Glosa', 'Documento', 'Monto', 'Estado', 'Conciliado con'], $q->get()->map(fn ($m) => [$m->fecha->toDateString(), $m->glosa, $m->documento, $m->monto, MovimientoBancario::ESTADOS[$m->estado], $this->contraparte($m)['texto'] ?? '']));
        }

        $resumen = $cuenta ? MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->groupBy('estado')->selectRaw('estado, count(*) as n, sum(monto) as monto')->get()->keyBy('estado') : collect();

        return Inertia::render('Finanzas/Conciliacion', [
            'cuentas' => $cuentas->map(fn ($c) => ['id' => $c->id, 'banco' => $c->banco, 'numero' => $c->numero, 'nombre' => $c->nombre, 'activa' => $c->activa]),
            'cuenta' => $cuenta ? ['id' => $cuenta->id, 'nombre' => $cuenta->nombre, 'banco' => $cuenta->banco, 'numero' => $cuenta->numero,
                'saldo_cartola' => (int) MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->sum('monto'),
                'saldo_contable' => (int) ($contabilidad->saldos(null, now()->toDateString())->firstWhere('cuenta_id', $cuenta->cuenta_contable_id)?->saldo ?? 0)] : null,
            'resumen' => collect(MovimientoBancario::ESTADOS)->map(fn ($n, $k) => ['clave' => $k, 'nombre' => $n, 'cantidad' => (int) ($resumen[$k]->n ?? 0), 'monto' => (int) ($resumen[$k]->monto ?? 0)])->values(),
            'movimientos' => $q->paginate(50)->withQueryString()->through(fn (MovimientoBancario $m) => [
                'id' => $m->id, 'fecha' => $m->fecha->toDateString(), 'glosa' => $m->glosa, 'documento' => $m->documento, 'monto' => $m->monto, 'estado' => $m->estado,
                'forma' => $m->forma, 'contraparte' => $this->contraparte($m),
            ]),
            'cartolas' => $cuenta ? Cartola::with('importador:id,name')->where('cuenta_bancaria_id', $cuenta->id)->latest('id')->limit(5)->get()->map(fn ($c) => [
                'id' => $c->id, 'archivo' => $c->archivo, 'desde' => $c->desde?->toDateString(), 'hasta' => $c->hasta?->toDateString(), 'movimientos' => $c->movimientos,
                'duplicados' => $c->duplicados, 'conciliados' => $c->conciliados, 'importador' => $c->importador?->name, 'fecha' => $c->created_at->toIso8601String()]) : [],
            'filtros' => ['estado' => $estado, 'buscar' => $request->input('buscar')],
            'cuentasContables' => CuentaContable::where('activa', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
            'centros' => CentroCosto::where('activo', true)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
        ]);
    }

    public function guardarCuenta(Request $request, Contabilidad $contabilidad)
    {
        $d = $request->validate([
            'banco' => ['required', 'string', 'max:60'],
            'numero' => ['required', 'string', 'max:30', Rule::unique('cuentas_bancarias')->where('empresa_id', app(Tenancy::class)->id())->where('banco', $request->input('banco'))],
            'nombre' => ['required', 'string', 'max:100'],
        ]);
        $c = CuentaBancaria::create($d + ['cuenta_contable_id' => $contabilidad->cuenta('banco')->id]);

        return to_route('finanzas.conciliacion', ['cuenta' => $c->id])->with('exito', 'Cuenta bancaria creada. Importa su cartola para conciliar.');
    }

    public function importar(Request $request, CuentaBancaria $cuenta)
    {
        $request->validate(['archivo' => ['required', 'file', 'max:5120', 'mimes:csv,txt']], ['archivo.mimes' => 'Sube la cartola en CSV (exportada desde el banco).']);
        $f = $request->file('archivo');
        $c = $this->conciliacion->importar($cuenta, (string) file_get_contents($f->getRealPath()), $f->getClientOriginalName(), $request->user());

        return back()->with('exito', "Cartola importada: {$c->movimientos} movimientos nuevos".($c->duplicados ? ", {$c->duplicados} ya importados" : '').", {$c->conciliados} conciliados automáticamente.");
    }

    public function automatico(Request $request, CuentaBancaria $cuenta)
    {
        $n = $this->conciliacion->conciliarAutomatico($cuenta, $request->user());

        return back()->with('exito', $n ? "{$n} movimientos conciliados." : 'No hay movimientos nuevos que calcen exactamente.');
    }

    /** Cobros o pagos candidatos para un movimiento (búsqueda amplia: ±60 días). */
    public function candidatos(MovimientoBancario $movimiento)
    {
        return $this->conciliacion->candidatos($movimiento, true)->map(fn ($x) => $x instanceof Pago
            ? ['tipo' => 'pago', 'id' => $x->id, 'fecha' => $x->fecha->toDateString(), 'monto' => $x->monto, 'texto' => 'Cobro '.$x->documento?->nombre().' · '.($x->documento?->receptor_razon_social ?? ''), 'referencia' => $x->referencia]
            : ['tipo' => 'pago_proveedor', 'id' => $x->id, 'fecha' => $x->fecha->toDateString(), 'monto' => $x->monto, 'texto' => 'Pago '.$x->factura?->nombre().' · '.$x->factura?->proveedor?->razon_social, 'referencia' => $x->referencia])->values();
    }

    public function conciliar(Request $request, MovimientoBancario $movimiento)
    {
        $d = $request->validate(['tipo' => ['required', Rule::in(['pago', 'pago_proveedor'])], 'id' => ['required', 'integer']]);
        $this->conciliacion->conciliar($movimiento, $d['tipo'], $d['id'], $request->user());

        return back()->with('exito', 'Movimiento conciliado.');
    }

    public function contabilizar(Request $request, MovimientoBancario $movimiento)
    {
        $d = $request->validate(['cuenta_id' => ['required', 'integer'], 'glosa' => ['nullable', 'string', 'max:200'], 'centro_costo_id' => ['nullable', 'integer', 'exists:centros_costo,id']]);
        $this->conciliacion->contabilizar($movimiento, $d['cuenta_id'], (string) ($d['glosa'] ?? ''), $request->user(), $d['centro_costo_id'] ?? null);

        return back()->with('exito', 'Movimiento contabilizado y conciliado.');
    }

    public function ignorar(Request $request, MovimientoBancario $movimiento)
    {
        $this->conciliacion->ignorar($movimiento, $request->user());

        return back()->with('exito', 'Movimiento marcado como ignorado.');
    }

    public function deshacer(Request $request, MovimientoBancario $movimiento)
    {
        $this->conciliacion->deshacer($movimiento, $request->user());

        return back()->with('exito', 'Conciliación deshecha: el movimiento vuelve a pendiente.');
    }

    private function contraparte(MovimientoBancario $m): ?array
    {
        return match (true) {
            (bool) $m->pago => ['texto' => 'Cobro '.$m->pago->documento?->nombre().' · '.($m->pago->documento?->receptor_razon_social ?? ''), 'url' => $m->pago->documento ? "/caja/documentos/{$m->pago->documento->id}" : null],
            (bool) $m->pagoProveedor => ['texto' => 'Pago '.$m->pagoProveedor->factura?->nombre().' · '.$m->pagoProveedor->factura?->proveedor?->razon_social, 'url' => $m->pagoProveedor->factura ? "/compras/facturas/{$m->pagoProveedor->factura->id}" : null],
            (bool) $m->asiento => ['texto' => "Asiento N° {$m->asiento->numero}", 'url' => "/finanzas/asientos/{$m->asiento->id}"],
            default => null,
        };
    }
}
