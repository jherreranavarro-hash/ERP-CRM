<?php

namespace Tests;

use App\Models\User;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Tenancy\Tenancy;
use Database\Seeders\PerfilesSeeder;
use Database\Seeders\PermisosSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Las migraciones corren una vez por proceso con el rol dueño (pgsql_owner),
 * igual que en producción. Cada test corre como erp_app (sujeto a RLS)
 * dentro de una transacción que se revierte al final.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    private static bool $esquemaListo = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$esquemaListo) {
            DB::rollBack(); // salir de la transacción del test mientras se prepara el esquema
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_owner', '--force' => true]);
            foreach ([PerfilesSeeder::class, PermisosSeeder::class, RolesSeeder::class] as $s) {
                Artisan::call('db:seed', ['--database' => 'pgsql_owner', '--class' => $s, '--force' => true]);
            }
            DB::purge('pgsql_owner');
            DB::beginTransaction();
            self::$esquemaListo = true;
        }

        app(Tenancy::class)->limpiar();
    }

    protected function empresa(array $attrs = []): Empresa
    {
        return Empresa::factory()->create($attrs);
    }

    /** Usuario activo con 2FA confirmado y una membresía con el rol dado. */
    protected function usuario(?Empresa $empresa = null, string $rol = 'Cajera', bool $superadmin = false, bool $con2fa = true): User
    {
        $u = User::factory()->create();
        $u->forceFill(['es_superadmin' => $superadmin])->save();

        if ($con2fa) {
            $u->forceFill([
                'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
                'two_factor_recovery_codes' => encrypt(json_encode(['codigo-1'])),
                'two_factor_confirmed_at' => now(),
            ])->save();
        }

        if ($empresa) {
            app(Tenancy::class)->como($empresa, fn () => UsuarioEmpresa::create([
                'user_id' => $u->id, 'empresa_id' => $empresa->id,
                'rol_id' => Rol::where('nombre', $rol)->value('id'), 'activo' => true,
            ]));
        }

        return $u->fresh();
    }

    /** Sesión autenticada como la que deja un login real (token de sesión registrado). */
    protected function entrar(User $u): static
    {
        $token = 'sesion-de-prueba-'.$u->id;
        \Illuminate\Support\Facades\Cache::put('sesiones:usuario:'.$u->id, [$token], now()->addDay());

        return $this->actingAs($u)->withSession(['sesion.token' => $token, 'sesion.inicio' => now()->timestamp, 'sesion.actividad' => now()->timestamp]);
    }

    /** Ejecuta $fn en un savepoint y exige que la base de datos la rechace. */
    protected function esperarRechazoBd(callable $fn): QueryException
    {
        try {
            DB::transaction($fn);
        } catch (QueryException $e) {
            $this->addToAssertionCount(1);

            return $e;
        }
        $this->fail('La base de datos debía rechazar la operación.');
    }
}
