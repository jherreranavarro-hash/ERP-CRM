<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3e: anticipos de sueldo (quincena), descontados en la nómina del mes,
 * reglas de comisión por categoría de producto y aprobación de comisiones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anticipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->string('periodo', 7);
            $table->date('fecha');
            $table->bigInteger('monto');
            $table->string('estado', 12)->default('registrado'); // registrado, descontado, anulado
            $table->foreignId('nomina_id')->nullable()->constrained('nominas');
            $table->foreignId('registrado_por')->constrained('users');
            $table->timestamps();
            $table->index(['empleado_id', 'periodo']);
        });

        Schema::create('reglas_comision', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('categoria_id')->constrained('categorias');
            $table->foreignId('empleado_id')->nullable()->constrained('empleados'); // null: para todos los vendedores
            $table->decimal('tasa', 5, 2);
            $table->timestamps();
        });

        Schema::table('comisiones', function (Blueprint $table) {
            $table->string('estado', 12)->default('pendiente');   // pendiente, aprobada
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->jsonb('detalle')->nullable();                 // tasa aplicada por categoría
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE anticipos
                ADD CONSTRAINT an_estado CHECK (estado IN ('registrado', 'descontado', 'anulado')),
                ADD CONSTRAINT an_monto CHECK (monto > 0);
            ALTER TABLE reglas_comision ADD CONSTRAINT rc_tasa CHECK (tasa >= 0 AND tasa <= 30);
            CREATE UNIQUE INDEX reglas_comision_unica ON reglas_comision (empresa_id, categoria_id, COALESCE(empleado_id, 0));
            ALTER TABLE comisiones ADD CONSTRAINT cm_estado CHECK (estado IN ('pendiente', 'aprobada'));
        SQL);

        foreach (['anticipos', 'reglas_comision'] as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        Schema::table('comisiones', fn (Blueprint $t) => $t->dropConstrainedForeignId('aprobado_por'));
        Schema::table('comisiones', fn (Blueprint $t) => $t->dropColumn(['estado', 'detalle']));
        Schema::dropIfExists('reglas_comision');
        Schema::dropIfExists('anticipos');
    }
};
