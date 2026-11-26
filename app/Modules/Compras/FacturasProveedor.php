<?php

namespace App\Modules\Compras;

use App\Models\User;
use App\Modules\Compras\Models\FacturaProveedor;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\OrdenCompraLinea;
use App\Modules\Compras\Models\Proveedor;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\Inventario\Inventario;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Facturas de proveedor. Al registrarla se cruza con la orden de compra y lo
 * recibido (3-way match): no se factura más de lo recibido, el precio no se
 * aleja de la OC más que la tolerancia y la suma de líneas cuadra con el total
 * declarado. Si calza, pasa directo a cuentas por pagar; si no (o no tiene
 * OC), queda por revisar hasta que otra persona la apruebe o la rechace.
 */
class FacturasProveedor
{
    public const MAX_LINEAS = 200;

    public function __construct(private Parametros $parametros, private Auditoria $auditoria) {}

    /**
     * @param  array{proveedor_id:int, tipo_dte:int, folio:int, fecha_emision:string, fecha_vencimiento?:?string, orden_compra_id?:?int,
     *               monto_neto:int, monto_exento?:int, monto_iva:int, monto_total:int, observaciones?:?string,
     *               lineas?: list<array{orden_compra_linea_id?:?int, producto_id?:?int, descripcion?:?string, cantidad:string|float, costo_unitario:string|float}>}  $d
     */
    public function registrar(array $d, User $user): FacturaProveedor
    {
        $proveedor = Proveedor::find($d['proveedor_id']) ?? throw ValidationException::withMessages(['proveedor_id' => 'Proveedor no encontrado.']);
        $tipo = (int) $d['tipo_dte'];
        if (! array_key_exists($tipo, FacturaProveedor::TIPOS)) {
            throw ValidationException::withMessages(['tipo_dte' => 'Tipo de documento no válido.']);
        }
        $neto = (int) $d['monto_neto'];
        $exento = (int) ($d['monto_exento'] ?? 0);
        $iva = (int) $d['monto_iva'];
        $total = (int) $d['monto_total'];
        if ($neto < 0 || $exento < 0 || $iva < 0 || $total <= 0 || $total !== $neto + $exento + $iva) {
            throw ValidationException::withMessages(['monto_total' => 'El total debe ser neto + exento + IVA.']);
        }
        if ($tipo === 34 && ($neto > 0 || $iva > 0)) {
            throw ValidationException::withMessages(['monto_neto' => 'Una factura exenta no tiene neto afecto ni IVA.']);
        }
        $cuadre = (int) $this->parametros->valor('compras.cuadre_tolerancia_monto');
        $ivaEsperado = Calculadora::red(BigDecimal::of($neto)->multipliedBy((string) $this->parametros->valor('tributario.tasa_iva'))->dividedBy(100, 6));
        if (abs($iva - $ivaEsperado) > max($cuadre, 1)) {
            throw ValidationException::withMessages(['monto_iva' => 'El IVA no corresponde al neto (se esperaba $'.number_format($ivaEsperado, 0, ',', '.').').']);
        }
        $emision = CarbonImmutable::parse($d['fecha_emision']);
        $vencimiento = ! empty($d['fecha_vencimiento']) ? CarbonImmutable::parse($d['fecha_vencimiento']) : $emision->addDays($proveedor->plazo_pago_dias);
        if ($vencimiento->lt($emision)) {
            throw ValidationException::withMessages(['fecha_vencimiento' => 'El vencimiento no puede ser anterior a la emisión.']);
        }
        if (FacturaProveedor::where(['proveedor_id' => $proveedor->id, 'tipo_dte' => $tipo, 'folio' => (int) $d['folio']])->exists()) {
            throw ValidationException::withMessages(['folio' => 'Esta factura del proveedor ya está registrada.']);
        }
        $lineas = array_values($d['lineas'] ?? []);
        if (count($lineas) > self::MAX_LINEAS) {
            throw ValidationException::withMessages(['lineas' => 'Máximo '.self::MAX_LINEAS.' líneas.']);
        }

        return DB::transaction(function () use ($d, $user, $proveedor, $tipo, $neto, $exento, $iva, $total, $emision, $vencimiento, $lineas, $cuadre) {
            $oc = null;
            if (! empty($d['orden_compra_id'])) {
                $oc = OrdenCompra::whereKey($d['orden_compra_id'])->lockForUpdate()->first();
                if (! $oc || $oc->proveedor_id !== $proveedor->id || ! in_array($oc->estado, ['aprobada', 'parcial', 'recibida', 'cerrada'], true)) {
                    throw ValidationException::withMessages(['orden_compra_id' => 'La orden de compra no es de este proveedor o no está aprobada.']);
                }
                $oc->load('lineas.producto:id,nombre');
            }

            [$filas, $diferencias] = $this->cruzar($oc, $lineas, $neto + $exento, $cuadre);
            $estado = $diferencias ? 'por_revisar' : 'por_pagar';

            $f = FacturaProveedor::create([
                'proveedor_id' => $proveedor->id, 'tipo_dte' => $tipo, 'folio' => (int) $d['folio'], 'fecha_emision' => $emision->toDateString(),
                'fecha_vencimiento' => $vencimiento->toDateString(), 'orden_compra_id' => $oc?->id, 'monto_neto' => $neto, 'monto_exento' => $exento,
                'monto_iva' => $iva, 'monto_total' => $total, 'saldo' => $total, 'estado' => $estado, 'diferencias' => $diferencias ?: null,
                'registrado_por' => $user->id, 'observaciones' => isset($d['observaciones']) ? mb_substr(trim((string) $d['observaciones']), 0, 300) : null,
            ]);
            foreach ($filas as $fila) {
                $f->lineas()->create($fila);
            }
            if ($estado === 'por_pagar') {
                $this->facturarLineas($f);
            }
            $this->auditoria->registrar('factura_proveedor.registrar', $f, null, ['factura' => $f->nombre(), 'proveedor' => $proveedor->razon_social, 'total' => $total, 'estado' => $estado]);

            return $f->load('lineas');
        });
    }

    /** Acepta una factura con diferencias: pasa a cuentas por pagar. */
    public function aprobar(FacturaProveedor $factura, User $user): FacturaProveedor
    {
        return DB::transaction(function () use ($factura, $user) {
            $f = $this->porRevisar($factura, $user, 'aprueba');
            if ($f->orden_compra_id) {
                OrdenCompra::whereKey($f->orden_compra_id)->lockForUpdate()->first();
            }
            $f->forceFill(['estado' => 'por_pagar', 'aprobado_por' => $user->id])->save();
            $this->facturarLineas($f);
            $this->auditoria->registrar('factura_proveedor.aprobar', $f, null, ['factura' => $f->nombre(), 'diferencias' => $f->diferencias]);

            return $f;
        });
    }

    public function rechazar(FacturaProveedor $factura, User $user, string $motivo): FacturaProveedor
    {
        return DB::transaction(function () use ($factura, $user, $motivo) {
            $f = $this->porRevisar($factura, $user, 'rechaza');
            $f->forceFill(['estado' => 'rechazada', 'aprobado_por' => $user->id, 'motivo_rechazo' => mb_substr(trim($motivo), 0, 200)])->save();
            $this->auditoria->registrar('factura_proveedor.rechazar', $f, null, ['factura' => $f->nombre(), 'motivo' => $motivo]);

            return $f;
        });
    }

    /**
     * Cruza las líneas con la OC y lo recibido.
     *
     * @return array{0: list<array<string,mixed>>, 1: list<string>}
     */
    private function cruzar(?OrdenCompra $oc, array $lineas, int $base, int $cuadre): array
    {
        $diferencias = [];
        $filas = [];
        $suma = 0;
        $tolerancia = BigDecimal::of((string) $this->parametros->valor('compras.match_tolerancia_pct', $oc?->local_id));
        $porLinea = [];
        foreach ($lineas as $i => $l) {
            $cant = BigDecimal::of((string) ($l['cantidad'] ?? 0));
            $costo = BigDecimal::of((string) ($l['costo_unitario'] ?? 0));
            if ($cant->isZero()) {
                continue;
            }
            if ($cant->isNegative() || $costo->isNegative()) {
                throw ValidationException::withMessages(["lineas.{$i}" => 'Línea '.($i + 1).': cantidad y costo no negativos.']);
            }
            $ol = null;
            if ($oc) {
                $ol = $oc->lineas->firstWhere('id', (int) ($l['orden_compra_linea_id'] ?? 0))
                    ?? throw ValidationException::withMessages(["lineas.{$i}" => 'Línea '.($i + 1).": no corresponde a la orden de compra N° {$oc->numero}."]);
                $porLinea[$ol->id] = ($porLinea[$ol->id] ?? BigDecimal::zero())->plus($cant);
                $nombre = $ol->producto?->nombre ?? 'Producto';
                $ref = BigDecimal::of((string) $ol->costo_unitario);
                if ($ref->isPositive()) {
                    $var = $costo->minus($ref)->multipliedBy(100)->dividedBy($ref, 2, RoundingMode::HALF_UP);
                    if ($var->abs()->isGreaterThan($tolerancia)) {
                        $diferencias[] = "{$nombre}: precio $".number_format((float) (string) $costo, 0, ',', '.').' vs. $'.number_format((float) (string) $ref, 0, ',', '.')." en la OC ({$var}%).";
                    }
                }
            }
            $descripcion = trim((string) ($l['descripcion'] ?? '')) ?: ($ol?->producto?->nombre ?? 'Ítem '.($i + 1));
            $monto = Calculadora::red($cant->multipliedBy($costo));
            $suma += $monto;
            $filas[] = ['orden_compra_linea_id' => $ol?->id, 'producto_id' => $ol?->producto_id ?? ($l['producto_id'] ?? null), 'descripcion' => mb_substr($descripcion, 0, 80),
                'cantidad' => (string) $cant, 'costo_unitario' => (string) $costo, 'monto' => $monto];
        }

        if (! $oc) {
            $diferencias[] = 'Factura sin orden de compra.';
        } else {
            if (! $filas) {
                throw ValidationException::withMessages(['lineas' => 'Indica qué se factura de la orden de compra.']);
            }
            foreach ($porLinea as $id => $cant) {
                $ol = $oc->lineas->firstWhere('id', $id);
                if ($cant->isGreaterThan($ol->porFacturar())) {
                    $diferencias[] = ($ol->producto?->nombre ?? 'Producto').': factura '.Inventario::cifra($cant).' y hay '.Inventario::cifra($ol->porFacturar()).' recibido sin facturar.';
                }
            }
        }
        if ($filas && abs($suma - $base) > $cuadre) {
            $diferencias[] = 'Las líneas suman $'.number_format($suma, 0, ',', '.').' y el neto + exento declarado es $'.number_format($base, 0, ',', '.').'.';
        }

        return [$filas, $diferencias];
    }

    /** Marca como facturado lo recibido (nunca más de lo recibido). */
    private function facturarLineas(FacturaProveedor $f): void
    {
        foreach ($f->lineas()->whereNotNull('orden_compra_linea_id')->get() as $l) {
            $ol = OrdenCompraLinea::whereKey($l->orden_compra_linea_id)->lockForUpdate()->first();
            $suma = BigDecimal::of((string) $ol->cantidad_facturada)->plus((string) $l->cantidad);
            $tope = BigDecimal::of((string) $ol->cantidad_recibida);
            $ol->forceFill(['cantidad_facturada' => (string) ($suma->isGreaterThan($tope) ? $tope : $suma)])->save();
        }
    }

    private function porRevisar(FacturaProveedor $factura, User $user, string $verbo): FacturaProveedor
    {
        $f = FacturaProveedor::whereKey($factura->id)->lockForUpdate()->firstOrFail();
        if ($f->estado !== 'por_revisar') {
            throw ValidationException::withMessages(['factura' => 'La factura no está por revisar.']);
        }
        if ($f->registrado_por === $user->id) {
            throw ValidationException::withMessages(['factura' => "Nadie {$verbo} una factura que registró: debe revisarla otra persona."]);
        }

        return $f;
    }
}
