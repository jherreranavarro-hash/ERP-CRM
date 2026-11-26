<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3b: actualización de precios con historial y aprobación, despachos con
 * máquina de estados explícita (validada también en la base de datos) y
 * devoluciones que generan la nota de crédito y reingresan el stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Precios ──
        Schema::create('cambios_precio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->unsignedInteger('numero');
            $table->string('descripcion', 150);
            $table->string('estado', 12);
            $table->jsonb('motivos')->nullable();
            $table->foreignId('solicitado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->string('motivo_rechazo', 200)->nullable();
            $table->timestampTz('aplicado_at')->nullable();
            $table->timestampsTz();
            $table->unique(['empresa_id', 'numero']);
        });

        Schema::create('cambio_precio_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('cambio_precio_id')->constrained('cambios_precio')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->string('campo', 20);
            $table->decimal('anterior', 18, 6)->nullable();
            $table->decimal('nuevo', 18, 6)->nullable();
            $table->decimal('variacion_pct', 9, 2)->nullable();
            $table->unique(['cambio_precio_id', 'producto_id', 'campo']);
        });

        Schema::create('historial_precios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('producto_id')->constrained('productos');
            $table->string('campo', 20);
            $table->decimal('anterior', 18, 6)->nullable();
            $table->decimal('nuevo', 18, 6)->nullable();
            $table->string('origen', 20);
            $table->unsignedBigInteger('cambio_precio_id')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['empresa_id', 'producto_id', 'id']);
        });

        // ── Despachos ──
        Schema::create('despachos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->unsignedInteger('numero');
            $table->foreignId('documento_id')->constrained('documentos');
            $table->foreignId('local_id')->constrained('locales');
            $table->foreignId('cliente_id')->nullable()->constrained('clientes');
            $table->string('estado', 12);
            $table->string('direccion', 150);
            $table->string('comuna', 60);
            $table->string('contacto', 100)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->date('fecha_programada');
            $table->string('ventana', 5)->nullable();          // am | pm
            $table->string('transportista', 30)->default('propio');
            $table->string('tracking', 60)->nullable();
            $table->bigInteger('cobro')->default(0);           // cargo de despacho, pesos
            $table->jsonb('motivos')->nullable();               // por qué requiere aprobación
            $table->foreignId('creado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->string('motivo_fallo', 200)->nullable();
            $table->string('recibido_nombre', 100)->nullable();
            $table->string('recibido_rut', 12)->nullable();
            $table->string('firma_path', 200)->nullable();
            $table->timestampTz('entregado_at')->nullable();
            $table->string('observaciones', 300)->nullable();
            $table->timestampsTz();
            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado', 'fecha_programada']);
        });

        Schema::create('despacho_eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('despacho_id')->constrained('despachos');
            $table->string('de', 12)->nullable();
            $table->string('a', 12);
            $table->string('nota', 200)->nullable();
            $table->foreignId('usuario_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['despacho_id', 'id']);
        });

        // ── Devoluciones ──
        Schema::create('devoluciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->unsignedInteger('numero');
            $table->foreignId('documento_id')->constrained('documentos');
            $table->foreignId('local_id')->constrained('locales');
            $table->string('motivo', 20);
            $table->string('detalle', 300);
            $table->string('estado', 12);
            $table->bigInteger('monto_estimado');
            $table->foreignId('nota_credito_id')->nullable()->constrained('documentos');
            $table->foreignId('solicitado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->string('motivo_rechazo', 200)->nullable();
            $table->timestampsTz();
            $table->unique(['empresa_id', 'numero']);
        });

        Schema::create('devolucion_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('devolucion_id')->constrained('devoluciones')->cascadeOnDelete();
            $table->foreignId('documento_linea_id')->constrained('documento_lineas');
            $table->foreignId('producto_id')->nullable()->constrained('productos');
            $table->decimal('cantidad', 18, 6);
            $table->boolean('reingresa')->default(true);       // false: producto dañado, no vuelve a stock
            $table->unique(['devolucion_id', 'documento_linea_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cambios_precio
                ADD CONSTRAINT cambios_precio_estado CHECK (estado IN ('por_aprobar', 'aplicado', 'rechazado')),
                ADD CONSTRAINT cambios_precio_aplicado CHECK ((estado = 'aplicado') = (aplicado_at IS NOT NULL));
            ALTER TABLE cambio_precio_lineas
                ADD CONSTRAINT cambio_precio_campo CHECK (campo IN ('precio_neto', 'precio_mayorista')),
                ADD CONSTRAINT cambio_precio_nuevo CHECK (nuevo IS NULL OR nuevo >= 0);
            ALTER TABLE historial_precios
                ADD CONSTRAINT historial_precios_campo CHECK (campo IN ('precio_neto', 'precio_mayorista', 'costo'));

            ALTER TABLE despachos
                ADD CONSTRAINT despachos_estado CHECK (estado IN ('por_aprobar', 'programado', 'en_ruta', 'entregado', 'fallido', 'anulado')),
                ADD CONSTRAINT despachos_ventana CHECK (ventana IS NULL OR ventana IN ('am', 'pm')),
                ADD CONSTRAINT despachos_cobro CHECK (cobro >= 0),
                -- Entregado exige quién recibió y la firma.
                ADD CONSTRAINT despachos_entrega CHECK (estado <> 'entregado' OR (recibido_nombre IS NOT NULL AND firma_path IS NOT NULL AND entregado_at IS NOT NULL)),
                ADD CONSTRAINT despachos_fallo CHECK (estado <> 'fallido' OR motivo_fallo IS NOT NULL);

            ALTER TABLE devoluciones
                ADD CONSTRAINT devoluciones_estado CHECK (estado IN ('solicitada', 'aprobada', 'rechazada')),
                ADD CONSTRAINT devoluciones_motivo CHECK (motivo IN ('falla', 'error_despacho', 'arrepentimiento', 'otro')),
                ADD CONSTRAINT devoluciones_aprobada CHECK (estado <> 'aprobada' OR nota_credito_id IS NOT NULL);
            ALTER TABLE devolucion_lineas
                ADD CONSTRAINT devolucion_lineas_cantidad CHECK (cantidad > 0);

            -- El kardex suma el reingreso por devolución.
            ALTER TABLE movimientos_stock DROP CONSTRAINT movimientos_tipo, DROP CONSTRAINT movimientos_signo;
            ALTER TABLE movimientos_stock
                ADD CONSTRAINT movimientos_tipo CHECK (tipo IN ('recepcion', 'venta', 'anulacion_venta', 'devolucion', 'merma', 'ajuste')),
                ADD CONSTRAINT movimientos_signo CHECK (
                    (tipo IN ('recepcion', 'anulacion_venta', 'devolucion') AND cantidad > 0) OR
                    (tipo IN ('venta', 'merma') AND cantidad < 0) OR tipo = 'ajuste');

            -- Historial de precios: lo escribe la base de datos en todo cambio,
            -- venga de donde venga (mantenedor, actualización masiva, recepción).
            CREATE OR REPLACE FUNCTION productos_historial_precios() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                origen text := coalesce(nullif(current_setting('app.origen_precio', true), ''), 'mantenedor');
                cambio bigint := nullif(current_setting('app.cambio_precio_id', true), '')::bigint;
                usuario bigint := nullif(current_setting('app.usuario_id', true), '')::bigint;
            BEGIN
                IF NEW.precio_neto IS DISTINCT FROM OLD.precio_neto THEN
                    INSERT INTO historial_precios (empresa_id, producto_id, campo, anterior, nuevo, origen, cambio_precio_id, usuario_id)
                    VALUES (NEW.empresa_id, NEW.id, 'precio_neto', OLD.precio_neto, NEW.precio_neto, origen, cambio, usuario);
                END IF;
                IF NEW.precio_mayorista IS DISTINCT FROM OLD.precio_mayorista THEN
                    INSERT INTO historial_precios (empresa_id, producto_id, campo, anterior, nuevo, origen, cambio_precio_id, usuario_id)
                    VALUES (NEW.empresa_id, NEW.id, 'precio_mayorista', OLD.precio_mayorista, NEW.precio_mayorista, origen, cambio, usuario);
                END IF;
                IF NEW.costo IS DISTINCT FROM OLD.costo THEN
                    INSERT INTO historial_precios (empresa_id, producto_id, campo, anterior, nuevo, origen, cambio_precio_id, usuario_id)
                    VALUES (NEW.empresa_id, NEW.id, 'costo', OLD.costo, NEW.costo, origen, cambio, usuario);
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER productos_historial_precios AFTER UPDATE OF precio_neto, precio_mayorista, costo ON productos
                FOR EACH ROW EXECUTE FUNCTION productos_historial_precios();

            CREATE OR REPLACE FUNCTION solo_insercion() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% es de solo inserción', TG_TABLE_NAME;
            END $$;
            CREATE TRIGGER historial_precios_inmutable BEFORE UPDATE OR DELETE ON historial_precios
                FOR EACH ROW EXECUTE FUNCTION solo_insercion();
            CREATE TRIGGER despacho_eventos_inmutable BEFORE UPDATE OR DELETE ON despacho_eventos
                FOR EACH ROW EXECUTE FUNCTION solo_insercion();

            -- Máquina de estados del despacho: solo transiciones permitidas, y cada
            -- cambio queda en despacho_eventos (dentro de la misma transacción).
            CREATE OR REPLACE FUNCTION despachos_transicion() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un despacho no se elimina: se anula';
                END IF;
                IF OLD.estado IN ('entregado', 'anulado') THEN
                    RAISE EXCEPTION 'El despacho % está cerrado (%)', OLD.numero, OLD.estado;
                END IF;
                IF NEW.estado IS DISTINCT FROM OLD.estado AND NOT (
                    (OLD.estado = 'por_aprobar' AND NEW.estado IN ('programado', 'anulado')) OR
                    (OLD.estado = 'programado'  AND NEW.estado IN ('en_ruta', 'anulado')) OR
                    (OLD.estado = 'en_ruta'     AND NEW.estado IN ('entregado', 'fallido')) OR
                    (OLD.estado = 'fallido'     AND NEW.estado IN ('programado', 'anulado'))
                ) THEN
                    RAISE EXCEPTION 'Transición de despacho no permitida: % → %', OLD.estado, NEW.estado;
                END IF;
                IF NEW.documento_id <> OLD.documento_id OR NEW.numero <> OLD.numero THEN
                    RAISE EXCEPTION 'El documento y el número de un despacho no cambian';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER despachos_transicion BEFORE UPDATE OR DELETE ON despachos
                FOR EACH ROW EXECUTE FUNCTION despachos_transicion();

            -- Una devolución resuelta no cambia.
            CREATE OR REPLACE FUNCTION devoluciones_congeladas() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.estado <> 'solicitada' THEN
                    RAISE EXCEPTION 'La devolución % ya está resuelta', OLD.numero;
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END $$;
            CREATE TRIGGER devoluciones_congeladas BEFORE UPDATE OR DELETE ON devoluciones
                FOR EACH ROW EXECUTE FUNCTION devoluciones_congeladas();
        SQL);

        foreach (['cambios_precio', 'cambio_precio_lineas', 'historial_precios', 'despachos', 'despacho_eventos', 'devoluciones', 'devolucion_lineas'] as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS productos_historial_precios ON productos;
            ALTER TABLE movimientos_stock DROP CONSTRAINT movimientos_tipo, DROP CONSTRAINT movimientos_signo;
            ALTER TABLE movimientos_stock
                ADD CONSTRAINT movimientos_tipo CHECK (tipo IN ('recepcion', 'venta', 'anulacion_venta', 'merma', 'ajuste')),
                ADD CONSTRAINT movimientos_signo CHECK (
                    (tipo IN ('recepcion', 'anulacion_venta') AND cantidad > 0) OR
                    (tipo IN ('venta', 'merma') AND cantidad < 0) OR tipo = 'ajuste');
        SQL);
        foreach (['devolucion_lineas', 'devoluciones', 'despacho_eventos', 'despachos', 'historial_precios', 'cambio_precio_lineas', 'cambios_precio'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS devoluciones_congeladas(); DROP FUNCTION IF EXISTS despachos_transicion(); DROP FUNCTION IF EXISTS solo_insercion(); DROP FUNCTION IF EXISTS productos_historial_precios();');
    }
};
