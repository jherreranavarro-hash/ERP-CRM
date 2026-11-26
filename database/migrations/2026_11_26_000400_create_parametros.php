<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mantenedores de configuración: cada fila es una versión propuesta o aprobada
 * de un parámetro, con alcance global → empresa → local, vigencia e historial.
 * Las definiciones (tipo, rango, piso, valor inicial) viven en código:
 * App\Modules\Core\Parametros\Catalogo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametro_cambios', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 100);
            $table->foreignId('empresa_id')->nullable()->constrained('empresas');
            $table->foreignId('local_id')->nullable()->constrained('locales');
            $table->jsonb('valor');
            $table->timestamp('vigente_desde');
            $table->string('estado', 15); // propuesto | aprobado | rechazado
            $table->string('motivo', 500);
            $table->foreignId('propuesto_por')->constrained('users');
            $table->foreignId('resuelto_por')->nullable()->constrained('users');
            $table->string('comentario_resolucion', 500)->nullable();
            // true solo en mantenedores no sensibles: el cambio rige sin segundo aprobador.
            $table->boolean('auto_aprobado')->default(false);
            $table->timestamp('resuelto_at')->nullable();
            $table->foreignId('revierte_a')->nullable()->constrained('parametro_cambios');
            $table->timestamps();
            $table->index(['clave', 'estado', 'vigente_desde']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE parametro_cambios ADD CONSTRAINT parametro_cambios_estado_chk
                CHECK (estado IN ('propuesto', 'aprobado', 'rechazado'));
            ALTER TABLE parametro_cambios ADD CONSTRAINT parametro_cambios_local_requiere_empresa
                CHECK (local_id IS NULL OR empresa_id IS NOT NULL);
            -- Cuatro ojos: nadie resuelve su propia propuesta.
            ALTER TABLE parametro_cambios ADD CONSTRAINT parametro_cambios_cuatro_ojos
                CHECK (resuelto_por IS NULL OR resuelto_por <> propuesto_por OR auto_aprobado);

            ALTER TABLE parametro_cambios ENABLE ROW LEVEL SECURITY;
            CREATE POLICY parametro_cambios_tenant ON parametro_cambios
                USING (empresa_id IS NULL OR empresa_id = app_empresa_id())
                WITH CHECK (empresa_id IS NULL OR empresa_id = app_empresa_id());
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('parametro_cambios');
    }
};
