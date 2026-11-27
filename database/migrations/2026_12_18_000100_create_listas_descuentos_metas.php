<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4c: listas de precios configurables (mesón, mayorista y listas propias con
 * vigencia), reglas de descuento automáticas, niveles de aprobación de descuentos
 * por rol y metas comerciales por período.
 */
return new class extends Migration
{
    private const TABLAS = ['listas_precios', 'lista_precio_items', 'reglas_descuento', 'niveles_descuento', 'metas'];

    public function up(): void
    {
        Schema::create('listas_precios', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('codigo', 12);
            $t->string('nombre', 60);
            $t->string('regla', 10); // base (solo mesón), porcentaje (bajo mesón), propio
            $t->decimal('porcentaje', 5, 2)->nullable();
            $t->date('vigente_desde')->nullable();
            $t->date('vigente_hasta')->nullable();
            $t->boolean('activa')->default(true);
            $t->boolean('sistema')->default(false);
            $t->timestamps();
            $t->unique(['empresa_id', 'codigo']);
        });
        Schema::create('lista_precio_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('lista_id')->constrained('listas_precios')->cascadeOnDelete();
            $t->foreignId('producto_id')->constrained('productos');
            $t->decimal('precio_neto', 18, 6);
            $t->timestamps();
            $t->unique(['lista_id', 'producto_id']);
        });

        Schema::create('reglas_descuento', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nombre', 80);
            $t->foreignId('categoria_id')->nullable()->constrained('categorias');
            $t->string('lista_precio', 12)->nullable();
            $t->decimal('cantidad_minima', 14, 3)->default(0);
            $t->decimal('descuento_pct', 5, 2);
            $t->date('vigente_desde')->nullable();
            $t->date('vigente_hasta')->nullable();
            $t->boolean('activa')->default(true);
            $t->foreignId('creado_por')->constrained('users');
            $t->timestamps();
        });
        Schema::create('niveles_descuento', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->decimal('hasta_pct', 5, 2);
            $t->foreignId('rol_id')->constrained('roles');
            $t->timestamps();
            $t->unique(['empresa_id', 'hasta_pct']);
        });

        Schema::create('metas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->date('periodo'); // primer día del mes
            $t->string('indicador', 14); // venta_neta, documentos, cotizaciones
            $t->string('alcance', 8); // empresa, local, vendedor
            $t->foreignId('local_id')->nullable()->constrained('locales');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->bigInteger('objetivo');
            $t->foreignId('creado_por')->constrained('users');
            $t->timestamps();
            $t->index(['empresa_id', 'periodo']);
        });

        foreach (['documentos', 'cotizaciones'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->decimal('nivel_descuento_pct', 5, 2)->nullable());
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE listas_precios ADD CONSTRAINT listas_regla CHECK (regla IN ('base','porcentaje','propio') AND (regla = 'base') = (codigo = 'meson'));
            ALTER TABLE listas_precios ADD CONSTRAINT listas_porcentaje CHECK ((regla = 'porcentaje') = (porcentaje IS NOT NULL) AND coalesce(porcentaje, 0) BETWEEN 0 AND 90);
            ALTER TABLE listas_precios ADD CONSTRAINT listas_codigo CHECK (codigo ~ '^[a-z0-9_]{2,12}$');
            ALTER TABLE listas_precios ADD CONSTRAINT listas_vigencia CHECK (vigente_hasta IS NULL OR vigente_desde IS NULL OR vigente_hasta >= vigente_desde);
            ALTER TABLE lista_precio_items ADD CONSTRAINT items_precio CHECK (precio_neto >= 0);
            ALTER TABLE reglas_descuento ADD CONSTRAINT reglas_pct CHECK (descuento_pct > 0 AND descuento_pct <= 90 AND cantidad_minima >= 0);
            ALTER TABLE reglas_descuento ADD CONSTRAINT reglas_vigencia CHECK (vigente_hasta IS NULL OR vigente_desde IS NULL OR vigente_hasta >= vigente_desde);
            ALTER TABLE niveles_descuento ADD CONSTRAINT niveles_pct CHECK (hasta_pct > 0 AND hasta_pct <= 100);
            ALTER TABLE metas ADD CONSTRAINT metas_indicador CHECK (indicador IN ('venta_neta','documentos','cotizaciones'));
            ALTER TABLE metas ADD CONSTRAINT metas_alcance CHECK (
                (alcance = 'empresa' AND local_id IS NULL AND user_id IS NULL) OR
                (alcance = 'local' AND local_id IS NOT NULL AND user_id IS NULL) OR
                (alcance = 'vendedor' AND user_id IS NOT NULL AND local_id IS NULL));
            ALTER TABLE metas ADD CONSTRAINT metas_objetivo CHECK (objetivo > 0);
            ALTER TABLE metas ADD CONSTRAINT metas_periodo CHECK (extract(day FROM periodo) = 1);
            CREATE UNIQUE INDEX metas_unica ON metas (empresa_id, periodo, indicador, alcance, coalesce(local_id, 0), coalesce(user_id, 0));

            -- La lista del cliente pasa a ser cualquier lista de la empresa (validada en la aplicación).
            ALTER TABLE clientes DROP CONSTRAINT clientes_lista_valida;
            ALTER TABLE clientes ADD CONSTRAINT clientes_lista_formato CHECK (lista_precio ~ '^[a-z0-9_]{2,12}$');
        SQL);

        foreach (self::TABLAS as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE clientes DROP CONSTRAINT IF EXISTS clientes_lista_formato;
            UPDATE clientes SET lista_precio = 'meson' WHERE lista_precio NOT IN ('meson','mayorista');
            ALTER TABLE clientes ADD CONSTRAINT clientes_lista_valida CHECK (lista_precio IN ('meson', 'mayorista'));
        SQL);
        foreach (['documentos', 'cotizaciones'] as $tabla) {
            Schema::table($tabla, fn (Blueprint $t) => $t->dropColumn('nivel_descuento_pct'));
        }
        foreach (array_reverse(self::TABLAS) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
