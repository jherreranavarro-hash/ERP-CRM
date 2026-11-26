<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Tenancy\Tenancy;
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

        if ($nuevo && $this->command) {
            $this->command->warn("Usuarios demo: admin@obramax.cl y caja@obramax.cl · contraseña: {$password}");
        }
    }
}
