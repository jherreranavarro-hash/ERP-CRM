<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos tributarios electrónicos. Las reglas que no pueden depender solo
 * del código las exige la base:
 *  - montos en pesos enteros y total = neto + exento + iva;
 *  - folio único por empresa y tipo, presente en todo documento emitido;
 *  - cabecera cuadrada con la suma de sus líneas al emitir;
 *  - documento emitido inmutable (solo cambian saldo, estado de pago y estado SII);
 *  - líneas y referencias congeladas una vez emitido;
 *  - rangos de folios sin solapes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folio_rangos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->unsignedSmallInteger('tipo_dte');
            $table->bigInteger('desde');
            $table->bigInteger('hasta');
            $table->bigInteger('siguiente');
            $table->string('caf_referencia', 120)->nullable();
            $table->date('vence')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('local_id')->nullable()->constrained('locales');
            $table->unsignedSmallInteger('tipo_dte');
            $table->bigInteger('folio')->nullable();
            $table->string('estado', 12)->default('borrador');
            $table->foreignId('cliente_id')->nullable()->constrained('clientes');
            // Receptor congelado al emitir: si el cliente cambia después, el documento no.
            $table->string('receptor_rut', 12)->nullable();
            $table->string('receptor_razon_social', 100)->nullable();
            $table->string('receptor_giro', 80)->nullable();
            $table->string('receptor_direccion', 70)->nullable();
            $table->string('receptor_comuna', 40)->nullable();
            $table->date('fecha_emision');
            $table->date('fecha_vencimiento')->nullable();
            $table->string('forma_pago', 8)->default('contado');
            $table->decimal('descuento_global_pct', 5, 2)->default(0);
            $table->bigInteger('descuento_global_monto')->default(0);
            $table->decimal('tasa_iva', 5, 2);
            // Precios con IVA incluido: boleta 39 y NC/ND que refieren a una boleta.
            $table->boolean('iva_incluido')->default(false);
            $table->bigInteger('monto_neto')->default(0);
            $table->bigInteger('monto_exento')->default(0);
            $table->bigInteger('monto_iva')->default(0);
            $table->bigInteger('monto_total')->default(0);
            $table->bigInteger('saldo')->default(0);
            $table->string('estado_sii', 10)->nullable();
            $table->string('sii_track_id', 60)->nullable();
            $table->jsonb('sii_respuesta')->nullable();
            $table->unsignedSmallInteger('sii_intentos')->default(0);
            $table->string('pdf_path')->nullable();
            $table->jsonb('aprobacion_motivos')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('creado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->foreignId('emitido_por')->nullable()->constrained('users');
            $table->timestampTz('emitido_at')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'tipo_dte', 'folio']);
            $table->index(['empresa_id', 'fecha_emision']);
            $table->index(['empresa_id', 'cliente_id']);
            $table->index(['empresa_id', 'local_id']);
        });

        Schema::create('documento_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('documento_id')->constrained('documentos')->cascadeOnDelete();
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
            $table->unique(['documento_id', 'orden']);
        });

        Schema::create('documento_referencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('documento_id')->constrained('documentos')->cascadeOnDelete();
            $table->foreignId('documento_referido_id')->nullable()->constrained('documentos');
            $table->unsignedSmallInteger('tipo_dte_ref');
            $table->bigInteger('folio_ref');
            $table->date('fecha_ref');
            $table->unsignedSmallInteger('codigo')->nullable();
            $table->string('razon', 90);
            $table->timestamps();
        });

        Schema::create('pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('documento_id')->constrained('documentos');
            $table->bigInteger('monto');
            $table->string('metodo', 15);
            $table->date('fecha');
            $table->string('referencia', 60)->nullable();
            $table->boolean('conciliado')->default(false);
            $table->foreignId('registrado_por')->constrained('users');
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE folio_rangos
                ADD CONSTRAINT folio_rangos_tipo CHECK (tipo_dte IN (33, 34, 39, 41, 52, 56, 61)),
                ADD CONSTRAINT folio_rangos_orden CHECK (desde > 0 AND desde <= hasta AND siguiente BETWEEN desde AND hasta + 1);

            ALTER TABLE documentos
                ADD CONSTRAINT documentos_tipo CHECK (tipo_dte IN (33, 34, 39, 41, 52, 56, 61)),
                ADD CONSTRAINT documentos_estado CHECK (estado IN ('borrador', 'por_aprobar', 'emitido', 'pagado', 'anulado')),
                ADD CONSTRAINT documentos_estado_sii CHECK (estado_sii IS NULL OR estado_sii IN ('pendiente', 'aceptado', 'rechazado', 'error')),
                ADD CONSTRAINT documentos_forma_pago CHECK (forma_pago IN ('contado', 'credito')),
                ADD CONSTRAINT documentos_total_cuadra CHECK (monto_total = monto_neto + monto_exento + monto_iva),
                ADD CONSTRAINT documentos_montos_no_negativos CHECK (monto_neto >= 0 AND monto_exento >= 0 AND monto_iva >= 0 AND descuento_global_monto >= 0 AND saldo >= 0),
                ADD CONSTRAINT documentos_saldo_tope CHECK (saldo <= monto_total),
                ADD CONSTRAINT documentos_descuento_rango CHECK (descuento_global_pct BETWEEN 0 AND 100),
                ADD CONSTRAINT documentos_folio_al_emitir CHECK ((estado IN ('borrador', 'por_aprobar')) = (folio IS NULL)),
                ADD CONSTRAINT documentos_vencimiento CHECK (fecha_vencimiento IS NULL OR fecha_vencimiento > fecha_emision);

            ALTER TABLE documento_lineas
                ADD CONSTRAINT lineas_cantidad CHECK (cantidad > 0),
                ADD CONSTRAINT lineas_precio CHECK (precio_unitario >= 0),
                ADD CONSTRAINT lineas_descuento CHECK (descuento_pct BETWEEN 0 AND 100 AND descuento_monto >= 0),
                ADD CONSTRAINT lineas_monto CHECK (monto_item >= 0),
                ADD CONSTRAINT lineas_descripcion CHECK (char_length(descripcion) BETWEEN 1 AND 80),
                ADD CONSTRAINT lineas_orden CHECK (orden BETWEEN 1 AND 60);

            ALTER TABLE documento_referencias
                ADD CONSTRAINT referencias_codigo CHECK (codigo IS NULL OR codigo IN (1, 2, 3)),
                ADD CONSTRAINT referencias_razon CHECK (char_length(trim(razon)) > 0);

            ALTER TABLE pagos
                ADD CONSTRAINT pagos_monto CHECK (monto > 0),
                ADD CONSTRAINT pagos_metodo CHECK (metodo IN ('efectivo', 'debito', 'credito', 'transferencia', 'cheque', 'nota_credito'));

            -- Rangos de folios sin solape por empresa y tipo.
            CREATE OR REPLACE FUNCTION folio_rangos_sin_solape() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('folios:' || NEW.empresa_id || ':' || NEW.tipo_dte));
                IF EXISTS (
                    SELECT 1 FROM folio_rangos r
                    WHERE r.empresa_id = NEW.empresa_id AND r.tipo_dte = NEW.tipo_dte AND r.id <> NEW.id
                      AND int8range(r.desde, r.hasta, '[]') && int8range(NEW.desde, NEW.hasta, '[]')
                ) THEN
                    RAISE EXCEPTION 'El rango de folios % a % se solapa con otro del mismo tipo', NEW.desde, NEW.hasta
                        USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER folio_rangos_sin_solape BEFORE INSERT OR UPDATE OF desde, hasta, tipo_dte ON folio_rangos
                FOR EACH ROW EXECUTE FUNCTION folio_rangos_sin_solape();

            -- Documento emitido: inmutable salvo saldo, pago/anulación y estado SII.
            CREATE OR REPLACE FUNCTION documentos_inmutables() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.estado NOT IN ('borrador', 'por_aprobar') THEN
                        RAISE EXCEPTION 'Un documento emitido no se puede eliminar: corrígelo con nota de crédito'
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.estado IN ('emitido', 'pagado', 'anulado') THEN
                    IF (NEW.empresa_id, NEW.local_id, NEW.tipo_dte, NEW.folio, NEW.cliente_id, NEW.receptor_rut,
                        NEW.receptor_razon_social, NEW.receptor_giro, NEW.receptor_direccion, NEW.receptor_comuna,
                        NEW.fecha_emision, NEW.fecha_vencimiento, NEW.forma_pago, NEW.descuento_global_pct,
                        NEW.descuento_global_monto, NEW.tasa_iva, NEW.iva_incluido, NEW.monto_neto, NEW.monto_exento, NEW.monto_iva,
                        NEW.monto_total, NEW.creado_por, NEW.aprobado_por, NEW.emitido_por, NEW.emitido_at)
                       IS DISTINCT FROM
                       (OLD.empresa_id, OLD.local_id, OLD.tipo_dte, OLD.folio, OLD.cliente_id, OLD.receptor_rut,
                        OLD.receptor_razon_social, OLD.receptor_giro, OLD.receptor_direccion, OLD.receptor_comuna,
                        OLD.fecha_emision, OLD.fecha_vencimiento, OLD.forma_pago, OLD.descuento_global_pct,
                        OLD.descuento_global_monto, OLD.tasa_iva, OLD.iva_incluido, OLD.monto_neto, OLD.monto_exento, OLD.monto_iva,
                        OLD.monto_total, OLD.creado_por, OLD.aprobado_por, OLD.emitido_por, OLD.emitido_at) THEN
                        RAISE EXCEPTION 'Documento % folio % emitido: es inmutable, corrígelo con nota de crédito', OLD.tipo_dte, OLD.folio
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                    IF NEW.estado IN ('borrador', 'por_aprobar') THEN
                        RAISE EXCEPTION 'Un documento emitido no vuelve a borrador' USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;

                -- Al emitir, la cabecera debe cuadrar al peso con la suma de sus líneas.
                IF NEW.estado = 'emitido' AND OLD.estado IN ('borrador', 'por_aprobar') THEN
                    PERFORM documentos_verificar_cuadratura(NEW);
                END IF;
                RETURN NEW;
            END $$;

            CREATE OR REPLACE FUNCTION documentos_verificar_cuadratura(d documentos) RETURNS void LANGUAGE plpgsql AS $$
            DECLARE afecto bigint; exento bigint; n int;
            BEGIN
                SELECT coalesce(sum(monto_item) FILTER (WHERE NOT l.exento), 0),
                       coalesce(sum(monto_item) FILTER (WHERE l.exento), 0),
                       count(*)
                  INTO afecto, exento, n
                  FROM documento_lineas l WHERE l.documento_id = d.id;

                IF n < 1 OR n > 60 THEN
                    RAISE EXCEPTION 'El documento debe tener entre 1 y 60 líneas (tiene %)', n USING ERRCODE = 'check_violation';
                END IF;
                IF d.monto_exento <> exento THEN
                    RAISE EXCEPTION 'Exento de cabecera (%) no cuadra con las líneas (%)', d.monto_exento, exento USING ERRCODE = 'check_violation';
                END IF;
                IF d.iva_incluido THEN
                    IF d.monto_neto + d.monto_iva <> afecto - d.descuento_global_monto THEN
                        RAISE EXCEPTION 'Afecto con IVA incluido (%) no cuadra con las líneas (%)', d.monto_neto + d.monto_iva, afecto - d.descuento_global_monto USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF d.monto_neto <> afecto - d.descuento_global_monto THEN
                    RAISE EXCEPTION 'Neto de cabecera (%) no cuadra con las líneas (%)', d.monto_neto, afecto - d.descuento_global_monto USING ERRCODE = 'check_violation';
                END IF;
            END $$;

            CREATE TRIGGER documentos_inmutables BEFORE UPDATE OR DELETE ON documentos
                FOR EACH ROW EXECUTE FUNCTION documentos_inmutables();

            -- Líneas y referencias solo se tocan mientras el documento es borrador.
            CREATE OR REPLACE FUNCTION documento_hijos_congelados() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE est text;
            BEGIN
                SELECT estado INTO est FROM documentos WHERE id = coalesce(NEW.documento_id, OLD.documento_id);
                IF est IS NOT NULL AND est NOT IN ('borrador', 'por_aprobar') THEN
                    RAISE EXCEPTION 'El documento ya fue emitido: sus líneas y referencias no cambian'
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN coalesce(NEW, OLD);
            END $$;
            CREATE TRIGGER documento_lineas_congeladas BEFORE INSERT OR UPDATE OR DELETE ON documento_lineas
                FOR EACH ROW EXECUTE FUNCTION documento_hijos_congelados();
            CREATE TRIGGER documento_referencias_congeladas BEFORE INSERT OR UPDATE OR DELETE ON documento_referencias
                FOR EACH ROW EXECUTE FUNCTION documento_hijos_congelados();
        SQL);

        foreach (['folio_rangos', 'documentos', 'documento_lineas', 'documento_referencias', 'pagos'] as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS documento_referencias_congeladas ON documento_referencias; DROP TRIGGER IF EXISTS documento_lineas_congeladas ON documento_lineas; DROP TRIGGER IF EXISTS documentos_inmutables ON documentos; DROP TRIGGER IF EXISTS folio_rangos_sin_solape ON folio_rangos;');
        Schema::dropIfExists('pagos');
        Schema::dropIfExists('documento_referencias');
        Schema::dropIfExists('documento_lineas');
        Schema::dropIfExists('documentos');
        Schema::dropIfExists('folio_rangos');
        DB::unprepared('DROP FUNCTION IF EXISTS documento_hijos_congelados(); DROP FUNCTION IF EXISTS documentos_verificar_cuadratura(documentos); DROP FUNCTION IF EXISTS documentos_inmutables(); DROP FUNCTION IF EXISTS folio_rangos_sin_solape();');
    }
};
