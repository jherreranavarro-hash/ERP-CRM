<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Comercial\Models\Categoria;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Models\FolioRango;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Datos de demostración para desarrollo. Los RUT son ficticios (válidos en
 * módulo 11) hasta que se carguen los datos reales del emisor.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $obramax = Empresa::updateOrCreate(['rut' => '76123456-0'], [
            'razon_social' => 'Obramax SpA', 'nombre_fantasia' => 'Obramax', 'giro' => 'Venta al por mayor de materiales de construcción',
            'direccion' => 'Av. Ejemplo 1234', 'comuna' => 'Santiago', 'ciudad' => 'Santiago', 'activo' => true,
        ]);
        $santander = Empresa::updateOrCreate(['rut' => '77654321-7'], [
            'razon_social' => 'Obra Center Santander SpA', 'nombre_fantasia' => 'ObraCenter Santander', 'giro' => 'Ferretería',
            'comuna' => 'Santiago', 'ciudad' => 'Santiago', 'activo' => true,
        ]);

        $tenancy = app(Tenancy::class);
        $tenancy->como($obramax, function () {
            Local::firstOrCreate(['codigo' => 'MATRIZ'], ['nombre' => 'Casa Matriz', 'direccion' => 'Av. Ejemplo 1234', 'comuna' => 'Santiago']);
            Local::firstOrCreate(['codigo' => 'BOD-01'], ['nombre' => 'Bodega Central', 'comuna' => 'Quilicura', 'es_bodega' => true]);
        });
        $tenancy->como($santander, fn () => Local::firstOrCreate(['codigo' => 'STGO'], ['nombre' => 'Local Santiago', 'comuna' => 'Santiago']));

        $password = env('DEMO_PASSWORD') ?: Str::password(16);
        $admin = User::firstOrNew(['email' => 'admin@obramax.cl']);
        $nuevo = ! $admin->exists;
        $admin->fill(['name' => 'Administración Obramax', 'activo' => true]);
        if ($nuevo) {
            $admin->password = $password;
            $admin->password_changed_at = now();
        }
        $admin->es_superadmin = true;
        $admin->save();

        $rolAdmin = Rol::where('nombre', 'Administrador del sistema')->first();
        foreach ([$obramax, $santander] as $e) {
            $tenancy->como($e, fn () => UsuarioEmpresa::updateOrCreate(['user_id' => $admin->id, 'empresa_id' => $e->id], ['rol_id' => $rolAdmin->id, 'activo' => true]));
        }

        $cajera = User::firstOrNew(['email' => 'caja@obramax.cl']);
        if (! $cajera->exists) {
            $cajera->fill(['name' => 'María Cajera', 'activo' => true, 'password' => $password, 'password_changed_at' => now()])->save();
        }
        $tenancy->como($obramax, fn () => UsuarioEmpresa::updateOrCreate(['user_id' => $cajera->id, 'empresa_id' => $obramax->id], [
            'rol_id' => Rol::where('nombre', 'Cajera')->value('id'), 'activo' => true,
            'local_id' => Local::where('codigo', 'MATRIZ')->value('id'),
        ]));

        $this->facturacion($obramax, $admin);

        if ($nuevo && $this->command) {
            $this->command->warn("Usuarios demo: admin@obramax.cl y caja@obramax.cl · contraseña: {$password}");
        }
    }

    /** Facturación activa en Obramax con folios que siguen a los últimos vistos en Fenix (25-nov-2026). */
    private function facturacion(Empresa $empresa, User $admin): void
    {
        app(Tenancy::class)->como($empresa, function () use ($admin) {
            $p = app(\App\Modules\Core\Parametros\Parametros::class);
            if (! $p->valor('modulos.facturacion')) {
                $p->proponer('modulos.facturacion', true, 'empresa', 'Datos de demostración', $admin);
            }

            foreach ([33 => [1071, 2070], 39 => [1406, 3405], 61 => [254, 753], 56 => [1, 100], 52 => [1, 500], 34 => [1, 100]] as $tipo => [$desde, $hasta]) {
                FolioRango::firstOrCreate(['tipo_dte' => $tipo, 'desde' => $desde], ['hasta' => $hasta, 'siguiente' => $desde, 'caf_referencia' => 'DEMO (sin validez)']);
            }

            $cat = ['Obra gruesa' => Categoria::firstOrCreate(['nombre' => 'Obra gruesa']), 'Terminaciones' => Categoria::firstOrCreate(['nombre' => 'Terminaciones']), 'Ferretería' => Categoria::firstOrCreate(['nombre' => 'Ferretería'])];
            foreach ([
                ['CEM-25', 'Cemento especial 25 kg', 'Obra gruesa', 'SAC', '4990', '4500'],
                ['FIE-8', 'Fierro estriado 8 mm x 6 m', 'Obra gruesa', 'UN', '3290', '2990'],
                ['YES-15', 'Plancha yeso-cartón 15 mm', 'Terminaciones', 'UN', '8490', '7790'],
                ['PIN-1G', 'Pintura látex blanco 1 galón', 'Terminaciones', 'UN', '12990', '11490'],
                ['TOR-100', 'Tornillo volcanita 6x1 (caja 100)', 'Ferretería', 'CJ', '2490', '2190'],
            ] as [$sku, $nombre, $c, $u, $neto, $may]) {
                Producto::firstOrCreate(['sku' => $sku], ['nombre' => $nombre, 'categoria_id' => $cat[$c]->id, 'unidad' => $u, 'precio_neto' => $neto, 'precio_mayorista' => $may]);
            }

            Cliente::firstOrCreate(['rut' => '76543210-3'], ['razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción de edificios', 'direccion' => 'Av. Los Leones 100', 'comuna' => 'Providencia', 'ciudad' => 'Santiago', 'lista_precio' => 'mayorista', 'tiene_credito' => true, 'cupo_credito' => 5000000, 'plazo_dias' => 30]);
            Cliente::firstOrCreate(['rut' => '11111111-1'], ['razon_social' => 'Juan Pérez Soto', 'giro' => 'Particular', 'direccion' => 'Pasaje Uno 123', 'comuna' => 'Maipú', 'ciudad' => 'Santiago', 'lista_precio' => 'meson']);
        });
    }
}
