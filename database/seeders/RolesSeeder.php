<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Perfil;
use App\Modules\Core\Models\Permiso;
use App\Modules\Core\Models\Rol;
use Illuminate\Database\Seeder;

/** Roles iniciales por perfil (editables luego en Configuración → Perfiles). */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['Administrador del sistema', 'superadmin', ['*'], []],
            ['Gerente', 'gerencia', ['dashboard.*', '*.ver', '*.exportar', '*.aprobar'], ['configuracion.parametros.proponer']],
            ['Analista de Finanzas', 'finanzas', ['dashboard.*', 'finanzas.*', 'caja.cierres-de-caja.*', 'configuracion.cuentas-corrientes.*'], ['configuracion.parametros.ver', 'configuracion.parametros.proponer']],
            ['Analista de RRHH', 'rrhh', ['rrhh.*'], []],
            ['Cajera', 'caja', ['caja.*'], ['ventascrm.cotizacion.ver']],
            ['Ejecutiva comercial', 'ventas', ['dashboard.*', 'ventascrm.dashboard.*', 'ventascrm.cotizacion.*', 'ventascrm.orden-de-venta.*', 'ventascrm.cuentas-corrientes.*', 'inventario.stock.ver'], []],
            ['Agente de contact center', 'contact_center', ['ventascrm.agente.ver', 'ventascrm.script.ver', 'ventascrm.cotizacion.ver', 'ventascrm.cotizacion.crear'], []],
            ['Supervisor de contact center', 'contact_center', ['ventascrm.*'], []],
            ['Bodeguero', 'bodega', ['inventario.*'], []],
            ['Comprador', 'compras', ['compras.*', 'inventario.recepcion-de-mercaderia.*', 'configuracion.proveedores.*'], []],
            ['Auditor', 'auditor', ['*.ver', '*.exportar'], []],
        ];

        $todos = Permiso::pluck('clave');
        $restringidos = config('erp.permisos_restringidos');

        foreach ($roles as [$nombre, $perfil, $patrones, $extra]) {
            $rol = Rol::updateOrCreate(['nombre' => $nombre], ['perfil_id' => Perfil::where('clave', $perfil)->value('id'), 'activo' => true]);
            if ($rol->wasRecentlyCreated) {
                $claves = $todos->filter(fn ($c) => ! in_array($c, $restringidos, true) && $this->coincide($c, $patrones))
                    ->merge($extra)->unique();
                $rol->permisos()->sync(Permiso::whereIn('clave', $claves)->pluck('id'));
            }
        }
    }

    private function coincide(string $clave, array $patrones): bool
    {
        foreach ($patrones as $p) {
            if (fnmatch($p, $clave)) {
                return true;
            }
        }

        return false;
    }
}
