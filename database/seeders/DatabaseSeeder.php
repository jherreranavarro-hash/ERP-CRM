<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Datos base (idempotentes). La demo solo corre fuera de producción. */
    public function run(): void
    {
        $this->call([PerfilesSeeder::class, PermisosSeeder::class, RolesSeeder::class]);

        if (! app()->isProduction()) {
            $this->call(DemoSeeder::class);
        }
    }
}
