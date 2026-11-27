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
use Illuminate\Support\Facades\DB;
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

/*
 * Mantenedor "Retención de datos": aplica plazos y acción al vencer por empresa (la
 * retención legal suspende los borrados). Corre de madrugada, fuera del horario de uso.
 */
Artisan::command('datos:aplicar-retencion', function (\App\Modules\Operacion\Retencion $retencion) {
    foreach ($retencion->aplicar() as $r) {
        $this->line(sprintf('%-12s %-12s %6d  (empresa %s)', $r->categoria, $r->accion, $r->registros, $r->empresa_id ?? 'plataforma'));
    }
})->purpose('Aplica la política de retención de datos');

Schedule::command('datos:aplicar-retencion')->dailyAt('02:30')->onOneServer()->withoutOverlapping();

/*
 * Alertas periódicas: uso anómalo de API, folios por agotarse, integraciones caídas
 * y respaldos o pruebas de restauración atrasados.
 */
Artisan::command('operacion:vigilar', function (\App\Modules\Operacion\Vigilancia $vigilancia) {
    $this->info('Alertas emitidas: '.count($vigilancia->revisar()));
})->purpose('Revisa API, folios, integraciones y respaldos, y emite alertas');

Schedule::command('operacion:vigilar')->hourly()->onOneServer()->withoutOverlapping();

/*
 * Lo llaman los scripts del servidor (deploy/respaldo.sh y deploy/probar-restauracion.sh)
 * para dejar constancia en el panel "Respaldos y continuidad". Un fallo alerta al tiro.
 */
Artisan::command('respaldos:registrar {tipo : respaldo o restauracion} {resultado : ok o fallo}
    {--archivo=} {--bytes=} {--duracion=} {--detalle=}', function (\App\Modules\Operacion\Alertas $alertas, string $tipo, string $resultado) {
    if (! in_array($tipo, ['respaldo', 'restauracion'], true) || ! in_array($resultado, ['ok', 'fallo'], true)) {
        $this->error('Uso: respaldos:registrar respaldo|restauracion ok|fallo');

        return 1;
    }
    $r = \App\Modules\Operacion\Models\Respaldo::create([
        'tipo' => $tipo, 'resultado' => $resultado,
        'archivo' => $this->option('archivo') ? mb_substr($this->option('archivo'), 0, 255) : null,
        'bytes' => $this->option('bytes') !== null ? (int) $this->option('bytes') : null,
        'duracion_s' => $this->option('duracion') !== null ? (int) $this->option('duracion') : null,
        'detalle' => $this->option('detalle') ? mb_substr($this->option('detalle'), 0, 1000) : null,
        'servidor' => gethostname() ?: null,
    ]);
    if ($resultado === 'fallo') {
        $alertas->emitir($tipo === 'respaldo' ? 'respaldo_fallido' : 'restauracion_fallida',
            $tipo === 'respaldo' ? 'Falló el respaldo de la base de datos' : 'Falló la prueba de restauración',
            ['detalle' => $r->detalle, 'servidor' => $r->servidor], "respaldo:{$r->id}", global: true, ventanaHoras: 0);
    }
    $this->info("Registrado #{$r->id}");

    return 0;
})->purpose('Registra el resultado de un respaldo o de una prueba de restauración');

/*
 * Alta del primer SuperAdmin o recuperación de acceso. Solo desde el servidor (quien
 * tiene shell ya tiene la base): queda en la auditoría. Para el resto, la marca se
 * otorga con cuatro ojos desde Configuración → Usuarios.
 */
Artisan::command('usuarios:superadmin {email}', function (Auditoria $auditoria, string $email) {
    $u = User::where('email', \Illuminate\Support\Str::lower($email))->first();
    if (! $u) {
        $this->error('No existe un usuario con ese correo.');

        return 1;
    }
    if (! $this->confirm("¿Otorgar SuperAdmin a {$u->name}?", false)) {
        return 1;
    }
    $u->forceFill(['es_superadmin' => true])->save();
    $auditoria->registrar('usuario.superadmin_otorgado', $u, ['es_superadmin' => false], ['es_superadmin' => true], 'Otorgado desde la consola del servidor', global: true);
    $this->info('Listo. El usuario debe activar 2FA en su primer ingreso.');

    return 0;
})->purpose('Otorga SuperAdmin desde el servidor (primer administrador o recuperación)');

/*
 * Lista de verificación previa a abrir producción (docs/despliegue.md). Revisa lo que
 * el prompt exige "por código": debug apagado, rol de base sin privilegios, RLS en
 * cada tabla con empresa_id, cookies seguras, colas reales y adaptadores simulados.
 * Termina con código 1 si hay errores, para usarlo en el pipeline de despliegue.
 */
Artisan::command('sistema:verificar-produccion', function () {
    $filas = [];
    $chk = function (string $nivel, string $item, string $detalle = '') use (&$filas) {
        $filas[] = [$nivel, $item, $detalle];
    };
    $ok = fn (bool $c, string $item, string $falla, string $nivel = 'ERROR') => $chk($c ? 'OK' : $nivel, $item, $c ? '' : $falla);

    $ok(app()->isProduction(), 'APP_ENV=production', 'APP_ENV es '.app()->environment());
    $ok(! config('app.debug'), 'APP_DEBUG apagado', 'APP_DEBUG=true');
    $ok((bool) config('app.key'), 'APP_KEY definida', 'Falta APP_KEY (php artisan key:generate una sola vez y guárdala en el gestor de secretos)');
    $ok(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL con https', 'APP_URL='.config('app.url'));
    $ok((bool) config('session.secure'), 'Cookie de sesión solo por https', 'SESSION_SECURE_COOKIE=false');
    $ok((bool) config('session.encrypt'), 'Sesión cifrada', 'SESSION_ENCRYPT=false');
    $ok(config('queue.default') !== 'sync', 'Cola asíncrona', 'QUEUE_CONNECTION=sync');
    $ok(! in_array(config('cache.default'), ['array', 'file'], true), 'Caché compartida (Redis)', 'CACHE_STORE='.config('cache.default'), 'AVISO');
    $ok(! in_array(config('mail.default'), ['log', 'array'], true), 'Correo real', 'MAIL_MAILER='.config('mail.default'));
    $ok((bool) config('app.config_export_key'), 'Clave de firma para exportar configuración', 'Falta CONFIG_EXPORT_KEY', 'AVISO');
    $ok(config('broadcasting.default') === 'reverb', 'Tiempo real (Reverb)', 'BROADCAST_CONNECTION='.config('broadcasting.default'), 'AVISO');
    $ok(is_file(public_path('build/manifest.json')), 'Frontend compilado', 'Falta npm run build');

    try {
        $rol = DB::selectOne('select current_user as u, rolsuper, rolbypassrls from pg_roles where rolname = current_user');
        $ok(! $rol->rolsuper && ! $rol->rolbypassrls, "Rol de base sin privilegios ({$rol->u})", 'El rol de la app es superusuario o BYPASSRLS: RLS no aplica');
        $ok($rol->u !== config('database.connections.pgsql_owner.username'), 'La app no usa el rol dueño', 'DB_USERNAME es el dueño del esquema');
        $sinRls = collect(DB::select("select c.relname from pg_class c join pg_attribute a on a.attrelid = c.oid
            where a.attname = 'empresa_id' and c.relkind = 'r' and c.relnamespace = 'public'::regnamespace and not c.relrowsecurity
            and c.relname not in ('api_registros', 'trazabilidad', 'usuario_empresa', 'invitaciones', 'personal_access_tokens')"))->pluck('relname');
        $ok($sinRls->isEmpty(), 'RLS en todas las tablas con empresa_id', 'Sin RLS: '.$sinRls->implode(', '));
        $ok(User::where('es_superadmin', true)->where('activo', true)->whereNotNull('two_factor_confirmed_at')->exists(), 'Al menos un SuperAdmin con 2FA', 'Crea el primero con usuarios:superadmin y activa 2FA', 'AVISO');
        $ultimo = \App\Modules\Operacion\Models\Respaldo::where('tipo', 'respaldo')->where('resultado', 'ok')->latest('id')->first();
        $ok((bool) $ultimo, 'Respaldo registrado', 'Aún no hay respaldos registrados (deploy/respaldo.sh)', 'AVISO');
    } catch (\Throwable $e) {
        $chk('ERROR', 'Conexión a PostgreSQL', $e->getMessage());
    }

    foreach (config('integraciones.adaptadores') as $tipo => $clase) {
        $ok(! str_contains((string) $clase, 'Simulad'), "Integración {$tipo} real", "Usa {$clase}", 'AVISO');
    }

    $this->table(['Estado', 'Verificación', 'Detalle'], $filas);
    $errores = collect($filas)->where(0, 'ERROR')->count();
    $avisos = collect($filas)->where(0, 'AVISO')->count();
    $errores ? $this->error("{$errores} error(es), {$avisos} aviso(s).") : $this->info("Sin errores. {$avisos} aviso(s).");

    return $errores ? 1 : 0;
})->purpose('Revisa la configuración antes de abrir producción');

/* Días de retención de respaldos (mantenedor "Respaldos y continuidad"), para deploy/respaldo.sh. */
Artisan::command('respaldos:retencion-dias', function (Parametros $parametros) {
    $this->line((string) (int) $parametros->valor('respaldos.retencion_dias'));
})->purpose('Imprime los días de retención de respaldos');

/*
 * Instalación inicial en producción: primera empresa y primer SuperAdmin (con su
 * membresía). La contraseña se muestra una sola vez y queda vencida, así que el
 * sistema pide cambiarla (y activar 2FA) en el primer ingreso. Solo corre si aún
 * no hay ninguna empresa.
 */
Artisan::command('sistema:instalar {--rut=} {--razon-social=} {--nombre-fantasia=} {--admin-nombre=} {--admin-email=}', function (Auditoria $auditoria, Tenancy $tenancy) {
    if (Empresa::query()->exists()) {
        $this->error('Ya hay empresas creadas: la instalación inicial no se repite. Usa la aplicación o usuarios:superadmin.');

        return 1;
    }
    $rut = \App\Support\Rut::normalizar($this->option('rut') ?: $this->ask('RUT de la empresa'));
    if (! \App\Support\Rut::valido($rut)) {
        $this->error('RUT inválido.');

        return 1;
    }
    $razon = $this->option('razon-social') ?: $this->ask('Razón social');
    $fantasia = $this->option('nombre-fantasia') ?: $this->ask('Nombre de fantasía', $razon);
    $nombre = $this->option('admin-nombre') ?: $this->ask('Nombre del administrador');
    $email = \Illuminate\Support\Str::lower($this->option('admin-email') ?: $this->ask('Correo del administrador'));
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Correo inválido.');

        return 1;
    }

    $clave = \Illuminate\Support\Str::password(20);
    DB::transaction(function () use ($rut, $razon, $fantasia, $nombre, $email, $clave, $auditoria, $tenancy) {
        $empresa = Empresa::create(['rut' => $rut, 'razon_social' => $razon, 'nombre_fantasia' => $fantasia, 'activo' => true]);
        $u = User::create(['name' => $nombre, 'email' => $email, 'password' => $clave, 'activo' => true]);
        // Contraseña vencida desde el inicio: se cambia en el primer ingreso.
        $u->forceFill(['es_superadmin' => true, 'password_changed_at' => now()->subYears(5)])->save();
        $tenancy->como($empresa, fn () => \App\Modules\Core\Models\UsuarioEmpresa::create([
            'user_id' => $u->id, 'empresa_id' => $empresa->id, 'activo' => true,
            'rol_id' => \App\Modules\Core\Models\Rol::where('nombre', 'Administrador del sistema')->value('id'),
        ]));
        $auditoria->registrar('sistema.instalado', $empresa, null, ['admin' => $email], 'Instalación inicial desde la consola', userId: $u->id, global: true);
    });

    $this->info("Empresa {$fantasia} y SuperAdmin {$email} creados.");
    $this->warn("Contraseña temporal (se muestra solo esta vez): {$clave}");
    $this->line('Al ingresar se pide cambiarla y activar la verificación en dos pasos.');

    return 0;
})->purpose('Crea la primera empresa y el primer SuperAdmin en una instalación nueva');
