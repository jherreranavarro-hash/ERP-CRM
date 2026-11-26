<?php

namespace App\Modules\RRHH;

/** Montos en palabras para contratos y finiquitos ("quinientos treinta y nueve mil pesos"). */
class Palabras
{
    private const UNIDADES = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince',
        'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];

    private const DECENAS = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];

    private const CENTENAS = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos', 6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];

    public static function pesos(int $n): string
    {
        if ($n === 0) {
            return 'cero pesos';
        }
        $t = self::numero(abs($n));
        // "un millón de pesos", "dos millones de pesos"
        $de = preg_match('/(millón|millones)$/', $t) ? ' de' : '';

        return ($n < 0 ? 'menos ' : '').$t.$de.' pesos';
    }

    public static function numero(int $n): string
    {
        if ($n >= 1_000_000) {
            $m = intdiv($n, 1_000_000);
            $r = $n % 1_000_000;

            return trim(($m === 1 ? 'un millón' : self::apocope(self::numero($m)).' millones').($r ? ' '.self::numero($r) : ''));
        }
        if ($n >= 1000) {
            $m = intdiv($n, 1000);
            $r = $n % 1000;

            return trim(($m === 1 ? 'mil' : self::apocope(self::numero($m)).' mil').($r ? ' '.self::numero($r) : ''));
        }
        if ($n >= 100) {
            $c = intdiv($n, 100);
            $r = $n % 100;

            return $n === 100 ? 'cien' : self::CENTENAS[$c].($r ? ' '.self::numero($r) : '');
        }
        if ($n < 30) {
            return self::UNIDADES[$n];
        }
        $d = intdiv($n, 10);
        $u = $n % 10;

        return self::DECENAS[$d].($u ? ' y '.self::UNIDADES[$u] : '');
    }

    /** "veintiuno mil" → "veintiún mil", "uno mil" no ocurre. */
    private static function apocope(string $t): string
    {
        return preg_replace(['/veintiuno$/', '/uno$/'], ['veintiún', 'un'], $t);
    }
}
