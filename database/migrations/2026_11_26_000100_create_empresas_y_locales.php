<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Empresas (tenants). No lleva RLS: el acceso se controla por membresía
        // (usuario_empresa) y por la policy de Laravel; solo SuperAdmin las administra.
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            $table->string('rut', 12)->unique();
            $table->string('razon_social', 150);
            $table->string('nombre_fantasia', 100)->nullable();
            $table->string('giro', 150)->nullable();
            $table->string('acteco', 10)->nullable();
            $table->string('direccion', 150)->nullable();
            $table->string('comuna', 80)->nullable();
            $table->string('ciudad', 80)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 120)->nullable();
            $table->unsignedInteger('resolucion_sii_numero')->nullable();
            $table->date('resolucion_sii_fecha')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('locales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('codigo', 20);
            $table->string('nombre', 100);
            $table->string('direccion', 150)->nullable();
            $table->string('comuna', 80)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->boolean('es_bodega')->default(false);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['empresa_id', 'codigo']);
        });

        Rls::habilitar('locales');
    }

    public function down(): void
    {
        Schema::dropIfExists('locales');
        Schema::dropIfExists('empresas');
    }
};
