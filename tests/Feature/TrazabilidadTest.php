<?php

namespace Tests\Feature;

use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Trazabilidad;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** La trazabilidad es de solo inserción: ni la app ni un SuperAdmin la modifican. */
class TrazabilidadTest extends TestCase
{
    public function test_no_se_puede_editar_ni_borrar_desde_sql(): void
    {
        $r = app(Auditoria::class)->registrar('prueba', global: true);

        $this->esperarRechazoBd(fn () => DB::table('trazabilidad')->where('id', $r->id)->update(['accion' => 'alterada']));
        $this->esperarRechazoBd(fn () => DB::table('trazabilidad')->where('id', $r->id)->delete());
        $this->esperarRechazoBd(fn () => DB::statement('truncate trazabilidad'));

        $this->assertSame('prueba', DB::table('trazabilidad')->where('id', $r->id)->value('accion'));
    }

    public function test_el_modelo_tampoco_permite_editar(): void
    {
        $r = app(Auditoria::class)->registrar('prueba', global: true);

        $this->expectException(\LogicException::class);
        $r->update(['accion' => 'alterada']);
    }

    public function test_no_guarda_secretos(): void
    {
        $r = app(Auditoria::class)->registrar('prueba', null, null, ['password' => 'x', 'two_factor_secret' => 'y', 'nombre' => 'ok'], global: true);

        $this->assertSame(['nombre' => 'ok'], $r->fresh()->valor_nuevo);
    }

    public function test_crear_un_registro_auditable_deja_rastro(): void
    {
        $antes = Trazabilidad::count();
        $this->empresa();

        $this->assertSame($antes + 1, Trazabilidad::count());
        $this->assertSame('crear', Trazabilidad::latest('id')->value('accion'));
    }
}
