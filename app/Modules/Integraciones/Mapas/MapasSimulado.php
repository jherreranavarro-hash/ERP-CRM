<?php

namespace App\Modules\Integraciones\Mapas;

/**
 * Geocodificación simulada: ubica cada dirección cerca del centro de su comuna
 * (Región Metropolitana), con un desplazamiento fijo según la dirección para
 * que dos direcciones distintas no caigan en el mismo punto. No sale a internet.
 */
class MapasSimulado implements Mapas
{
    private const COMUNAS = [
        'santiago' => [-33.4489, -70.6693], 'providencia' => [-33.4314, -70.6093], 'las condes' => [-33.4080, -70.5670], 'vitacura' => [-33.3900, -70.5900],
        'nunoa' => [-33.4569, -70.5976], 'la reina' => [-33.4500, -70.5420], 'penalolen' => [-33.4860, -70.5420], 'macul' => [-33.4870, -70.5990],
        'la florida' => [-33.5220, -70.5980], 'puente alto' => [-33.6110, -70.5750], 'maipu' => [-33.5100, -70.7570], 'pudahuel' => [-33.4400, -70.7640],
        'cerrillos' => [-33.4990, -70.7120], 'estacion central' => [-33.4590, -70.6980], 'lo prado' => [-33.4440, -70.7260], 'quinta normal' => [-33.4280, -70.6990],
        'quilicura' => [-33.3600, -70.7280], 'renca' => [-33.4060, -70.7280], 'independencia' => [-33.4160, -70.6650], 'recoleta' => [-33.4060, -70.6400],
        'huechuraba' => [-33.3670, -70.6360], 'conchali' => [-33.3840, -70.6750], 'san miguel' => [-33.4970, -70.6510], 'la cisterna' => [-33.5290, -70.6640],
        'san bernardo' => [-33.5920, -70.6990], 'lo barnechea' => [-33.3520, -70.5180], 'cerro navia' => [-33.4250, -70.7440], 'lo espejo' => [-33.5210, -70.6920],
    ];

    public function geocodificar(string $direccion, ?string $comuna): ?array
    {
        $clave = $this->normalizar((string) $comuna);
        $centro = self::COMUNAS[$clave] ?? self::COMUNAS['santiago'];
        $h = crc32($this->normalizar($direccion).'|'.$clave);
        $dLat = (($h & 0xFFFF) / 0xFFFF - 0.5) * 0.03;           // ±1,6 km aprox.
        $dLng = ((($h >> 16) & 0xFFFF) / 0xFFFF - 0.5) * 0.03;

        return [round($centro[0] + $dLat, 6), round($centro[1] + $dLng, 6)];
    }

    public function simulado(): bool
    {
        return true;
    }

    private function normalizar(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
    }
}
