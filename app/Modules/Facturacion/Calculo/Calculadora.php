<?php

namespace App\Modules\Facturacion\Calculo;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Reglas de cálculo de documentos tributarios. Todo en aritmética decimal
 * exacta (nunca float) y redondeo mitad hacia arriba a pesos enteros.
 *
 *  Línea:   bruto = red(cant × precio); desc = desc_monto o red(bruto × %/100);
 *           monto_item = bruto − desc ≥ 0
 *  Global:  % sobre las líneas afectas; no toca las exentas.
 *  33/52/56/61: neto = afecto − desc_global; iva = red(neto × tasa); total = neto + iva + exento
 *  39 (precio con IVA): total_afecto = afecto − desc_global; neto = red(total_afecto / (1 + tasa));
 *           iva = total_afecto − neto (cuadra al peso)
 *  34/41:   todas las líneas exentas, IVA 0.
 *  NC/ND sobre una boleta: mismo cálculo que la boleta (montos brutos, IndMntBruto del SII).
 */
final class Calculadora
{
    public const TIPOS = [33, 34, 39, 41, 52, 56, 61];

    public const EXENTOS = [34, 41];

    public const CON_IVA_INCLUIDO = [39];

    /** Redondeo mitad hacia arriba a pesos enteros. */
    public static function red(BigDecimal|string|int $valor): int
    {
        return BigDecimal::of($valor)->toScale(0, RoundingMode::HALF_UP)->toInt();
    }

    /**
     * @return array{bruto:int, descuento:int, monto_item:int}
     */
    public function linea(string|int $cantidad, string|int $precioUnitario, string|int $descuentoPct = 0, ?int $descuentoMonto = null): array
    {
        $cant = BigDecimal::of($cantidad);
        $precio = BigDecimal::of($precioUnitario);
        $pct = BigDecimal::of($descuentoPct);

        if ($cant->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }
        if ($precio->isNegative()) {
            throw new InvalidArgumentException('El precio no puede ser negativo.');
        }
        if ($pct->isNegative() || $pct->isGreaterThan(100)) {
            throw new InvalidArgumentException('El descuento debe estar entre 0 y 100 %.');
        }

        $bruto = self::red($cant->multipliedBy($precio));
        $descuento = $descuentoMonto ?? self::red(BigDecimal::of($bruto)->multipliedBy($pct)->dividedBy(100, 10, RoundingMode::HALF_UP));

        if ($descuento < 0 || $descuento > $bruto) {
            throw new InvalidArgumentException('El descuento no puede superar el monto de la línea.');
        }

        return ['bruto' => $bruto, 'descuento' => $descuento, 'monto_item' => $bruto - $descuento];
    }

    /**
     * @param  list<array{monto_item:int, exento:bool}>  $lineas
     * @return array{afecto:int, descuento_global_monto:int, monto_neto:int, monto_exento:int, monto_iva:int, monto_total:int}
     */
    public function documento(int $tipoDte, array $lineas, string|int $descuentoGlobalPct, string|int $tasaIva, ?bool $ivaIncluido = null): array
    {
        if (! in_array($tipoDte, self::TIPOS, true)) {
            throw new InvalidArgumentException("Tipo de documento {$tipoDte} no soportado.");
        }
        $pct = BigDecimal::of($descuentoGlobalPct);
        if ($pct->isNegative() || $pct->isGreaterThan(100)) {
            throw new InvalidArgumentException('El descuento global debe estar entre 0 y 100 %.');
        }
        $tasa = BigDecimal::of($tasaIva)->dividedBy(100, 10, RoundingMode::UNNECESSARY);

        $afecto = 0;
        $exento = 0;
        foreach ($lineas as $l) {
            if ($l['exento']) {
                $exento += $l['monto_item'];
            } else {
                $afecto += $l['monto_item'];
            }
        }

        if (in_array($tipoDte, self::EXENTOS, true) && $afecto > 0) {
            throw new InvalidArgumentException('En un documento exento todas las líneas deben ser exentas.');
        }

        $descGlobal = self::red(BigDecimal::of($afecto)->multipliedBy($pct)->dividedBy(100, 10, RoundingMode::HALF_UP));
        $totalAfecto = $afecto - $descGlobal;

        if ($ivaIncluido ?? in_array($tipoDte, self::CON_IVA_INCLUIDO, true)) {
            $neto = self::red(BigDecimal::of($totalAfecto)->dividedBy($tasa->plus(1), 10, RoundingMode::HALF_UP));
            $iva = $totalAfecto - $neto;
        } else {
            $neto = $totalAfecto;
            $iva = self::red(BigDecimal::of($neto)->multipliedBy($tasa));
        }

        $r = [
            'afecto' => $afecto,
            'descuento_global_monto' => $descGlobal,
            'monto_neto' => $neto,
            'monto_exento' => $exento,
            'monto_iva' => $iva,
            'monto_total' => $neto + $iva + $exento,
        ];
        $this->verificarInvariantes($tipoDte, $r);

        return $r;
    }

    /** Precio unitario de boleta: neto × (1 + tasa), redondeado al peso. */
    public function precioConIva(string|int $precioNeto, string|int $tasaIva): int
    {
        return self::red(BigDecimal::of($precioNeto)->multipliedBy(BigDecimal::of($tasaIva)->dividedBy(100, 10, RoundingMode::UNNECESSARY)->plus(1)));
    }

    private function verificarInvariantes(int $tipo, array $r): void
    {
        foreach (['monto_neto', 'monto_exento', 'monto_iva', 'monto_total', 'descuento_global_monto'] as $k) {
            if ($r[$k] < 0) {
                throw new \LogicException("Invariante rota: {$k} negativo.");
            }
        }
        if ($r['monto_total'] !== $r['monto_neto'] + $r['monto_iva'] + $r['monto_exento']) {
            throw new \LogicException('Invariante rota: total ≠ neto + iva + exento.');
        }
        if (in_array($tipo, self::EXENTOS, true) && $r['monto_iva'] !== 0) {
            throw new \LogicException('Invariante rota: documento exento con IVA.');
        }
    }
}
