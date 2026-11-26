<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RBAC jerárquico: perfil → roles → permisos. El rol se asigna al usuario por
 * empresa (y opcionalmente por local) en usuario_empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('perfiles', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 40)->unique();
            $table->string('nombre', 80);
            $table->string('descripcion', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 120)->unique();   // modulo.submodulo.accion
            $table->string('modulo', 40);
            $table->string('submodulo', 60);
            $table->string('accion', 20);
            $table->string('descripcion', 200);
            $table->boolean('restringido')->default(false); // ni SuperAdmin lo tiene implícito
            $table->timestamps();
            $table->index(['modulo', 'submodulo']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('perfil_id')->constrained('perfiles');
            $table->string('nombre', 80)->unique();
            $table->string('descripcion', 255)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rol_permiso', function (Blueprint $table) {
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permiso_id')->constrained('permisos')->cascadeOnDelete();
            $table->primary(['rol_id', 'permiso_id']);
        });

        Schema::create('usuario_empresa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('rol_id')->constrained('roles');
            $table->foreignId('local_id')->nullable()->constrained('locales');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'empresa_id']);
        });

        Schema::create('invitaciones', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150);
            $table->string('nombre', 120);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('rol_id')->constrained('roles');
            $table->foreignId('local_id')->nullable()->constrained('locales');
            $table->foreignId('invitado_por')->constrained('users');
            $table->timestamp('expira_at');
            $table->timestamp('aceptada_at')->nullable();
            $table->timestamp('revocada_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones');
        Schema::dropIfExists('usuario_empresa');
        Schema::dropIfExists('rol_permiso');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permisos');
        Schema::dropIfExists('perfiles');
    }
};
