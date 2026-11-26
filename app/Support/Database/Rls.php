<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security por empresa. Las tablas de negocio llevan empresa_id y una
 * política que solo deja ver y escribir filas de la empresa fijada en la conexión
 * (app_empresa_id(), ver App\Modules\Core\Tenancy\Tenancy). Sin empresa fijada,
 * la consulta no devuelve filas.
 */
class Rls
{
    public static function habilitar(string $tabla, string $columna = 'empresa_id'): void
    {
        DB::unprepared(<<<SQL
            ALTER TABLE "{$tabla}" ENABLE ROW LEVEL SECURITY;
            CREATE POLICY "{$tabla}_tenant" ON "{$tabla}"
                USING ("{$columna}" = app_empresa_id())
                WITH CHECK ("{$columna}" = app_empresa_id());
        SQL);
    }
}
