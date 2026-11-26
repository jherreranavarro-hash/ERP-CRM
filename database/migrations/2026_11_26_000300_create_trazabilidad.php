<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoría inmutable (append-only). Tres barreras:
 *  1. El rol de la aplicación no tiene UPDATE, DELETE ni TRUNCATE sobre la tabla.
 *  2. Un trigger rechaza UPDATE y DELETE incluso para el dueño del esquema.
 *  3. El modelo Eloquent lanza excepción si se intenta modificar o borrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trazabilidad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('accion', 60);
            $table->string('model_type', 120)->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->jsonb('valor_anterior')->nullable();
            $table->jsonb('valor_nuevo')->nullable();
            $table->string('motivo', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('canal', 20)->default('web'); // web | api | consola | cola
            $table->timestamp('created_at')->useCurrent();
            $table->index(['model_type', 'model_id']);
            $table->index(['empresa_id', 'created_at']);
            $table->index('accion');
        });

        $app = config('database.connections.pgsql.username');

        DB::unprepared(<<<SQL
            REVOKE UPDATE, DELETE, TRUNCATE ON trazabilidad FROM "{$app}";

            CREATE OR REPLACE FUNCTION trazabilidad_solo_insercion() RETURNS trigger
                LANGUAGE plpgsql AS \$\$
            BEGIN
                RAISE EXCEPTION 'La trazabilidad es de solo inserción (% bloqueado)', TG_OP;
            END \$\$;

            CREATE TRIGGER trazabilidad_inmutable
                BEFORE UPDATE OR DELETE ON trazabilidad
                FOR EACH ROW EXECUTE FUNCTION trazabilidad_solo_insercion();

            CREATE TRIGGER trazabilidad_sin_truncate
                BEFORE TRUNCATE ON trazabilidad
                FOR EACH STATEMENT EXECUTE FUNCTION trazabilidad_solo_insercion();

            ALTER TABLE trazabilidad ENABLE ROW LEVEL SECURITY;
            -- Los eventos globales (empresa_id NULL: login, configuración global) son
            -- visibles en cualquier empresa; el resto solo en la suya.
            CREATE POLICY trazabilidad_lectura ON trazabilidad FOR SELECT
                USING (empresa_id IS NULL OR empresa_id = app_empresa_id());
            CREATE POLICY trazabilidad_insercion ON trazabilidad FOR INSERT
                WITH CHECK (empresa_id IS NULL OR empresa_id = app_empresa_id());
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS trazabilidad; DROP FUNCTION IF EXISTS trazabilidad_solo_insercion();');
    }
};
