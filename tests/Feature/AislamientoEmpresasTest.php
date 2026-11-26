<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RLS en PostgreSQL: una empresa nunca ve ni escribe datos de otra. */
class AislamientoEmpresasTest extends TestCase
{
    public function test_cada_empresa_ve_solo_sus_locales_incluso_sin_scope_de_eloquent(): void
    {
        $a = $this->empresa();
        $b = $this->empresa();
        $t = app(Tenancy::class);
        $t->como($a, fn () => Local::create(['codigo' => 'A1', 'nombre' => 'Local A1']));
        $t->como($a, fn () => Local::create(['codigo' => 'A2', 'nombre' => 'Local A2']));
        $t->como($b, fn () => Local::create(['codigo' => 'B1', 'nombre' => 'Local B1']));

        $t->establecer($a);
        $this->assertSame(2, DB::table('locales')->count(), 'SQL crudo también filtra por RLS');
        $this->assertEqualsCanonicalizing(['A1', 'A2'], Local::withoutGlobalScopes()->pluck('codigo')->all());

        $t->establecer($b);
        $this->assertSame(['B1'], DB::table('locales')->pluck('codigo')->all());
    }

    public function test_sin_empresa_activa_no_se_ve_nada(): void
    {
        $a = $this->empresa();
        app(Tenancy::class)->como($a, fn () => Local::create(['codigo' => 'X', 'nombre' => 'X']));

        $this->assertSame(0, DB::table('locales')->count());
    }

    public function test_no_se_puede_insertar_en_otra_empresa(): void
    {
        $a = $this->empresa();
        $b = $this->empresa();
        app(Tenancy::class)->establecer($a);

        $e = $this->esperarRechazoBd(fn () => DB::table('locales')->insert([
            'empresa_id' => $b->id, 'codigo' => 'INTRUSO', 'nombre' => 'Intruso', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertStringContainsString('row-level security', $e->getMessage());
    }

    public function test_el_usuario_no_puede_activar_una_empresa_ajena(): void
    {
        $propia = $this->empresa();
        $ajena = $this->empresa();
        $u = $this->usuario($propia, 'Ejecutiva comercial');

        $this->entrar($u)->post('/empresa-activa', ['empresa_id' => $ajena->id]);

        $this->entrar($u)->get('/')->assertOk()
            ->assertInertia(fn ($p) => $p->where('empresa.actual.id', $propia->id));
    }

    public function test_las_rutas_con_modelo_resuelven_dentro_de_la_empresa_activa(): void
    {
        $a = $this->empresa();
        $b = $this->empresa();
        $u = $this->usuario($a, 'Administrador del sistema', superadmin: true);
        $propio = app(Tenancy::class)->como($a, fn () => Local::create(['codigo' => 'P1', 'nombre' => 'Propio']));
        $ajeno = app(Tenancy::class)->como($b, fn () => Local::create(['codigo' => 'X1', 'nombre' => 'Ajeno']));

        $this->entrar($u)->put("/configuracion/locales/{$propio->id}", ['codigo' => 'P1', 'nombre' => 'Renombrado'])->assertSessionHasNoErrors()->assertRedirect();
        $this->entrar($u)->put("/configuracion/locales/{$ajeno->id}", ['codigo' => 'X1', 'nombre' => 'Hackeado'])->assertNotFound();

        app(Tenancy::class)->establecer($a);
        $this->assertSame('Renombrado', $propio->fresh()->nombre);
    }
}
