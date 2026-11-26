<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cotizaciones (VentasCRM) y turnos de caja (Caja).
 *  - Una cotización convertida queda congelada y apunta a su documento.
 *  - Un usuario tiene a lo más un turno abierto, y una caja también.
 *  - Un turno cerrado es inmutable salvo la aprobación de su diferencia,
 *    y no admite pagos nuevos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('local_id')->nullable()->constrained('locales');
            $table->unsignedInteger('numero');
            $table->string('estado', 12)->default('borrador');
            $table->foreignId('cliente_id')->nullable()->constrained('clientes');
            $table->string('contacto_nombre', 100)->nullable();
            $table->string('contacto_email', 120)->nullable();
            $table->string('contacto_telefono', 20)->nullable();
            $table->date('fecha');
            $table->date('valida_hasta');
            $table->string('forma_pago', 8)->default('contado');
            $table->decimal('descuento_global_pct', 5, 2)->default(0);
            $table->bigInteger('descuento_global_monto')->default(0);
            $table->decimal('tasa_iva', 5, 2);
            $table->bigInteger('monto_neto')->default(0);
            $table->bigInteger('monto_exento')->default(0);
            $table->bigInteger('monto_iva')->default(0);
            $table->bigInteger('monto_total')->default(0);
            $table->jsonb('aprobacion_motivos')->nullable();
            $table->text('observaciones')->nullable();
            $table->string('motivo_rechazo', 200)->nullable();
            $table->foreignId('documento_id')->nullable()->constrained('documentos');
            $table->foreignId('creado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado']);
            $table->index(['empresa_id', 'cliente_id']);
        });

        Schema::create('cotizacion_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('cotizacion_id')->constrained('cotizaciones')->cascadeOnDelete();
            $table->unsignedSmallInteger('orden');
            $table->foreignId('producto_id')->nullable()->constrained('productos');
            $table->string('codigo', 40)->nullable();
            $table->string('descripcion', 80);
            $table->decimal('cantidad', 18, 6);
            $table->string('unidad', 10)->default('UN');
            $table->decimal('precio_unitario', 18, 6);
            $table->decimal('precio_lista', 18, 6)->nullable();
            $table->decimal('descuento_pct', 5, 2)->default(0);
            $table->bigInteger('descuento_monto')->default(0);
            $table->bigInteger('monto_item');
            $table->boolean('exento')->default(false);
            $table->timestamps();
            $table->unique(['cotizacion_id', 'orden']);
        });

        Schema::table('documentos', function (Blueprint $table) {
            $table->foreignId('cotizacion_id')->nullable()->after('cliente_id')->constrained('cotizaciones');
        });

        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('local_id')->constrained('locales');
            $table->string('nombre', 60);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'local_id', 'nombre']);
        });

        Schema::create('turnos_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('caja_id')->constrained('cajas');
            $table->foreignId('usuario_id')->constrained('users');
            $table->string('estado', 10)->default('abierto');
            $table->bigInteger('monto_apertura');
            $table->timestampTz('abierto_at');
            $table->timestampTz('cerrado_at')->nullable();
            $table->jsonb('esperado')->nullable();     // por medio de pago, al cerrar
            $table->jsonb('declarado')->nullable();    // contado por la cajera, por medio
            $table->bigInteger('diferencia')->nullable();
            $table->boolean('requiere_aprobacion')->default(false);
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestampTz('aprobado_at')->nullable();
            $table->string('observacion', 300)->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'abierto_at']);
        });

        Schema::table('pagos', function (Blueprint $table) {
            $table->foreignId('turno_id')->nullable()->after('documento_id')->constrained('turnos_caja');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cotizaciones
                ADD CONSTRAINT cotizaciones_estado CHECK (estado IN ('borrador', 'por_aprobar', 'enviada', 'aceptada', 'rechazada', 'convertida')),
                ADD CONSTRAINT cotizaciones_forma_pago CHECK (forma_pago IN ('contado', 'credito')),
                ADD CONSTRAINT cotizaciones_total_cuadra CHECK (monto_total = monto_neto + monto_exento + monto_iva),
                ADD CONSTRAINT cotizaciones_montos CHECK (monto_neto >= 0 AND monto_exento >= 0 AND monto_iva >= 0 AND descuento_global_monto >= 0),
                ADD CONSTRAINT cotizaciones_vigencia CHECK (valida_hasta >= fecha),
                ADD CONSTRAINT cotizaciones_convertida CHECK ((estado = 'convertida') = (documento_id IS NOT NULL)),
                ADD CONSTRAINT cotizaciones_contacto CHECK (cliente_id IS NOT NULL OR char_length(trim(coalesce(contacto_nombre, ''))) > 0);

            ALTER TABLE cotizacion_lineas
                ADD CONSTRAINT cot_lineas_cantidad CHECK (cantidad > 0),
                ADD CONSTRAINT cot_lineas_precio CHECK (precio_unitario >= 0),
                ADD CONSTRAINT cot_lineas_descuento CHECK (descuento_pct BETWEEN 0 AND 100 AND descuento_monto >= 0),
                ADD CONSTRAINT cot_lineas_monto CHECK (monto_item >= 0),
                ADD CONSTRAINT cot_lineas_orden CHECK (orden BETWEEN 1 AND 60);

            -- Cotización convertida o rechazada: congelada (sus líneas también).
            CREATE OR REPLACE FUNCTION cotizaciones_congeladas() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.estado NOT IN ('borrador', 'por_aprobar') THEN
                        RAISE EXCEPTION 'Solo se elimina una cotización en borrador' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.estado IN ('convertida', 'rechazada') THEN
                    RAISE EXCEPTION 'La cotización N° % ya está cerrada (%)', OLD.numero, OLD.estado USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER cotizaciones_congeladas BEFORE UPDATE OR DELETE ON cotizaciones
                FOR EACH ROW EXECUTE FUNCTION cotizaciones_congeladas();

            CREATE OR REPLACE FUNCTION cotizacion_lineas_congeladas() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE est text;
            BEGIN
                SELECT estado INTO est FROM cotizaciones WHERE id = coalesce(NEW.cotizacion_id, OLD.cotizacion_id);
                IF est IS NOT NULL AND est NOT IN ('borrador', 'por_aprobar') THEN
                    RAISE EXCEPTION 'Las líneas solo cambian mientras la cotización es borrador' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN coalesce(NEW, OLD);
            END $$;
            CREATE TRIGGER cotizacion_lineas_congeladas BEFORE INSERT OR UPDATE OR DELETE ON cotizacion_lineas
                FOR EACH ROW EXECUTE FUNCTION cotizacion_lineas_congeladas();

            ALTER TABLE turnos_caja
                ADD CONSTRAINT turnos_estado CHECK (estado IN ('abierto', 'cerrado')),
                ADD CONSTRAINT turnos_apertura CHECK (monto_apertura >= 0),
                ADD CONSTRAINT turnos_cierre CHECK ((estado = 'cerrado') = (cerrado_at IS NOT NULL AND diferencia IS NOT NULL)),
                ADD CONSTRAINT turnos_aprobacion CHECK (aprobado_por IS NULL OR requiere_aprobacion);

            -- Un turno abierto por caja y uno por usuario.
            CREATE UNIQUE INDEX turnos_un_abierto_por_caja ON turnos_caja (caja_id) WHERE estado = 'abierto';
            CREATE UNIQUE INDEX turnos_un_abierto_por_usuario ON turnos_caja (empresa_id, usuario_id) WHERE estado = 'abierto';

            -- Turno cerrado: inmutable salvo la aprobación de su diferencia.
            CREATE OR REPLACE FUNCTION turnos_caja_cerrados() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un turno de caja no se elimina' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF OLD.estado = 'cerrado' THEN
                    IF (NEW.caja_id, NEW.usuario_id, NEW.estado, NEW.monto_apertura, NEW.abierto_at, NEW.cerrado_at, NEW.esperado, NEW.declarado,
                        NEW.diferencia, NEW.requiere_aprobacion, NEW.observacion)
                       IS DISTINCT FROM
                       (OLD.caja_id, OLD.usuario_id, OLD.estado, OLD.monto_apertura, OLD.abierto_at, OLD.cerrado_at, OLD.esperado, OLD.declarado,
                        OLD.diferencia, OLD.requiere_aprobacion, OLD.observacion) THEN
                        RAISE EXCEPTION 'El turno % ya está cerrado: su cuadratura no cambia', OLD.id USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                    IF OLD.aprobado_por IS NOT NULL AND NEW.aprobado_por IS DISTINCT FROM OLD.aprobado_por THEN
                        RAISE EXCEPTION 'La diferencia del turno % ya fue aprobada', OLD.id USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER turnos_caja_cerrados BEFORE UPDATE OR DELETE ON turnos_caja
                FOR EACH ROW EXECUTE FUNCTION turnos_caja_cerrados();

            -- Un pago solo entra a un turno abierto, y no se mueve de turno.
            CREATE OR REPLACE FUNCTION pagos_turno_abierto() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.turno_id IS DISTINCT FROM OLD.turno_id THEN
                    RAISE EXCEPTION 'Un pago no cambia de turno' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF TG_OP = 'INSERT' AND NEW.turno_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM turnos_caja t WHERE t.id = NEW.turno_id AND t.estado = 'abierto') THEN
                    RAISE EXCEPTION 'El turno de caja está cerrado' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER pagos_turno_abierto BEFORE INSERT OR UPDATE ON pagos
                FOR EACH ROW EXECUTE FUNCTION pagos_turno_abierto();
        SQL);

        foreach (['cotizaciones', 'cotizacion_lineas', 'cajas', 'turnos_caja'] as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS pagos_turno_abierto ON pagos; DROP TRIGGER IF EXISTS turnos_caja_cerrados ON turnos_caja; DROP TRIGGER IF EXISTS cotizacion_lineas_congeladas ON cotizacion_lineas; DROP TRIGGER IF EXISTS cotizaciones_congeladas ON cotizaciones;');
        Schema::table('pagos', fn (Blueprint $t) => $t->dropConstrainedForeignId('turno_id'));
        Schema::dropIfExists('turnos_caja');
        Schema::dropIfExists('cajas');
        Schema::table('documentos', fn (Blueprint $t) => $t->dropConstrainedForeignId('cotizacion_id'));
        Schema::dropIfExists('cotizacion_lineas');
        Schema::dropIfExists('cotizaciones');
        DB::unprepared('DROP FUNCTION IF EXISTS pagos_turno_abierto(); DROP FUNCTION IF EXISTS turnos_caja_cerrados(); DROP FUNCTION IF EXISTS cotizacion_lineas_congeladas(); DROP FUNCTION IF EXISTS cotizaciones_congeladas();');
    }
};
