<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maestros comerciales: categorías, productos y clientes. Precios unitarios en
 * numeric(18,6); montos (cupo) en bigint de pesos enteros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nombre', 80);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('sku', 40);
            $table->string('nombre', 80);
            $table->foreignId('categoria_id')->nullable()->constrained('categorias');
            $table->string('unidad', 10)->default('UN');
            $table->decimal('precio_neto', 18, 6);
            $table->decimal('precio_mayorista', 18, 6)->nullable();
            $table->decimal('costo', 18, 6)->nullable();
            $table->boolean('exento')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['empresa_id', 'sku']);
        });

        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('rut', 12);
            $table->string('razon_social', 100);
            $table->string('giro', 80)->nullable();
            $table->string('direccion', 70)->nullable();
            $table->string('comuna', 40)->nullable();
            $table->string('ciudad', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('lista_precio', 12)->default('meson');
            $table->boolean('tiene_credito')->default(false);
            $table->bigInteger('cupo_credito')->default(0);
            $table->unsignedSmallInteger('plazo_dias')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['empresa_id', 'rut']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE productos
                ADD CONSTRAINT productos_precios_no_negativos CHECK (precio_neto >= 0 AND coalesce(precio_mayorista, 0) >= 0 AND coalesce(costo, 0) >= 0);
            ALTER TABLE clientes
                ADD CONSTRAINT clientes_lista_valida CHECK (lista_precio IN ('meson', 'mayorista')),
                ADD CONSTRAINT clientes_cupo_no_negativo CHECK (cupo_credito >= 0);
            CREATE INDEX clientes_busqueda ON clientes (empresa_id, razon_social);
            CREATE INDEX productos_busqueda ON productos (empresa_id, nombre);
        SQL);

        foreach (['categorias', 'productos', 'clientes'] as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
        Schema::dropIfExists('productos');
        Schema::dropIfExists('categorias');
    }
};
