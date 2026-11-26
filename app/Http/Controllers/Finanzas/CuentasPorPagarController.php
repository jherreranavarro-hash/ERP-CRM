<?php

namespace App\Http\Controllers\Finanzas;

use App\Http\Controllers\Controller;
use App\Modules\Compras\Models\FacturaProveedor;
use App\Modules\Compras\Models\PagoProveedor;
use App\Modules\Compras\PagosProveedor;
use App\Support\Exportacion\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Finanzas → Cuentas por Pagar: facturas de proveedor por pagar, antigüedad, calendario de pagos y aprobación de pagos. */
class CuentasPorPagarController extends Controller
{
    private const TRAMOS = ['al_dia' => 'Por vencer', '1_30' => 'Vencido 1–30', '31_60' => 'Vencido 31–60', '61_90' => 'Vencido 61–90', 'mas_90' => 'Vencido +90'];

    public function __construct(private PagosProveedor $pagos) {}

    public function index(Request $request)
    {
        $q = FacturaProveedor::with('proveedor:id,razon_social,rut')->where('estado', 'por_pagar')->where('saldo', '>', 0)->orderBy('fecha_vencimiento');
        if ($b = $request->string('buscar')->trim()->value()) {
            $q->whereHas('proveedor', fn ($p) => $p->where('razon_social', 'ilike', "%{$b}%"));
        }
        $todas = (clone $q)->get();
        $tramos = array_fill_keys(array_keys(self::TRAMOS), 0);
        foreach ($todas as $f) {
            $tramos[$this->tramo($f)] += $f->saldo;
        }

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('finanzas.cuentas-por-pagar.exportar'), 403);

            return Csv::descargar('cuentas-por-pagar', ['Documento', 'Proveedor', 'RUT', 'Emisión', 'Vencimiento', 'Total', 'Saldo', 'Tramo'],
                $todas->map(fn (FacturaProveedor $f) => [$f->nombre(), $f->proveedor?->razon_social, $f->proveedor?->rut, $f->fecha_emision->toDateString(), $f->fecha_vencimiento->toDateString(), $f->monto_total, $f->saldo, self::TRAMOS[$this->tramo($f)]]));
        }

        // Calendario: lo vencido y las próximas 8 semanas (lunes a domingo).
        $lunes = CarbonImmutable::today()->startOfWeek();
        $semanas = [['desde' => null, 'hasta' => $lunes->subDay()->toDateString(), 'nombre' => 'Vencido', 'monto' => 0, 'cantidad' => 0]];
        for ($i = 0; $i < 8; $i++) {
            $semanas[] = ['desde' => $lunes->addWeeks($i)->toDateString(), 'hasta' => $lunes->addWeeks($i)->endOfWeek()->toDateString(), 'nombre' => null, 'monto' => 0, 'cantidad' => 0];
        }
        foreach ($todas as $f) {
            $v = $f->fecha_vencimiento->toDateString();
            foreach ($semanas as $k => $s) {
                if (($s['desde'] === null || $v >= $s['desde']) && $v <= $s['hasta']) {
                    $semanas[$k]['monto'] += $f->saldo;
                    $semanas[$k]['cantidad']++;
                    break;
                }
            }
        }

        return Inertia::render('Finanzas/CuentasPorPagar', [
            'tramos' => collect(self::TRAMOS)->map(fn ($n, $k) => ['clave' => $k, 'nombre' => $n, 'monto' => $tramos[$k]])->values(),
            'total' => array_sum($tramos),
            'semanas' => $semanas,
            'facturas' => $q->paginate(25)->withQueryString()->through(fn (FacturaProveedor $f) => [
                'id' => $f->id, 'nombre' => $f->nombre(), 'proveedor' => $f->proveedor?->razon_social, 'emision' => $f->fecha_emision->toDateString(),
                'vencimiento' => $f->fecha_vencimiento->toDateString(), 'total' => $f->monto_total, 'saldo' => $f->saldo, 'tramo' => self::TRAMOS[$this->tramo($f)], 'vencido' => $this->tramo($f) !== 'al_dia',
            ]),
            'pagosPorAprobar' => PagoProveedor::with('factura.proveedor:id,razon_social', 'registrador:id,name')->where('estado', 'por_aprobar')->orderBy('id')->get()->map(fn (PagoProveedor $p) => [
                'id' => $p->id, 'factura_id' => $p->factura_proveedor_id, 'factura' => $p->factura?->nombre(), 'proveedor' => $p->factura?->proveedor?->razon_social, 'monto' => $p->monto,
                'fecha' => $p->fecha->toDateString(), 'metodo' => PagoProveedor::METODOS[$p->metodo], 'registrador' => $p->registrador?->name, 'propio' => $p->registrado_por === $request->user()->id,
            ]),
            'porRevisar' => FacturaProveedor::where('estado', 'por_revisar')->count(),
            'filtros' => $request->only('buscar'),
        ]);
    }

    public function pagar(Request $request, FacturaProveedor $factura)
    {
        $d = $request->validate([
            'monto' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'metodo' => ['required', Rule::in(array_keys(PagoProveedor::METODOS))],
            'referencia' => ['nullable', 'string', 'max:60'],
        ]);
        $p = $this->pagos->registrar($factura, $d, $request->user());

        return back()->with('exito', $p->estado === 'aplicado' ? 'Pago registrado y aplicado al saldo.' : 'Pago registrado: por su monto, espera la aprobación de otra persona.');
    }

    public function aprobarPago(Request $request, PagoProveedor $pago)
    {
        $this->pagos->aprobar($pago, $request->user());

        return back()->with('exito', 'Pago aprobado y aplicado al saldo.');
    }

    public function rechazarPago(Request $request, PagoProveedor $pago)
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:200']]);
        $this->pagos->rechazar($pago, $request->user(), $d['motivo']);

        return back()->with('exito', 'Pago rechazado: el saldo no cambia.');
    }

    private function tramo(FacturaProveedor $f): string
    {
        $dias = (int) $f->fecha_vencimiento->startOfDay()->diffInDays(now()->startOfDay(), false);

        return match (true) {
            $dias <= 0 => 'al_dia',
            $dias <= 30 => '1_30',
            $dias <= 60 => '31_60',
            $dias <= 90 => '61_90',
            default => 'mas_90',
        };
    }
}
