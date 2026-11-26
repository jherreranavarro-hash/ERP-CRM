<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La aplicación se conecta con un rol que NO es dueño de las tablas (erp_app),
 * de modo que Row-Level Security siempre se le aplica. Las migraciones corren con
 * el dueño (erp_owner) y otorgan aquí los privilegios por defecto al rol de la app.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = config('database.connections.pgsql.username');

        DB::unprepared(<<<SQL
            DO \$\$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$app}') THEN
                    RAISE EXCEPTION 'Falta el rol de aplicación {$app}';
                END IF;
            END \$\$;
            GRANT USAGE ON SCHEMA public TO "{$app}";
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO "{$app}";
            ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO "{$app}";

            -- Empresa activa de la conexión. Sin empresa fijada devuelve NULL y
            -- ninguna política de RLS deja pasar filas.
            CREATE OR REPLACE FUNCTION app_empresa_id() RETURNS bigint
                LANGUAGE sql STABLE AS
                \$f\$ SELECT NULLIF(current_setting('app.empresa_id', true), '')::bigint \$f\$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS app_empresa_id()');
    }
};
