<?php

namespace Tests\Unit;

use App\Support\Rut;
use PHPUnit\Framework\TestCase;

/** RUT chileno: módulo 11. (Archivo heredado del esqueleto; se mantiene el nombre.) */
class ExampleTest extends TestCase
{
    public function test_valida_rut_con_digito_verificador(): void
    {
        $this->assertTrue(Rut::valido('76.123.456-0'));
        $this->assertTrue(Rut::valido('77654321-7'));
        $this->assertTrue(Rut::valido('11111111-1'));
        $this->assertFalse(Rut::valido('77654321-K'));
        $this->assertFalse(Rut::valido('12345678-0'));
        $this->assertFalse(Rut::valido(''));
    }

    public function test_normaliza_y_formatea(): void
    {
        $this->assertSame('76123456-0', Rut::normalizar(' 76.123.456-0 '));
        $this->assertSame('76.123.456-0', Rut::formatear('761234560'));
    }
}
