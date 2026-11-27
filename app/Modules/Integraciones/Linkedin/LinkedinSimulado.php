<?php

namespace App\Modules\Integraciones\Linkedin;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Bandeja simulada y determinista: algunos contactos B2B escriben durante los últimos
 * días con ids estables, así la sincronización es idempotente. Una conversación cuyo
 * id termina en "x" simula un envío rechazado.
 */
class LinkedinSimulado implements Linkedin
{
    private const CONTACTOS = [
        ['conv' => 'urn:li:conv:sim-a1', 'nombre' => 'Rodrigo Valenzuela', 'cargo' => 'Jefe de Adquisiciones', 'organizacion' => 'Inmobiliaria Cordillera', 'perfil' => 'https://www.linkedin.com/in/sim-rvalenzuela',
            'textos' => ['Hola, estamos licitando materiales para un edificio de 12 pisos en Ñuñoa. ¿Trabajan con despacho programado a obra?', '¿Podrían enviarme lista de precios mayorista para fierro y cemento?']],
        ['conv' => 'urn:li:conv:sim-b2', 'nombre' => 'Paula Herrera', 'cargo' => 'Gerenta de Proyectos', 'organizacion' => 'Constructora Pacífico', 'perfil' => 'https://www.linkedin.com/in/sim-pherrera',
            'textos' => ['Buenas tardes, vi su publicación sobre terminaciones. Nos interesa conversar por un proyecto de 40 casas en Buin.']],
        ['conv' => 'urn:li:conv:sim-c3', 'nombre' => 'Andrés Carrasco', 'cargo' => 'Administrador de Obra', 'organizacion' => 'Ingeniería Andes Ltda.', 'perfil' => 'https://www.linkedin.com/in/sim-acarrasco',
            'textos' => ['Necesitamos cotizar planchas de yeso-cartón 15 mm, unas 800 unidades.', '¿Tienen crédito a 30 días para empresas?', 'Quedo atento, gracias.']],
    ];

    public function mensajesDesde(\DateTimeInterface $desde): array
    {
        $salida = [];
        foreach (self::CONTACTOS as $k => $c) {
            foreach ($c['textos'] as $i => $texto) {
                // Mensajes escalonados en los últimos días, el más reciente hace pocas horas.
                $fecha = now()->startOfHour()->subDays(count($c['textos']) - $i + $k)->addHours(10 + $k);
                if ($fecha->lt($desde) || $fecha->gt(now())) {
                    continue;
                }
                $salida[] = ['id' => 'urn:li:msg:'.substr(md5($c['conv'].$i), 0, 12), 'conversacion' => $c['conv'], 'nombre' => $c['nombre'], 'cargo' => $c['cargo'],
                    'organizacion' => $c['organizacion'], 'perfil' => $c['perfil'], 'texto' => $texto, 'fecha' => Carbon::instance($fecha)->toIso8601String()];
            }
        }
        usort($salida, fn ($a, $b) => strcmp($a['fecha'], $b['fecha']));

        return $salida;
    }

    public function enviar(string $conversacion, string $texto): string
    {
        if (str_ends_with($conversacion, 'x')) {
            throw new \RuntimeException('LinkedIn rechazó el envío (simulado).');
        }

        return 'urn:li:msg:'.Str::lower(Str::random(12));
    }

    public function simulado(): bool
    {
        return true;
    }
}
