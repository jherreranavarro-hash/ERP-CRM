<?php

namespace App\Support;

/** RUT chileno: normalización, dígito verificador (módulo 11) y formato. */
class Rut
{
    /** "12.345.678-5" → "12345678-5" (DV en mayúscula). Null si no tiene forma de RUT. */
    public static function normalizar(?string $rut): ?string
    {
        $limpio = strtoupper(preg_replace('/[^0-9kK]/', '', (string) $rut));
        if (strlen($limpio) < 2) {
            return null;
        }

        return ltrim(substr($limpio, 0, -1), '0').'-'.substr($limpio, -1);
    }

    public static function dv(string $cuerpo): string
    {
        $suma = 0;
        $factor = 2;
        for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
            $suma += (int) $cuerpo[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $r = 11 - ($suma % 11);

        return match ($r) {
            11 => '0',
            10 => 'K',
            default => (string) $r,
        };
    }

    public static function valido(?string $rut): bool
    {
        $n = self::normalizar($rut);
        if (! $n || ! preg_match('/^(\d{1,9})-([\dK])$/', $n, $m)) {
            return false;
        }

        return self::dv($m[1]) === $m[2];
    }

    /** "12345678-5" → "12.345.678-5" */
    public static function formatear(?string $rut): string
    {
        $n = self::normalizar($rut);
        if (! $n) {
            return (string) $rut;
        }
        [$cuerpo, $dv] = explode('-', $n);

        return number_format((int) $cuerpo, 0, ',', '.').'-'.$dv;
    }
}
