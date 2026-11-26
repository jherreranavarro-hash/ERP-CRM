<?php

namespace App\Support\Exportacion;

use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación CSV compatible con Excel (UTF-8 con BOM y separador ";", como lo
 * espera Excel en configuración regional chilena). Neutraliza fórmulas (CSV injection).
 */
class Csv
{
    /** @param iterable<array> $filas */
    public static function descargar(string $nombre, array $encabezados, iterable $filas): StreamedResponse
    {
        return response()->streamDownload(function () use ($encabezados, $filas) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $encabezados, ';');
            foreach ($filas instanceof Collection ? $filas->all() : $filas as $fila) {
                fputcsv($out, array_map([self::class, 'celda'], array_values($fila)), ';');
            }
            fclose($out);
        }, $nombre.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function celda(mixed $v): string
    {
        $v = is_bool($v) ? ($v ? 'Sí' : 'No') : (string) $v;

        return preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    }
}
