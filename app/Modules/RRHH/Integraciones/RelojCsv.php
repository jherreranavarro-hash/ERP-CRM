<?php

namespace App\Modules\RRHH\Integraciones;

use Illuminate\Validation\ValidationException;

/**
 * Lector del archivo de marcas exportado por el reloj control, en CSV:
 * RUT; fecha y hora (dd/mm/aaaa hh:mm o aaaa-mm-dd hh:mm); tipo (E/S, entrada/salida).
 * Es el adaptador por defecto mientras no se conecte un reloj por API.
 */
class RelojCsv implements RelojControl
{
    public function leer(string $contenido): array
    {
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        $lineas = preg_split('/\r\n|\r|\n/', trim($contenido));
        $sep = str_contains($lineas[0] ?? '', ';') ? ';' : (str_contains($lineas[0] ?? '', "\t") ? "\t" : ',');
        $marcas = [];
        foreach ($lineas as $n => $linea) {
            $c = array_map('trim', str_getcsv($linea, $sep));
            if (count($c) < 3 || ($n === 0 && ! preg_match('/\d/', $c[1]))) {
                continue; // encabezado o línea vacía
            }
            $tipo = match (mb_strtolower(mb_substr($c[2], 0, 1))) {
                'e', 'i' => 'entrada',
                's', 'o' => 'salida',
                default => throw ValidationException::withMessages(['archivo' => 'Línea '.($n + 1).': el tipo debe ser entrada o salida.']),
            };
            if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$#', $c[1], $m)) {
                $momento = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[3], $m[2], $m[1], $m[4], $m[5], $m[6] ?? 0);
            } elseif (preg_match('#^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$#', $c[1], $m)) {
                $momento = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? 0);
            } else {
                throw ValidationException::withMessages(['archivo' => 'Línea '.($n + 1).': fecha y hora no reconocidas.']);
            }
            $marcas[] = ['rut' => $c[0], 'momento' => $momento, 'tipo' => $tipo];
        }

        return $marcas;
    }
}
