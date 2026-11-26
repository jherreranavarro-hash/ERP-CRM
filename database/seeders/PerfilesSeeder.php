<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Perfil;
use Illuminate\Database\Seeder;

class PerfilesSeeder extends Seeder
{
    public const PERFILES = [
        'superadmin' => ['SuperAdmin', 'Administración total del sistema'],
        'gerencia' => ['Gerencia', 'Aprobaciones, metas y reportes'],
        'finanzas' => ['Finanzas', 'Tesorería, contabilidad y libros tributarios'],
        'rrhh' => ['RRHH', 'Gestión de personas y remuneraciones'],
        'caja' => ['Caja', 'Punto de venta, cobro y cierres'],
        'ventas' => ['Ventas', 'Ejecutivos comerciales: cotizaciones y órdenes'],
        'contact_center' => ['Contact Center', 'Agentes y supervisores de telefonía'],
        'bodega' => ['Bodega', 'Inventario, recepción y despachos'],
        'compras' => ['Compras', 'Abastecimiento y proveedores'],
        'auditor' => ['Auditor', 'Solo lectura de trazabilidad y reportes'],
        'legal' => ['Legal', 'Retención de datos y cumplimiento'],
    ];

    public function run(): void
    {
        foreach (self::PERFILES as $clave => [$nombre, $desc]) {
            Perfil::updateOrCreate(['clave' => $clave], ['nombre' => $nombre, 'descripcion' => $desc]);
        }
    }
}
