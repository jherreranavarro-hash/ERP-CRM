<?php

namespace App\Modules\RRHH;

use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\RRHH\Models\Contrato;
use App\Modules\RRHH\Models\Empleado;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Motor de remuneraciones con la legislación chilena. Todos los valores que
 * cambian (UF, UTM, ingreso mínimo, topes, tasas de AFP, salud, cesantía y
 * aportes del empleador) vienen de los parámetros de nómina; el cálculo es
 * puro: recibe los datos del mes y devuelve la liquidación, sin tocar la base.
 */
class MotorNomina
{
    /**
     * Impuesto único de segunda categoría (art. 43 N° 1 LIR), mensual.
     * [desde UTM, hasta UTM (null = sin tope), factor, cantidad a rebajar en UTM].
     */
    public const TABLA_IMPUESTO = [
        [0, 13.5, '0', '0'],
        [13.5, 30, '0.04', '0.54'],
        [30, 50, '0.08', '1.74'],
        [50, 70, '0.135', '4.49'],
        [70, 90, '0.23', '11.14'],
        [90, 120, '0.304', '17.80'],
        [120, 310, '0.35', '23.32'],
        [310, null, '0.40', '38.82'],
    ];

    public function __construct(private Parametros $parametros) {}

    /** Indicadores vigentes, que la nómina guarda junto a sus liquidaciones. */
    public function indicadores(): array
    {
        $p = fn (string $k) => $this->parametros->valor('nomina.'.$k);
        $afp = [];
        foreach (array_keys(Empleado::AFPS) as $a) {
            $afp[$a] = (string) $p('afp_'.$a);
        }

        return [
            'uf' => (string) $p('uf'), 'utm' => (int) $p('utm'), 'imm' => (int) $p('imm'),
            'tope_afp_uf' => (string) $p('tope_afp_uf'), 'tope_cesantia_uf' => (string) $p('tope_cesantia_uf'), 'tope_indemnizacion_uf' => (string) $p('tope_indemnizacion_uf'),
            'afp' => $afp, 'salud_pct' => (string) $p('salud_pct'),
            'cesantia_trabajador_pct' => (string) $p('cesantia_trabajador_pct'), 'cesantia_empleador_indefinido_pct' => (string) $p('cesantia_empleador_indefinido_pct'),
            'cesantia_empleador_plazo_pct' => (string) $p('cesantia_empleador_plazo_pct'), 'sis_pct' => (string) $p('sis_pct'),
            'aporte_empleador_pct' => (string) $p('aporte_empleador_pct'), 'mutual_pct' => (string) $p('mutual_pct'),
            'recargo_horas_extra_pct' => (string) $p('recargo_horas_extra_pct'),
        ];
    }

    /**
     * @param  array{dias_trabajados:int, horas_extra:string|float, comisiones:int, bonos?:list<array{concepto:string, monto:int}>, cuota_prestamo?:int, anticipo?:int}  $mes
     * @return array{dias_trabajados:int, horas_extra:string, haberes:list<array>, descuentos:list<array>, aportes:list<array>, imponible:int, no_imponible:int, tributable:int, impuesto:int, total_descuentos:int, liquido:int, costo_empleador:int}
     */
    public function calcular(Empleado $e, Contrato $c, array $mes, array $ind): array
    {
        $dias = max(0, min(30, (int) $mes['dias_trabajados']));
        $prop = fn (int $monto) => Calculadora::red(BigDecimal::of($monto)->multipliedBy($dias)->dividedBy(30, 6, RoundingMode::HALF_UP));
        $pct = fn (int $base, string $tasa) => Calculadora::red(BigDecimal::of($base)->multipliedBy($tasa)->dividedBy(100, 6, RoundingMode::HALF_UP));
        $uf = fn (string $cantidad) => Calculadora::red(BigDecimal::of($cantidad)->multipliedBy($ind['uf']));

        // Haberes imponibles.
        $haberes = [];
        $sueldo = $prop($c->sueldo_base);
        $haberes[] = ['concepto' => "Sueldo base ({$dias} días)", 'monto' => $sueldo, 'imponible' => true];

        $he = BigDecimal::of((string) $mes['horas_extra'])->toScale(2, RoundingMode::HALF_UP);
        if ($he->isPositive()) {
            // Valor hora ordinaria = sueldo mensual / 30 × 28 / (4 × jornada semanal), con el recargo legal.
            $valorHora = BigDecimal::of($c->sueldo_base)->multipliedBy(28)->dividedBy(30 * 4 * $c->jornada_horas, 6, RoundingMode::HALF_UP)
                ->multipliedBy(BigDecimal::of(100)->plus($ind['recargo_horas_extra_pct']))->dividedBy(100, 6, RoundingMode::HALF_UP);
            $haberes[] = ['concepto' => "Horas extraordinarias ({$he} h)", 'monto' => Calculadora::red($valorHora->multipliedBy($he)), 'imponible' => true];
        }
        if (($mes['comisiones'] ?? 0) !== 0) {
            $haberes[] = ['concepto' => 'Comisiones por ventas', 'monto' => (int) $mes['comisiones'], 'imponible' => true];
        }
        foreach ($mes['bonos'] ?? [] as $b) {
            if ((int) $b['monto'] > 0) {
                $haberes[] = ['concepto' => mb_substr($b['concepto'], 0, 60), 'monto' => (int) $b['monto'], 'imponible' => true];
            }
        }
        if ($c->gratificacion) {
            // Art. 50: 25% de lo devengado, con tope mensual de 4,75 ingresos mínimos / 12.
            $base = array_sum(array_column($haberes, 'monto'));
            $tope = Calculadora::red(BigDecimal::of($ind['imm'])->multipliedBy('4.75')->dividedBy(12, 6, RoundingMode::HALF_UP));
            $haberes[] = ['concepto' => 'Gratificación legal (art. 50)', 'monto' => min($pct($base, '25'), $tope), 'imponible' => true];
        }
        $imponible = array_sum(array_column($haberes, 'monto'));

        // No imponibles: colación y movilización, proporcionales a los días.
        foreach (['colacion' => 'Asignación de colación', 'movilizacion' => 'Asignación de movilización'] as $campo => $concepto) {
            if ($c->{$campo} > 0) {
                $haberes[] = ['concepto' => $concepto, 'monto' => $prop($c->{$campo}), 'imponible' => false];
            }
        }
        $noImponible = array_sum(array_column(array_filter($haberes, fn ($h) => ! $h['imponible']), 'monto'));

        // Cotizaciones del trabajador, con topes en UF.
        $baseAfp = min($imponible, $uf($ind['tope_afp_uf']));
        $baseCes = min($imponible, $uf($ind['tope_cesantia_uf']));
        $descuentos = [];
        $tasaAfp = $ind['afp'][$e->afp] ?? throw new \InvalidArgumentException("AFP desconocida: {$e->afp}");
        $afp = $pct($baseAfp, $tasaAfp);
        $descuentos[] = ['concepto' => 'AFP '.(Empleado::AFPS[$e->afp] ?? $e->afp)." ({$tasaAfp}%)", 'monto' => $afp, 'legal' => true];
        $saludLegal = $pct($baseAfp, $ind['salud_pct']);
        $salud = $saludLegal;
        if ($e->salud !== 'fonasa' && BigDecimal::of((string) $e->plan_isapre_uf)->isPositive()) {
            $salud = max($saludLegal, $uf((string) $e->plan_isapre_uf));
        }
        $descuentos[] = ['concepto' => $e->salud === 'fonasa' ? "Fonasa ({$ind['salud_pct']}%)" : "Isapre {$e->salud}", 'monto' => $salud, 'legal' => true];
        $cesantia = $c->tipo === 'indefinido' ? $pct($baseCes, $ind['cesantia_trabajador_pct']) : 0;
        if ($cesantia) {
            $descuentos[] = ['concepto' => "Seguro de cesantía ({$ind['cesantia_trabajador_pct']}%)", 'monto' => $cesantia, 'legal' => true];
        }

        // Impuesto único: la renta tributable rebaja solo la salud legal (7%), no el adicional de isapre.
        $tributable = max(0, $imponible - $afp - min($salud, $saludLegal) - $cesantia);
        $impuesto = $this->impuesto($tributable, $ind['utm']);
        if ($impuesto) {
            $descuentos[] = ['concepto' => 'Impuesto único', 'monto' => $impuesto, 'legal' => true];
        }
        if (($mes['anticipo'] ?? 0) > 0) {
            $descuentos[] = ['concepto' => 'Anticipo de sueldo', 'monto' => (int) $mes['anticipo'], 'legal' => false];
        }
        if (($mes['cuota_prestamo'] ?? 0) > 0) {
            $descuentos[] = ['concepto' => 'Cuota de préstamo', 'monto' => (int) $mes['cuota_prestamo'], 'legal' => false];
        }
        $totalDescuentos = array_sum(array_column($descuentos, 'monto'));

        // Aportes del empleador (no se descuentan al trabajador).
        $aportes = [
            ['concepto' => "Seguro de invalidez y sobrevivencia ({$ind['sis_pct']}%)", 'monto' => $pct($baseAfp, $ind['sis_pct'])],
            ['concepto' => 'Seguro de cesantía empleador', 'monto' => $pct($baseCes, $c->tipo === 'indefinido' ? $ind['cesantia_empleador_indefinido_pct'] : $ind['cesantia_empleador_plazo_pct'])],
            ['concepto' => "Cotización adicional empleador ({$ind['aporte_empleador_pct']}%)", 'monto' => $pct($baseAfp, $ind['aporte_empleador_pct'])],
            ['concepto' => "Seguro de accidentes ({$ind['mutual_pct']}%)", 'monto' => $pct($baseAfp, $ind['mutual_pct'])],
        ];
        $aportes = array_values(array_filter($aportes, fn ($a) => $a['monto'] > 0));
        $totalAportes = array_sum(array_column($aportes, 'monto'));

        return [
            'dias_trabajados' => $dias, 'horas_extra' => (string) $he, 'haberes' => $haberes, 'descuentos' => $descuentos, 'aportes' => $aportes,
            'imponible' => $imponible, 'no_imponible' => $noImponible, 'tributable' => $tributable, 'impuesto' => $impuesto,
            'total_descuentos' => $totalDescuentos, 'liquido' => $imponible + $noImponible - $totalDescuentos,
            'costo_empleador' => $imponible + $noImponible + $totalAportes,
        ];
    }

    /** Impuesto único mensual sobre la renta tributable, en pesos. */
    public function impuesto(int $tributable, int $utm): int
    {
        $enUtm = BigDecimal::of($tributable)->dividedBy($utm, 6, RoundingMode::HALF_UP);
        foreach (self::TABLA_IMPUESTO as [$desde, $hasta, $factor, $rebaja]) {
            if ($hasta === null || $enUtm->isLessThanOrEqualTo((string) $hasta)) {
                return max(0, Calculadora::red(BigDecimal::of($tributable)->multipliedBy($factor)->minus(BigDecimal::of($rebaja)->multipliedBy($utm))));
            }
        }

        return 0;
    }
}
