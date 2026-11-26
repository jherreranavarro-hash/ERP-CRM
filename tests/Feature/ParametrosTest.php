<?php

namespace Tests\Feature;

use App\Modules\Core\Models\ParametroCambio;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ParametrosTest extends TestCase
{
    private Parametros $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = app(Parametros::class);
    }

    public function test_sin_cambios_rige_el_valor_inicial(): void
    {
        $this->assertSame(30, $this->p->valor('sesiones.inactividad_minutos'));
        $this->assertSame([1, 5, 30], $this->p->valor('login.escalones_bloqueo'));
    }

    public function test_ningun_valor_baja_del_piso(): void
    {
        $admin = $this->usuario(superadmin: true);

        $this->expectException(ValidationException::class);
        $this->p->proponer('sesiones.inactividad_minutos', 240, 'global', 'Quiero sesiones largas', $admin);
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $this->expectException(ValidationException::class);
        $this->p->proponer('sesiones.inactividad_minutos', 20, 'global', '  ', $this->usuario(superadmin: true));
    }

    public function test_la_empresa_hereda_del_global_y_puede_ser_mas_estricta(): void
    {
        $a = $this->empresa();
        $b = $this->empresa();
        $admin = $this->usuario(superadmin: true);
        $t = app(Tenancy::class);

        $t->establecer($a);
        $this->p->proponer('sesiones.inactividad_minutos', 10, 'empresa', 'Caja compartida', $admin);
        $this->assertSame(10, $this->p->valor('sesiones.inactividad_minutos'));

        $t->establecer($b);
        $this->assertSame(30, $this->p->valor('sesiones.inactividad_minutos'), 'La otra empresa sigue con el global');
    }

    public function test_una_empresa_no_puede_ser_mas_laxa_que_el_global(): void
    {
        $admin = $this->usuario(superadmin: true);
        $this->p->proponer('sesiones.inactividad_minutos', 20, 'global', 'Endurecer', $admin);
        app(Tenancy::class)->establecer($this->empresa());

        $this->expectException(ValidationException::class);
        $this->p->proponer('sesiones.inactividad_minutos', 45, 'empresa', 'Más laxo', $admin);
    }

    public function test_cambio_sensible_requiere_a_otra_persona(): void
    {
        $a = $this->usuario(superadmin: true);
        $b = $this->usuario(superadmin: true);

        $cambio = $this->p->proponer('login.intentos_maximos', 3, 'global', 'Endurecer login', $a);
        $this->assertSame('propuesto', $cambio->estado);
        $this->assertSame(5, $this->p->valor('login.intentos_maximos'), 'No rige hasta aprobarse');

        try {
            $this->p->aprobar($cambio, $a);
            $this->fail('Nadie aprueba su propio cambio');
        } catch (ValidationException) {
        }

        $this->p->aprobar($cambio->fresh(), $b, 'Ok');
        $this->assertSame(3, $this->p->valor('login.intentos_maximos'));
    }

    public function test_la_base_de_datos_tambien_impide_la_autoaprobacion(): void
    {
        $a = $this->usuario(superadmin: true);
        $cambio = $this->p->proponer('login.intentos_maximos', 4, 'global', 'Prueba', $a);

        $this->esperarRechazoBd(fn () => ParametroCambio::query()->whereKey($cambio->id)
            ->update(['estado' => 'aprobado', 'resuelto_por' => $a->id, 'resuelto_at' => now()]));
    }

    public function test_un_cambio_programado_no_rige_antes_de_su_fecha(): void
    {
        $admin = $this->usuario(superadmin: true);
        $this->p->proponer('sesiones.inactividad_minutos', 15, 'global', 'Desde mañana', $admin, vigenteDesde: now()->addDay());

        $this->assertSame(30, $this->p->valor('sesiones.inactividad_minutos'));
        $this->travel(25)->hours();
        $this->p->invalidar();
        $this->assertSame(15, $this->p->valor('sesiones.inactividad_minutos'));
    }

    public function test_exportar_e_importar_exige_firma_valida(): void
    {
        $admin = $this->usuario(superadmin: true);
        $this->p->proponer('sesiones.inactividad_minutos', 20, 'global', 'Base', $admin);
        $json = $this->p->exportar();
        $this->assertSame(0, $this->p->importar($json, $admin, 'Mismo archivo, sin cambios'));

        $json['parametros'][0]['valor'] = 60;
        $this->expectException(ValidationException::class);
        $this->p->importar($json, $admin, 'Copia desde staging');
    }
}
