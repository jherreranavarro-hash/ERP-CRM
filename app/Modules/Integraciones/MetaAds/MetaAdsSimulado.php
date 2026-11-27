<?php

namespace App\Modules\Integraciones\MetaAds;

use Illuminate\Support\Carbon;

/**
 * Cuenta publicitaria simulada y determinista: tres campañas cuyas métricas crecen
 * con los días del mes y prospectos con id estable por día, así una sincronización
 * repetida no duplica nada. Un teléfono que termina en 0000 está en la lista negra demo.
 */
class MetaAdsSimulado implements MetaAds
{
    private const CAMPANAS = [
        ['id' => 'act_sim_120001', 'nombre' => 'Obra gruesa · Formulario cotización', 'estado' => 'activa', 'objetivo' => 'LEAD_GENERATION', 'presupuesto_diario' => 25000, 'por_dia' => 3],
        ['id' => 'act_sim_120002', 'nombre' => 'Terminaciones · Remodela tu casa', 'estado' => 'activa', 'objetivo' => 'LEAD_GENERATION', 'presupuesto_diario' => 15000, 'por_dia' => 2],
        ['id' => 'act_sim_120003', 'nombre' => 'Marca · Alcance Santiago', 'estado' => 'pausada', 'objetivo' => 'REACH', 'presupuesto_diario' => 10000, 'por_dia' => 0],
    ];

    private const NOMBRES = ['Camila Rojas', 'Diego Muñoz', 'Francisca Soto', 'Matías González', 'Valentina Díaz', 'Joaquín Pérez', 'Antonia Silva', 'Benjamín Contreras', 'Isidora Morales', 'Tomás Fuentes'];

    private const COMUNAS = ['Maipú', 'La Florida', 'Puente Alto', 'Ñuñoa', 'Las Condes', 'Providencia', 'San Bernardo', 'Quilicura'];

    public function campanas(): array
    {
        $dias = now()->day;

        return array_map(fn ($c) => [
            'id' => $c['id'], 'nombre' => $c['nombre'], 'estado' => $c['estado'], 'objetivo' => $c['objetivo'], 'presupuesto_diario' => $c['presupuesto_diario'],
            'gasto' => $c['presupuesto_diario'] * $dias - (crc32($c['id']) % 5000),
            'impresiones' => $c['presupuesto_diario'] * $dias / 10 + (crc32($c['id']) % 900),
            'clics' => (int) ($c['presupuesto_diario'] * $dias / 10 * 0.018) + (crc32($c['id']) % 40),
            'leads' => $c['por_dia'] * $dias,
        ], self::CAMPANAS);
    }

    public function prospectos(string $campana, \DateTimeInterface $desde): array
    {
        $c = collect(self::CAMPANAS)->firstWhere('id', $campana);
        if (! $c || ! $c['por_dia']) {
            return [];
        }
        $salida = [];
        $dia = Carbon::instance($desde)->startOfDay()->max(now()->subDays(14)->startOfDay());
        for (; $dia->lte(now()); $dia->addDay()) {
            for ($i = 0; $i < $c['por_dia']; $i++) {
                $semilla = crc32($campana.$dia->toDateString().$i);
                $creado = $dia->copy()->addHours(9 + $semilla % 10)->addMinutes($semilla % 60);
                if ($creado->gt(now()) || $creado->lt($desde)) {
                    continue;
                }
                $tel = '569'.str_pad((string) (70000000 + $semilla % 29999999), 8, '0', STR_PAD_LEFT);
                if ($semilla % 23 === 0) {
                    $tel = substr($tel, 0, -4).'0000';
                }
                $nombre = self::NOMBRES[$semilla % count(self::NOMBRES)];
                $salida[] = ['id' => 'lead_'.substr(md5($campana.$dia->toDateString().$i), 0, 16), 'nombre' => $nombre, 'telefono' => $tel,
                    'email' => strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', ' '], ['a', 'e', 'i', 'o', 'u', 'n', '.'], $nombre)).'@correo.cl',
                    'comuna' => self::COMUNAS[$semilla % count(self::COMUNAS)], 'creado' => $creado->toIso8601String()];
            }
        }

        return $salida;
    }

    public function simulado(): bool
    {
        return true;
    }
}
