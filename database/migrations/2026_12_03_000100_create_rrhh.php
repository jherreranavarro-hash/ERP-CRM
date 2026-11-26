<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3e: RRHH. Ficha de personal con documentos cifrados, contratos desde
 * plantillas con firma electrónica, turnos y marcas de asistencia (inmutables:
 * una corrección es una marca nueva), ausencias y vacaciones con aprobación,
 * préstamos al personal, comisiones por venta, nómina mensual con liquidaciones
 * y finiquitos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('turnos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nombre', 60);
            $table->time('hora_inicio');
            $table->time('hora_fin');
            $table->unsignedSmallInteger('colacion_min')->default(60);
            $table->jsonb('dias');                                   // [1..7], ISO: 1 = lunes
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('rut', 12);
            $table->string('nombres', 80);
            $table->string('apellidos', 80);
            $table->date('fecha_nacimiento')->nullable();
            $table->string('email', 120)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('direccion', 150)->nullable();
            $table->string('comuna', 60)->nullable();
            $table->string('nacionalidad', 40)->default('Chilena');
            $table->foreignId('local_id')->nullable()->constrained('locales');
            $table->foreignId('centro_costo_id')->nullable()->constrained('centros_costo');
            $table->foreignId('turno_id')->nullable()->constrained('turnos');
            $table->foreignId('user_id')->nullable()->constrained('users');   // vendedor: enlaza sus ventas para comisiones
            $table->string('cargo', 80);
            $table->date('fecha_ingreso');
            $table->unsignedTinyInteger('anios_previos')->default(0);         // años cotizados con otros empleadores (vacaciones progresivas)
            $table->string('afp', 20);
            $table->string('salud', 30)->default('fonasa');                    // fonasa o nombre de la isapre
            $table->decimal('plan_isapre_uf', 8, 4)->default(0);
            $table->string('banco', 60)->nullable();
            $table->string('cuenta_banco', 30)->nullable();
            $table->string('estado', 12)->default('activo');                  // activo, desvinculado
            $table->date('fecha_egreso')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'rut']);
        });

        Schema::create('empleado_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->string('tipo', 30);                 // contrato, cedula, certificado, licencia, otro
            $table->string('nombre', 150);
            $table->string('ruta', 255);                // archivo cifrado en reposo (Crypt) en el disco local
            $table->string('mime', 100);
            $table->unsignedInteger('bytes');
            $table->string('sha256', 64);
            $table->foreignId('subido_por')->constrained('users');
            $table->timestamps();
        });

        Schema::create('plantillas_contrato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('nombre', 80);
            $table->text('cuerpo');
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'nombre']);
        });

        Schema::create('contratos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->foreignId('plantilla_id')->nullable()->constrained('plantillas_contrato');
            $table->string('tipo', 12);                 // indefinido, plazo_fijo, obra
            $table->string('cargo', 80);
            $table->unsignedTinyInteger('jornada_horas');
            $table->bigInteger('sueldo_base');
            $table->boolean('gratificacion')->default(true);
            $table->bigInteger('colacion')->default(0);
            $table->bigInteger('movilizacion')->default(0);
            $table->decimal('comision_pct', 5, 2)->default(0);
            $table->date('fecha_inicio');
            $table->date('fecha_termino')->nullable();
            $table->text('texto')->nullable();          // contrato generado desde la plantilla
            $table->string('estado', 12)->default('borrador'); // borrador, por_firmar, vigente, terminado
            $table->string('firma_id', 80)->nullable(); // id en el proveedor de firma electrónica
            $table->timestampTz('firmado_at')->nullable();
            $table->foreignId('creado_por')->constrained('users');
            $table->timestamps();
        });

        Schema::create('marcas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->string('tipo', 8);                  // entrada, salida
            $table->timestampTz('momento');
            $table->string('origen', 10);               // reloj, app, manual
            $table->string('motivo', 200)->nullable();  // obligatorio en las manuales
            $table->string('huella', 64)->nullable();   // evita importar dos veces la misma marca del reloj
            $table->foreignId('registrado_por')->nullable()->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['empresa_id', 'huella']);
            $table->index(['empleado_id', 'momento']);
        });

        Schema::create('ausencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->string('tipo', 20);                 // vacaciones, licencia, permiso_con_goce, permiso_sin_goce
            $table->date('desde');
            $table->date('hasta');
            $table->unsignedSmallInteger('dias_habiles');
            $table->string('detalle', 200)->nullable();
            $table->string('estado', 12)->default('solicitada'); // solicitada, aprobada, rechazada, anulada
            $table->foreignId('solicitado_por')->constrained('users');
            $table->foreignId('resuelto_por')->nullable()->constrained('users');
            $table->timestampTz('resuelto_at')->nullable();
            $table->string('motivo_rechazo', 200)->nullable();
            $table->timestamps();
            $table->index(['empleado_id', 'desde']);
        });

        Schema::create('prestamos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->bigInteger('monto');
            $table->unsignedSmallInteger('cuotas');
            $table->bigInteger('valor_cuota');
            $table->bigInteger('saldo');
            $table->string('primer_periodo', 7);        // AAAA-MM de la primera cuota
            $table->string('motivo', 200);
            $table->string('estado', 12)->default('por_aprobar'); // por_aprobar, vigente, pagado, rechazado
            $table->foreignId('solicitado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestampTz('aprobado_at')->nullable();
            $table->timestamps();
        });

        Schema::create('comisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->foreignId('documento_id')->constrained('documentos');
            $table->string('periodo', 7);
            $table->bigInteger('base');                 // neto del documento (negativo en NC)
            $table->decimal('tasa', 5, 2);
            $table->bigInteger('monto');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['empresa_id', 'documento_id']);
        });

        Schema::create('nominas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('periodo', 7);
            $table->string('estado', 12)->default('borrador'); // borrador, aprobada, pagada
            $table->bigInteger('total_haberes')->default(0);
            $table->bigInteger('total_descuentos')->default(0);
            $table->bigInteger('total_liquido')->default(0);
            $table->bigInteger('total_empleador')->default(0);
            $table->jsonb('indicadores');               // UF, UTM, IMM y tasas usadas en el cálculo
            $table->foreignId('generado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestampTz('aprobado_at')->nullable();
            $table->date('fecha_pago')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'periodo']);
        });

        Schema::create('liquidaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('nomina_id')->constrained('nominas')->cascadeOnDelete();
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->foreignId('contrato_id')->constrained('contratos');
            $table->unsignedTinyInteger('dias_trabajados');
            $table->decimal('horas_extra', 6, 2)->default(0);
            $table->jsonb('haberes');                   // [{concepto, monto, imponible}]
            $table->jsonb('descuentos');                // [{concepto, monto, legal}]
            $table->jsonb('aportes');                   // aportes del empleador
            $table->bigInteger('imponible');
            $table->bigInteger('no_imponible');
            $table->bigInteger('tributable');
            $table->bigInteger('impuesto');
            $table->bigInteger('total_descuentos');
            $table->bigInteger('liquido');
            $table->bigInteger('costo_empleador');
            $table->timestamps();
            $table->unique(['nomina_id', 'empleado_id']);
        });

        Schema::create('finiquitos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->foreignId('contrato_id')->constrained('contratos');
            $table->string('causal', 12);               // 159-1, 159-2, 159-4, 159-5, 160, 161-1, 161-2
            $table->date('fecha_termino');
            $table->boolean('aviso_previo')->default(false); // se avisó con 30 días: no hay indemnización sustitutiva
            $table->bigInteger('base_calculo');         // última remuneración mensual (con tope en UF)
            $table->decimal('anios_servicio', 5, 2);
            $table->jsonb('conceptos');                 // [{concepto, monto}]
            $table->bigInteger('total');
            $table->string('estado', 12)->default('borrador'); // borrador, aprobado, firmado, anulado
            $table->foreignId('creado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestampTz('aprobado_at')->nullable();
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE empleados ADD CONSTRAINT emp_estado CHECK (estado IN ('activo', 'desvinculado'));
            ALTER TABLE contratos
                ADD CONSTRAINT ct_tipo CHECK (tipo IN ('indefinido', 'plazo_fijo', 'obra')),
                ADD CONSTRAINT ct_estado CHECK (estado IN ('borrador', 'por_firmar', 'vigente', 'terminado')),
                ADD CONSTRAINT ct_montos CHECK (sueldo_base > 0 AND colacion >= 0 AND movilizacion >= 0 AND jornada_horas BETWEEN 1 AND 45),
                ADD CONSTRAINT ct_fechas CHECK (fecha_termino IS NULL OR fecha_termino >= fecha_inicio),
                ADD CONSTRAINT ct_plazo CHECK (tipo = 'indefinido' OR fecha_termino IS NOT NULL);
            -- Un solo contrato vigente por persona.
            CREATE UNIQUE INDEX contratos_un_vigente ON contratos (empleado_id) WHERE estado = 'vigente';

            ALTER TABLE marcas
                ADD CONSTRAINT mc_tipo CHECK (tipo IN ('entrada', 'salida')),
                ADD CONSTRAINT mc_origen CHECK (origen IN ('reloj', 'app', 'manual')),
                ADD CONSTRAINT mc_manual CHECK (origen <> 'manual' OR (motivo IS NOT NULL AND registrado_por IS NOT NULL));
            CREATE TRIGGER marcas_inmutables BEFORE UPDATE OR DELETE ON marcas FOR EACH ROW EXECUTE FUNCTION solo_insercion();

            ALTER TABLE ausencias
                ADD CONSTRAINT au_tipo CHECK (tipo IN ('vacaciones', 'licencia', 'permiso_con_goce', 'permiso_sin_goce')),
                ADD CONSTRAINT au_estado CHECK (estado IN ('solicitada', 'aprobada', 'rechazada', 'anulada')),
                ADD CONSTRAINT au_fechas CHECK (hasta >= desde),
                -- Cuatro ojos: nadie resuelve su propia solicitud.
                ADD CONSTRAINT au_cuatro_ojos CHECK (resuelto_por IS NULL OR resuelto_por <> solicitado_por);

            ALTER TABLE prestamos
                ADD CONSTRAINT pr_estado CHECK (estado IN ('por_aprobar', 'vigente', 'pagado', 'rechazado')),
                ADD CONSTRAINT pr_montos CHECK (monto > 0 AND cuotas > 0 AND valor_cuota > 0 AND saldo >= 0 AND saldo <= monto),
                ADD CONSTRAINT pr_cuatro_ojos CHECK (aprobado_por IS NULL OR aprobado_por <> solicitado_por);

            ALTER TABLE nominas
                ADD CONSTRAINT nm_estado CHECK (estado IN ('borrador', 'aprobada', 'pagada')),
                ADD CONSTRAINT nm_cuatro_ojos CHECK (aprobado_por IS NULL OR aprobado_por <> generado_por);

            ALTER TABLE finiquitos
                ADD CONSTRAINT fq_estado CHECK (estado IN ('borrador', 'aprobado', 'firmado', 'anulado')),
                ADD CONSTRAINT fq_cuatro_ojos CHECK (aprobado_por IS NULL OR aprobado_por <> creado_por);

            -- Una liquidación de una nómina aprobada o pagada ya no cambia.
            CREATE OR REPLACE FUNCTION liquidacion_congelada() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE e text;
            BEGIN
                SELECT estado INTO e FROM nominas WHERE id = COALESCE(OLD.nomina_id, NEW.nomina_id);
                IF e IS DISTINCT FROM 'borrador' THEN
                    RAISE EXCEPTION 'La nómina % ya está %: sus liquidaciones no cambian', COALESCE(OLD.nomina_id, NEW.nomina_id), e USING ERRCODE = 'check_violation';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END $$;
            CREATE TRIGGER liquidaciones_congeladas BEFORE INSERT OR UPDATE OR DELETE ON liquidaciones FOR EACH ROW EXECUTE FUNCTION liquidacion_congelada();
        SQL);

        foreach (['turnos', 'empleados', 'empleado_documentos', 'plantillas_contrato', 'contratos', 'marcas', 'ausencias', 'prestamos', 'comisiones', 'nominas', 'liquidaciones', 'finiquitos'] as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        foreach (['finiquitos', 'liquidaciones', 'nominas', 'comisiones', 'prestamos', 'ausencias', 'marcas', 'contratos', 'plantillas_contrato', 'empleado_documentos', 'empleados', 'turnos'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS liquidacion_congelada();');
    }
};
