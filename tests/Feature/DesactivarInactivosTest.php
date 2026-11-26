<?php

namespace Tests\Feature;

use Tests\TestCase;

class DesactivarInactivosTest extends TestCase
{
    public function test_desactiva_solo_a_quienes_superan_el_plazo(): void
    {
        $e = $this->empresa();
        $viejo = $this->usuario($e, 'Bodeguero', con2fa: false);
        $viejo->forceFill(['ultimo_acceso_at' => now()->subDays(120)])->saveQuietly();
        $reciente = $this->usuario($e, 'Bodeguero', con2fa: false);
        $reciente->forceFill(['ultimo_acceso_at' => now()->subDays(10)])->saveQuietly();
        $admin = $this->usuario(superadmin: true);
        $admin->forceFill(['ultimo_acceso_at' => now()->subDays(400)])->saveQuietly();

        $this->artisan('usuarios:desactivar-inactivos')->assertSuccessful();

        $this->assertFalse($viejo->fresh()->activo);
        $this->assertTrue($reciente->fresh()->activo);
        $this->assertTrue($admin->fresh()->activo);
        $this->assertDatabaseHas('trazabilidad', ['accion' => 'usuario.desactivado_por_inactividad', 'model_id' => $viejo->id]);
    }
}
