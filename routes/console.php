<?php

use App\Models\User;
use App\Modules\Auth\Sesiones;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Jobs\EnviarDocumentoSii;
use App\Modules\Facturacion\Models\Documento;
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

/*
 * Reintenta el envío al proveedor DTE de los documentos emitidos que quedaron con
 * estado SII "error" o "pendiente" por más de 10 minutos (p. ej. proveedor caído).
 * Recorre todas las empresas: cada una con su empresa fijada (RLS).
 */
Artisan::command('dte:reintentar', function (Tenancy $tenancy) {
    $n = 0;
    foreach (Empresa::where('activo', true)->get() as $empresa) {
        $tenancy->como($empresa, function () use ($empresa, &$n) {
            Documento::query()->whereIn('estado_sii', ['error', 'pendiente'])->where('updated_at', '<', now()->subMinutes(10))
                ->where('sii_intentos', '<', 20)->orderBy('id')->limit(500)->pluck('id')
                ->each(function ($id) use ($empresa, &$n) {
                    EnviarDocumentoSii::dispatch($empresa->id, $id);
                    $n++;
                });
        });
    }
    $this->info("Documentos reencolados: {$n}");
})->purpose('Reencola el envío al SII de documentos con error');

Schedule::command('dte:reintentar')->everyFifteenMinutes()->onOneServer()->withoutOverlapping();

/*
 * Integraciones sin webhook: Meta Ads (métricas y prospectos), bandeja de LinkedIn y
 * estado de las plantillas de WhatsApp en revisión. Una tarea en cola por empresa con
 * la integración activa; el trabajo es único por empresa, así no se solapa.
 */
Artisan::command('integraciones:sincronizar {tipo : meta_ads, linkedin o plantillas}', function (Tenancy $tenancy, string $tipo) {
    $n = 0;
    foreach (Empresa::where('activo', true)->get() as $empresa) {
        $tenancy->como($empresa, function () use ($empresa, $tipo, &$n) {
            $integracion = $tipo === 'plantillas' ? 'whatsapp' : $tipo;
            if (! \App\Modules\Integraciones\Models\Integracion::activa($integracion)) {
                return;
            }
            match ($tipo) {
                'meta_ads' => \App\Modules\Marketing\Trabajos\SincronizarMetaAds::dispatch($empresa->id),
                'linkedin' => \App\Modules\Mensajeria\Trabajos\SincronizarLinkedin::dispatch($empresa->id),
                'plantillas' => app(\App\Modules\Mensajeria\Plantillas::class)->actualizarPendientes(),
            };
            $n++;
        });
    }
    $this->info("Empresas procesadas: {$n}");
})->purpose('Sincroniza integraciones sin webhook (Meta Ads, LinkedIn, plantillas de WhatsApp)');

Schedule::command('integraciones:sincronizar meta_ads')->hourly()->onOneServer();
Schedule::command('integraciones:sincronizar linkedin')->everyFiveMinutes()->onOneServer();
Schedule::command('integraciones:sincronizar plantillas')->everyFifteenMinutes()->onOneServer();
