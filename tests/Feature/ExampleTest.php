<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Archivo heredado del esqueleto: comprobación mínima de que la app responde. */
class ExampleTest extends TestCase
{
    public function test_la_raiz_pide_iniciar_sesion(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
