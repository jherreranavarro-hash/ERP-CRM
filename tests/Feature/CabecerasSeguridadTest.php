<?php

namespace Tests\Feature;

use Tests\TestCase;

class CabecerasSeguridadTest extends TestCase
{
    public function test_las_respuestas_llevan_cabeceras_de_seguridad(): void
    {
        $r = $this->get('/login');

        $r->assertHeader('X-Frame-Options', 'DENY');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("frame-ancestors 'none'", $r->headers->get('Content-Security-Policy'));
        $this->assertMatchesRegularExpression("/script-src[^;]*'nonce-[A-Za-z0-9+\/=]+'/", $r->headers->get('Content-Security-Policy'));
        $this->assertFalse($r->headers->has('X-Powered-By'));
    }

    public function test_los_errores_no_exponen_detalles(): void
    {
        $this->get('/no-existe')->assertNotFound()->assertDontSee('vendor/laravel');
    }
}
