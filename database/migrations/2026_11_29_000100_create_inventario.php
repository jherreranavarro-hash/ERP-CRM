<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventario: stock por producto y local, kardex inmutable, recepciones,
 * mermas y órdenes de venta con reserva.
 *
 * El kardex manda: el stock solo cambia al insertar un movimiento, y el
 * trigger del movimiento calcula el saldo y el costo promedio ponderado con
 * la fila de stock bloqueada. Así el stock siempre es la suma del kardex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('local_id')->constrained('locales');
            $table->decimal('cantidad', 18, 6)->default(0);
            $table->decimal('costo_promedio', 18, 6)->default(0);
            $table->decimal('minimo', 18, 6)->nullable();
            $table->timestamps();
            $table->unique(['producto_id', 'local_id']);
            $table->index(['empresa_id', 'local_id']);
        });

        Schema::create('movimientos_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('producto_id')->constrained('productos');
            $table->foreignId('local_id')->constrained('locales');
            $table->string('tipo', 20);
            $table->decimal('cantidad', 18, 6);                 // con signo: + entra, − sale
            $table->decimal('costo_unitario', 18, 6)->nullable();
            $table->decimal('saldo_cantidad', 18, 6)->default(0);   // lo calcula el trigger
            $table->decimal('saldo_costo_promedio', 18, 6)->default(0);
            $table->string('origen_tipo', 30)->nullable();
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->string('referencia', 120)->nullable();
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['empresa_id', 'producto_id', 'local_id', 'id']);
            $table->index(['origen_tipo', 'origen_id']);
        });

        Schema::create('recepciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('local_id')->constrained('locales');
            $table->unsignedInteger('numero');
            $table->string('estado', 12)->default('borrador');
            $table->string('proveedor_rut', 12)->nullable();
            $table->string('proveedor_nombre', 100);
            $table->string('documento_proveedor', 40)->nullable();   // N° factura o guía del proveedor
            $table->date('fecha');
            $table->bigInteger('costo_total')->default(0);
            $table->text('observaciones')->nullable();
            $table->foreignId('creado_por')->constrained('users');
            $table->foreignId('confirmado_por')->nullable()->constrained('users');
            $table->timestampTz('confirmado_at')->nullable();
            $table->timestamps();
            $table->unique(['empresa_id', 'numero']);
        });

        Schema::create('recepcion_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('recepcion_id')->constrained('recepciones')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad', 18, 6);
            $table->decimal('costo_unitario', 18, 6);
            $table->timestamps();
        });

        Schema::create('mermas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('local_id')->constrained('locales');
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad', 18, 6);
            $table->string('motivo', 12);
            $table->string('detalle', 300);
            $table->decimal('costo_unitario', 18, 6)->default(0);
            $table->bigInteger('costo_total')->default(0);
            $table->string('estado', 12);
            $table->foreignId('responsable_id')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestampTz('aprobado_at')->nullable();
            $table->string('motivo_rechazo', 200)->nullable();
            $table->timestamps();
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('ordenes_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('local_id')->constrained('locales');
            $table->unsignedInteger('numero');
            $table->string('estado', 12)->default('confirmada');
            $table->foreignId('cliente_id')->constrained('clientes');
            $table->foreignId('cotizacion_id')->nullable()->constrained('cotizaciones');
            $table->foreignId('documento_id')->nullable()->constrained('documentos')->nullOnDelete();   // si se elimina el borrador, la orden queda libre
            $table->string('forma_pago', 8)->default('contado');
            $table->date('fecha');
            $table->date('reserva_hasta');
            $table->decimal('descuento_global_pct', 5, 2)->default(0);
            $table->bigInteger('descuento_global_monto')->default(0);
            $table->decimal('tasa_iva', 5, 2);
            $table->bigInteger('monto_neto')->default(0);
            $table->bigInteger('monto_exento')->default(0);
            $table->bigInteger('monto_iva')->default(0);
            $table->bigInteger('monto_total')->default(0);
            $table->text('observaciones')->nullable();
            $table->string('motivo_anulacion', 200)->nullable();
            $table->foreignId('creado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('orden_venta_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('orden_venta_id')->constrained('ordenes_venta')->cascadeOnDelete();
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
            $table->unique(['orden_venta_id', 'orden']);
            $table->index(['producto_id']);
        });

        Schema::table('documentos', function (Blueprint $table) {
            $table->foreignId('orden_venta_id')->nullable()->after('cotizacion_id')->constrained('ordenes_venta');
        });
        Schema::table('cotizaciones', function (Blueprint $table) {
            $table->foreignId('orden_venta_id')->nullable()->after('documento_id')->constrained('ordenes_venta');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE stock
                ADD CONSTRAINT stock_costo CHECK (costo_promedio >= 0),
                ADD CONSTRAINT stock_minimo CHECK (minimo IS NULL OR minimo >= 0);

            ALTER TABLE movimientos_stock
                ADD CONSTRAINT movimientos_tipo CHECK (tipo IN ('recepcion', 'venta', 'anulacion_venta', 'merma', 'ajuste')),
                ADD CONSTRAINT movimientos_cantidad CHECK (cantidad <> 0),
                ADD CONSTRAINT movimientos_costo CHECK (costo_unitario IS NULL OR costo_unitario >= 0),
                -- Entradas y salidas en el sentido de su tipo.
                ADD CONSTRAINT movimientos_signo CHECK (
                    (tipo IN ('recepcion', 'anulacion_venta') AND cantidad > 0) OR
                    (tipo IN ('venta', 'merma') AND cantidad < 0) OR tipo = 'ajuste');

            ALTER TABLE recepciones
                ADD CONSTRAINT recepciones_estado CHECK (estado IN ('borrador', 'confirmada')),
                ADD CONSTRAINT recepciones_costo CHECK (costo_total >= 0),
                ADD CONSTRAINT recepciones_confirmada CHECK ((estado = 'confirmada') = (confirmado_at IS NOT NULL));
            ALTER TABLE recepcion_lineas
                ADD CONSTRAINT recepcion_lineas_cantidad CHECK (cantidad > 0),
                ADD CONSTRAINT recepcion_lineas_costo CHECK (costo_unitario >= 0);

            ALTER TABLE mermas
                ADD CONSTRAINT mermas_cantidad CHECK (cantidad > 0),
                ADD CONSTRAINT mermas_motivo CHECK (motivo IN ('rotura', 'vencimiento', 'robo', 'diferencia', 'otro')),
                ADD CONSTRAINT mermas_estado CHECK (estado IN ('por_aprobar', 'registrada', 'rechazada')),
                ADD CONSTRAINT mermas_detalle CHECK (char_length(trim(detalle)) > 0);

            ALTER TABLE ordenes_venta
                ADD CONSTRAINT ov_estado CHECK (estado IN ('confirmada', 'facturada', 'anulada')),
                ADD CONSTRAINT ov_forma_pago CHECK (forma_pago IN ('contado', 'credito')),
                ADD CONSTRAINT ov_total CHECK (monto_total = monto_neto + monto_exento + monto_iva),
                ADD CONSTRAINT ov_facturada CHECK (estado <> 'facturada' OR documento_id IS NOT NULL);
            ALTER TABLE orden_venta_lineas
                ADD CONSTRAINT ov_lineas_cantidad CHECK (cantidad > 0),
                ADD CONSTRAINT ov_lineas_monto CHECK (monto_item >= 0);

            -- Una cotización convertida apunta a su documento o a su orden de venta.
            ALTER TABLE cotizaciones DROP CONSTRAINT cotizaciones_convertida;
            ALTER TABLE cotizaciones ADD CONSTRAINT cotizaciones_convertida
                CHECK ((estado = 'convertida') = (documento_id IS NOT NULL OR orden_venta_id IS NOT NULL));

            -- Kardex: cada movimiento actualiza el stock con la fila bloqueada y deja su saldo.
            CREATE OR REPLACE FUNCTION movimientos_stock_aplicar() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE s stock%ROWTYPE; nueva numeric(18,6); prom numeric(18,6);
            BEGIN
                INSERT INTO stock (empresa_id, producto_id, local_id, cantidad, costo_promedio, created_at, updated_at)
                VALUES (NEW.empresa_id, NEW.producto_id, NEW.local_id, 0, 0, now(), now())
                ON CONFLICT (producto_id, local_id) DO NOTHING;
                SELECT * INTO s FROM stock WHERE producto_id = NEW.producto_id AND local_id = NEW.local_id FOR UPDATE;

                nueva := s.cantidad + NEW.cantidad;
                prom := s.costo_promedio;
                IF NEW.cantidad > 0 AND NEW.costo_unitario IS NOT NULL THEN
                    -- Promedio ponderado; si el stock previo no era positivo, manda el costo de la entrada.
                    IF s.cantidad > 0 THEN
                        prom := round((s.cantidad * s.costo_promedio + NEW.cantidad * NEW.costo_unitario) / nueva, 6);
                    ELSE
                        prom := NEW.costo_unitario;
                    END IF;
                ELSIF NEW.cantidad < 0 THEN
                    -- Una salida se valoriza al costo promedio vigente.
                    NEW.costo_unitario := s.costo_promedio;
                END IF;

                NEW.saldo_cantidad := nueva;
                NEW.saldo_costo_promedio := prom;
                UPDATE stock SET cantidad = nueva, costo_promedio = prom, updated_at = now() WHERE id = s.id;
                RETURN NEW;
            END $$;
            CREATE TRIGGER movimientos_stock_aplicar BEFORE INSERT ON movimientos_stock
                FOR EACH ROW EXECUTE FUNCTION movimientos_stock_aplicar();

            CREATE OR REPLACE FUNCTION movimientos_stock_inmutables() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'El kardex es solo inserción: corrige con un movimiento de ajuste' USING ERRCODE = 'integrity_constraint_violation';
            END $$;
            CREATE TRIGGER movimientos_stock_inmutables BEFORE UPDATE OR DELETE ON movimientos_stock
                FOR EACH ROW EXECUTE FUNCTION movimientos_stock_inmutables();

            -- El stock solo lo mueve el kardex (salvo el mínimo, que es configuración).
            CREATE OR REPLACE FUNCTION stock_solo_por_kardex() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF pg_trigger_depth() = 1 AND (NEW.cantidad, NEW.costo_promedio) IS DISTINCT FROM (OLD.cantidad, OLD.costo_promedio) THEN
                    RAISE EXCEPTION 'El stock cambia solo con movimientos de kardex' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER stock_solo_por_kardex BEFORE UPDATE ON stock
                FOR EACH ROW EXECUTE FUNCTION stock_solo_por_kardex();

            -- Recepción confirmada: congelada, igual que sus líneas.
            CREATE OR REPLACE FUNCTION recepciones_congeladas() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.estado = 'confirmada' THEN
                    RAISE EXCEPTION 'La recepción N° % ya está confirmada: corrige con un ajuste', OLD.numero USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN coalesce(NEW, OLD);
            END $$;
            CREATE TRIGGER recepciones_congeladas BEFORE UPDATE OR DELETE ON recepciones
                FOR EACH ROW EXECUTE FUNCTION recepciones_congeladas();
            CREATE OR REPLACE FUNCTION recepcion_lineas_congeladas() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF (SELECT estado FROM recepciones WHERE id = coalesce(NEW.recepcion_id, OLD.recepcion_id)) = 'confirmada' THEN
                    RAISE EXCEPTION 'La recepción ya está confirmada' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN coalesce(NEW, OLD);
            END $$;
            CREATE TRIGGER recepcion_lineas_congeladas BEFORE INSERT OR UPDATE OR DELETE ON recepcion_lineas
                FOR EACH ROW EXECUTE FUNCTION recepcion_lineas_congeladas();
        SQL);

        foreach (['stock', 'movimientos_stock', 'recepciones', 'recepcion_lineas', 'mermas', 'ordenes_venta', 'orden_venta_lineas'] as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS recepcion_lineas_congeladas ON recepcion_lineas;
            DROP TRIGGER IF EXISTS recepciones_congeladas ON recepciones;
            DROP TRIGGER IF EXISTS stock_solo_por_kardex ON stock;
            DROP TRIGGER IF EXISTS movimientos_stock_inmutables ON movimientos_stock;
            DROP TRIGGER IF EXISTS movimientos_stock_aplicar ON movimientos_stock;
            ALTER TABLE cotizaciones DROP CONSTRAINT cotizaciones_convertida;
        SQL);
        Schema::table('cotizaciones', fn (Blueprint $t) => $t->dropConstrainedForeignId('orden_venta_id'));
        Schema::table('documentos', fn (Blueprint $t) => $t->dropConstrainedForeignId('orden_venta_id'));
        DB::unprepared("ALTER TABLE cotizaciones ADD CONSTRAINT cotizaciones_convertida CHECK ((estado = 'convertida') = (documento_id IS NOT NULL))");
        foreach (['orden_venta_lineas', 'ordenes_venta', 'mermas', 'recepcion_lineas', 'recepciones', 'movimientos_stock', 'stock'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS recepcion_lineas_congeladas(); DROP FUNCTION IF EXISTS recepciones_congeladas(); DROP FUNCTION IF EXISTS stock_solo_por_kardex(); DROP FUNCTION IF EXISTS movimientos_stock_inmutables(); DROP FUNCTION IF EXISTS movimientos_stock_aplicar();');
    }
};
