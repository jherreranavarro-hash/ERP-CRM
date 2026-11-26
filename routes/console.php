<?php

use App\Models\User;
use App\Modules\Auth\Sesiones;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
 * Mantenedor "Usuarios": desactiva cuentas sin ingreso en N días (piso: 15 a 365).
 * Los SuperAdmin quedan fuera para no dejar el sistema sin administración.
 */
Artisan::command('usuarios:desactivar-inactivos', function (Parametros $parametros, Auditoria $auditoria, Sesiones $sesiones) {
    $dias = (int) $parametros->valor('usuarios.desactivar_inactivos_dias');
    $limite = now()->subDays($dias);

    $n = 0;
    User::query()
        ->where('activo', true)
        ->where('es_superadmin', false)
        ->where(fn ($q) => $q->where('ultimo_acceso_at', '<', $limite)
            ->orWhere(fn ($q) => $q->whereNull('ultimo_acceso_at')->where('created_at', '<', $limite)))
        ->each(function (User $u) use ($auditoria, $sesiones, $dias, &$n) {
            $u->forceFill(['activo' => false])->saveQuietly();
            $sesiones->cerrarTodas($u);
            $auditoria->registrar('usuario.desactivado_por_inactividad', $u, ['activo' => true], ['activo' => false], "Sin ingreso en {$dias} días", global: true);
            $n++;
        });

    $this->info("Usuarios desactivados: {$n}");
})->purpose('Desactiva usuarios sin ingreso según el mantenedor de Usuarios');

Schedule::command('usuarios:desactivar-inactivos')->dailyAt('03:00')->onOneServer();
