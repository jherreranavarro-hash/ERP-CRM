<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5: cierre de requisitos no funcionales.
 *  - alertas: bandeja de alertas de seguridad y operación (panel + correo).
 *  - respaldos: bitácora de respaldos y pruebas de restauración que informan los
 *    scripts del servidor (panel "Respaldos y continuidad").
 *  - retencion_ejecuciones: lo que hizo cada pasada de la política de retención.
 *  - solicitudes_privilegio: cuatro ojos para dar permisos a un rol o la marca
 *    SuperAdmin (RBAC es mantenedor sensible). Quitar privilegios rige al tiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alertas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->nullable()->constrained('empresas'); // null = alerta de plataforma
            $t->string('tipo', 10);      // seguridad, operacion
            $t->string('codigo', 40);    // api_anomalia, folios_por_agotarse, dte_rechazado, ...
            $t->string('clave', 120);    // para no repetir la misma alerta en la ventana
            $t->string('titulo', 200);
            $t->jsonb('detalle')->nullable();
            $t->jsonb('notificada_a')->nullable();
            $t->foreignId('atendida_por')->nullable()->constrained('users');
            $t->timestamp('atendida_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['empresa_id', 'atendida_at', 'created_at']);
            $t->index(['codigo', 'clave', 'created_at']);
        });

        Schema::create('respaldos', function (Blueprint $t) {
            $t->id();
            $t->string('tipo', 12);        // respaldo, restauracion
            $t->string('resultado', 6);    // ok, fallo
            $t->string('archivo', 255)->nullable();
            $t->unsignedBigInteger('bytes')->nullable();
            $t->unsignedInteger('duracion_s')->nullable();
            $t->string('detalle', 1000)->nullable();
            $t->string('servidor', 120)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['tipo', 'created_at']);
        });

        Schema::create('retencion_ejecuciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->nullable()->constrained('empresas');
            $t->string('categoria', 30);  // grabaciones, mensajeria, prospectos, logs_api
            $t->string('accion', 12);     // archivar, anonimizar, eliminar, suspendida
            $t->unsignedInteger('registros')->default(0);
            $t->date('limite');
            $t->string('archivo', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('solicitudes_privilegio', function (Blueprint $t) {
            $t->id();
            $t->string('tipo', 14);  // rol_permisos, superadmin
            $t->foreignId('rol_id')->nullable()->constrained('roles');
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->jsonb('permisos')->nullable();   // claves que se agregan al rol
            $t->boolean('otorgar')->nullable();  // superadmin: true otorga
            $t->string('motivo', 500);
            $t->string('estado', 10)->default('pendiente'); // pendiente, aprobada, rechazada, anulada
            $t->foreignId('solicitado_por')->constrained('users');
            $t->foreignId('resuelto_por')->nullable()->constrained('users');
            $t->string('comentario', 500)->nullable();
            $t->timestamp('resuelto_at')->nullable();
            $t->timestamps();
            $t->index(['estado', 'created_at']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE alertas ADD CONSTRAINT alertas_tipo_chk CHECK (tipo IN ('seguridad', 'operacion'));
            ALTER TABLE alertas ADD CONSTRAINT alertas_atencion_chk CHECK ((atendida_por IS NULL) = (atendida_at IS NULL));
            ALTER TABLE alertas ENABLE ROW LEVEL SECURITY;
            CREATE POLICY alertas_tenant ON alertas
                USING (empresa_id IS NULL OR empresa_id = app_empresa_id())
                WITH CHECK (empresa_id IS NULL OR empresa_id = app_empresa_id());

            ALTER TABLE respaldos ADD CONSTRAINT respaldos_tipo_chk CHECK (tipo IN ('respaldo', 'restauracion'));
            ALTER TABLE respaldos ADD CONSTRAINT respaldos_resultado_chk CHECK (resultado IN ('ok', 'fallo'));

            ALTER TABLE retencion_ejecuciones ADD CONSTRAINT retencion_accion_chk
                CHECK (accion IN ('archivar', 'anonimizar', 'eliminar', 'suspendida'));
            ALTER TABLE retencion_ejecuciones ENABLE ROW LEVEL SECURITY;
            CREATE POLICY retencion_ejecuciones_tenant ON retencion_ejecuciones
                USING (empresa_id IS NULL OR empresa_id = app_empresa_id())
                WITH CHECK (empresa_id IS NULL OR empresa_id = app_empresa_id());

            ALTER TABLE solicitudes_privilegio ADD CONSTRAINT solicitudes_privilegio_tipo_chk
                CHECK ((tipo = 'rol_permisos' AND rol_id IS NOT NULL AND permisos IS NOT NULL AND user_id IS NULL)
                    OR (tipo = 'superadmin' AND user_id IS NOT NULL AND otorgar IS NOT NULL AND rol_id IS NULL));
            ALTER TABLE solicitudes_privilegio ADD CONSTRAINT solicitudes_privilegio_estado_chk
                CHECK (estado IN ('pendiente', 'aprobada', 'rechazada', 'anulada'));
            -- Cuatro ojos: nadie resuelve su propia solicitud (anular la propia sí).
            ALTER TABLE solicitudes_privilegio ADD CONSTRAINT solicitudes_privilegio_cuatro_ojos
                CHECK (estado IN ('pendiente', 'anulada') OR resuelto_por <> solicitado_por);
            -- Nadie se otorga a sí mismo la marca SuperAdmin.
            ALTER TABLE solicitudes_privilegio ADD CONSTRAINT solicitudes_privilegio_no_autootorgar
                CHECK (tipo <> 'superadmin' OR user_id <> solicitado_por);
            CREATE UNIQUE INDEX solicitudes_privilegio_una_pendiente_rol
                ON solicitudes_privilegio (rol_id) WHERE estado = 'pendiente' AND tipo = 'rol_permisos';
            CREATE UNIQUE INDEX solicitudes_privilegio_una_pendiente_usuario
                ON solicitudes_privilegio (user_id) WHERE estado = 'pendiente' AND tipo = 'superadmin';
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_privilegio');
        Schema::dropIfExists('retencion_ejecuciones');
        Schema::dropIfExists('respaldos');
        Schema::dropIfExists('alertas');
    }
};
