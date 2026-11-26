<?php

namespace Database\Seeders;

use App\Modules\Core\Rbac\Rbac;
use Illuminate\Database\Seeder;

class PermisosSeeder extends Seeder
{
    public function run(): void
    {
        Rbac::sincronizarPermisos();
    }
}
