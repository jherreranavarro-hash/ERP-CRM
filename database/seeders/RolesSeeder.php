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
            ['Analista de Finanzas', 'finanzas', ['dashboard.*', 'finanzas.*', 'caja.cierres-de-caja.*', 'configuracion.cuentas-corrientes.*', 'configuracion.folios.*', 'compras.factura-de-proveedores.*'], ['configuracion.parametros.ver', 'configuracion.parametros.proponer', 'configuracion.clientes.ver', 'configuracion.proveedores.ver', 'compras.orden-de-compra.ver']],
            ['Analista de RRHH', 'rrhh', ['rrhh.*'], ['configuracion.parametros.ver', 'configuracion.parametros.proponer']],
            ['Cajera', 'caja', ['caja.*', '!caja.*.aprobar'], ['ventascrm.cotizacion.ver', 'configuracion.clientes.ver', 'configuracion.clientes.crear', 'configuracion.producto.ver']],
            ['Ejecutiva comercial', 'ventas', ['dashboard.*', 'ventascrm.dashboard.*', 'ventascrm.cotizacion.*', 'ventascrm.orden-de-venta.*', 'ventascrm.cuentas-corrientes.*', 'inventario.stock.ver'], ['caja.facturas-de-ventas.ver', 'caja.facturas-de-ventas.crear', 'caja.facturas-de-ventas.editar', 'configuracion.clientes.ver', 'configuracion.producto.ver']],
            ['Agente de contact center', 'contact_center', ['ventascrm.agente.ver', 'ventascrm.script.ver', 'ventascrm.cotizacion.ver', 'ventascrm.cotizacion.crear'], []],
            ['Supervisor de contact center', 'contact_center', ['ventascrm.*', 'configuracion.proveedor-ip.*', 'configuracion.numero-entrante.*', 'configuracion.ivr.*', 'configuracion.lista-negra.*'], ['ventascrm.grabaciones.escuchar']],
            ['Bodeguero', 'bodega', ['inventario.*'], []],
            ['Comprador', 'compras', ['compras.*', 'inventario.recepcion-de-mercaderia.*', 'configuracion.proveedores.*'], ['configuracion.producto.ver', 'inventario.stock.ver']],
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

    /** Patrones fnmatch; los que empiezan con "!" excluyen (p. ej. la cajera no aprueba). */
    private function coincide(string $clave, array $patrones): bool
    {
        $si = false;
        foreach ($patrones as $p) {
            if (str_starts_with($p, '!')) {
                if (fnmatch(substr($p, 1), $clave)) {
                    return false;
                }
            } elseif (fnmatch($p, $clave)) {
                $si = true;
            }
        }

        return $si;
    }
}
