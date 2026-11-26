<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Log de cada llamada a la API pública, separado del log de la app web.
        Schema::create('api_registros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->nullable()->constrained('empresas');
            $table->unsignedBigInteger('token_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('metodo', 10);
            $table->string('ruta', 255);
            $table->unsignedSmallInteger('estado_http');
            $table->unsignedInteger('duracion_ms');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['empresa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_registros');
    }
};
