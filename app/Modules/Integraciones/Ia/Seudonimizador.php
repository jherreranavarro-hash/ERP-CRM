<?php

namespace App\Modules\Integraciones\Ia;

/**
 * Reemplaza datos personales por marcadores antes de enviar texto a un
 * proveedor externo de IA, y los restituye en la respuesta. Cubre RUT,
 * correos, teléfonos, montos de tarjetas y los nombres conocidos del contexto.
 */
class Seudonimizador
{
    /** @var array<string, string> marcador => valor original */
    private array $mapa = [];

    private array $contadores = [];

    /** @param  list<string>  $nombres  nombres del contacto y del ejecutivo, que se ocultan aunque no sigan un patrón */
    public function __construct(private array $nombres = []) {}

    public function ocultar(string $texto): string
    {
        $patrones = [
            'CORREO' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
            'RUT' => '/\b\d{1,2}\.?\d{3}\.?\d{3}-[\dkK]\b/u',
            'TARJETA' => '/\b(?:\d[ -]?){13,19}\b/u',
            'TELEFONO' => '/(?:\+?56[\s-]?)?(?:\(?\d\)?[\s-]?)?\d{4}[\s-]?\d{4}\b/u',
        ];
        foreach ($patrones as $tipo => $re) {
            $texto = preg_replace_callback($re, fn ($m) => $this->marcador($tipo, $m[0]), $texto);
        }
        foreach (array_filter($this->nombres, fn ($n) => mb_strlen(trim((string) $n)) >= 3) as $nombre) {
            foreach (preg_split('/\s+/u', trim($nombre)) as $parte) {
                if (mb_strlen($parte) >= 3) {
                    $texto = preg_replace_callback('/\b'.preg_quote($parte, '/').'\b/iu', fn ($m) => $this->marcador('NOMBRE', $m[0]), $texto);
                }
            }
        }

        return $texto;
    }

    public function restituir(string $texto): string
    {
        return strtr($texto, $this->mapa);
    }

    public function ocultados(): int
    {
        return count($this->mapa);
    }

    private function marcador(string $tipo, string $valor): string
    {
        if ($existente = array_search($valor, $this->mapa, true)) {
            return $existente;
        }
        $this->contadores[$tipo] = ($this->contadores[$tipo] ?? 0) + 1;
        $m = "[{$tipo}_{$this->contadores[$tipo]}]";
        $this->mapa[$m] = $valor;

        return $m;
    }
}
