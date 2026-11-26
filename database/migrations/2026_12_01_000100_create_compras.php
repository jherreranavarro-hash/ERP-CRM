<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3c: compras. Proveedores, órdenes de compra con comparación de
 * cotizaciones y aprobación por monto, recepción contra OC, facturas de
 * proveedor con 3-way match (OC × recepción × factura) que generan la cuenta
 * por pagar, y pagos a proveedores con segunda aprobación sobre el umbral.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('rut', 12);
            $table->string('razon_social', 100);
            $table->string('giro', 80)->nullable();
            $table->string('direccion', 100)->nullable();
            $table->string('comuna', 40)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('telefono', 30)->nullable();
            $table->string('contacto', 100)->nullable();
            $table->unsignedSmallInteger('plazo_pago_dias')->default(30);
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['empresa_id', 'rut']);
        });

        Schema::create('ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->unsignedInteger('numero');
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('local_id')->constrained('locales');       // dónde se recibe
            $table->string('estado', 16);
            $table->date('fecha');
            $table->date('fecha_entrega')->nullable();
            $table->bigInteger('monto_neto')->default(0);
            $table->bigInteger('monto_iva')->default(0);
            $table->bigInteger('monto_total')->default(0);
            $table->jsonb('motivos')->nullable();
            $table->string('observaciones', 500)->nullable();
            $table->foreignId('creado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestampTz('aprobado_at')->nullable();
            $table->string('motivo_rechazo', 200)->nullable();
            $table->timestampsTz();
            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('orden_compra_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos');
            $table->decimal('cantidad', 18, 6);
            $table->decimal('costo_unitario', 18, 6);
            $table->bigInteger('monto');
            $table->decimal('cantidad_recibida', 18, 6)->default(0);
            $table->decimal('cantidad_facturada', 18, 6)->default(0);
        });

        // Cotizaciones de proveedores comparadas para una OC.
        Schema::create('orden_compra_cotizaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $table->foreignId('proveedor_id')->nullable()->constrained('proveedores');
            $table->string('proveedor_nombre', 100);
            $table->bigInteger('monto_neto');
            $table->unsignedSmallInteger('plazo_entrega_dias')->nullable();
            $table->string('observacion', 200)->nullable();
            $table->boolean('elegida')->default(false);
        });

        Schema::table('recepciones', function (Blueprint $table) {
            $table->foreignId('orden_compra_id')->nullable()->after('local_id')->constrained('ordenes_compra');
            $table->foreignId('proveedor_id')->nullable()->after('orden_compra_id')->constrained('proveedores');
        });
        Schema::table('recepcion_lineas', function (Blueprint $table) {
            $table->foreignId('orden_compra_linea_id')->nullable()->after('producto_id')->constrained('orden_compra_lineas');
        });

        Schema::create('facturas_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->unsignedSmallInteger('tipo_dte');                      // 33 factura, 34 exenta, 46 factura de compra
            $table->unsignedBigInteger('folio');
            $table->date('fecha_emision');
            $table->date('fecha_vencimiento');
            $table->foreignId('orden_compra_id')->nullable()->constrained('ordenes_compra');
            $table->bigInteger('monto_neto');
            $table->bigInteger('monto_exento')->default(0);
            $table->bigInteger('monto_iva');
            $table->bigInteger('monto_total');
            $table->bigInteger('saldo');
            $table->string('estado', 12);
            $table->jsonb('diferencias')->nullable();                      // resultado del 3-way match
            $table->foreignId('registrado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->string('motivo_rechazo', 200)->nullable();
            $table->string('observaciones', 300)->nullable();
            $table->timestampsTz();
            $table->unique(['empresa_id', 'proveedor_id', 'tipo_dte', 'folio']);
            $table->index(['empresa_id', 'estado', 'fecha_vencimiento']);
        });

        Schema::create('factura_proveedor_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('factura_proveedor_id')->constrained('facturas_proveedor')->cascadeOnDelete();
            $table->foreignId('orden_compra_linea_id')->nullable()->constrained('orden_compra_lineas');
            $table->foreignId('producto_id')->nullable()->constrained('productos');
            $table->string('descripcion', 80);
            $table->decimal('cantidad', 18, 6);
            $table->decimal('costo_unitario', 18, 6);
            $table->bigInteger('monto');
        });

        Schema::create('pagos_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('factura_proveedor_id')->constrained('facturas_proveedor');
            $table->bigInteger('monto');
            $table->date('fecha');
            $table->string('metodo', 15);
            $table->string('referencia', 60)->nullable();
            $table->string('estado', 12);
            $table->foreignId('registrado_por')->constrained('users');
            $table->foreignId('aprobado_por')->nullable()->constrained('users');
            $table->timestampTz('aprobado_at')->nullable();
            $table->string('motivo_rechazo', 200)->nullable();
            $table->timestampsTz();
            $table->index(['empresa_id', 'estado']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE ordenes_compra
                ADD CONSTRAINT oc_estado CHECK (estado IN ('borrador', 'por_aprobar', 'aprobada', 'parcial', 'recibida', 'cerrada', 'anulada', 'rechazada')),
                ADD CONSTRAINT oc_total CHECK (monto_total = monto_neto + monto_iva AND monto_neto >= 0),
                ADD CONSTRAINT oc_aprobada CHECK (estado NOT IN ('aprobada', 'parcial', 'recibida') OR aprobado_at IS NOT NULL);
            ALTER TABLE orden_compra_lineas
                ADD CONSTRAINT oc_lineas_cantidad CHECK (cantidad > 0 AND costo_unitario >= 0 AND monto >= 0),
                -- Nunca se recibe ni se factura más de lo pedido (la tolerancia se aplica antes, en el servicio).
                ADD CONSTRAINT oc_lineas_recibida CHECK (cantidad_recibida >= 0),
                ADD CONSTRAINT oc_lineas_facturada CHECK (cantidad_facturada >= 0 AND cantidad_facturada <= cantidad_recibida);
            ALTER TABLE orden_compra_cotizaciones
                ADD CONSTRAINT oc_cotizaciones_monto CHECK (monto_neto > 0);
            CREATE UNIQUE INDEX oc_cotizacion_elegida ON orden_compra_cotizaciones (orden_compra_id) WHERE elegida;

            ALTER TABLE facturas_proveedor
                ADD CONSTRAINT fp_tipo CHECK (tipo_dte IN (33, 34, 46)),
                ADD CONSTRAINT fp_estado CHECK (estado IN ('por_revisar', 'por_pagar', 'pagada', 'rechazada')),
                ADD CONSTRAINT fp_total CHECK (monto_total = monto_neto + monto_exento + monto_iva),
                ADD CONSTRAINT fp_saldo CHECK (saldo >= 0 AND saldo <= monto_total),
                ADD CONSTRAINT fp_pagada CHECK (estado <> 'pagada' OR saldo = 0),
                ADD CONSTRAINT fp_vencimiento CHECK (fecha_vencimiento >= fecha_emision);
            ALTER TABLE factura_proveedor_lineas
                ADD CONSTRAINT fp_lineas CHECK (cantidad > 0 AND costo_unitario >= 0 AND monto >= 0);

            ALTER TABLE pagos_proveedor
                ADD CONSTRAINT pp_monto CHECK (monto > 0),
                ADD CONSTRAINT pp_metodo CHECK (metodo IN ('transferencia', 'cheque', 'efectivo', 'vale_vista')),
                ADD CONSTRAINT pp_estado CHECK (estado IN ('por_aprobar', 'aplicado', 'rechazado')),
                ADD CONSTRAINT pp_aprobado CHECK (estado <> 'aplicado' OR aprobado_at IS NOT NULL);

            -- Un pago aplicado no se modifica ni se borra (se revierte con otro registro).
            CREATE OR REPLACE FUNCTION pagos_proveedor_congelados() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.estado <> 'por_aprobar' THEN
                    RAISE EXCEPTION 'El pago % ya está resuelto', OLD.id;
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END $$;
            CREATE TRIGGER pagos_proveedor_congelados BEFORE UPDATE OR DELETE ON pagos_proveedor
                FOR EACH ROW EXECUTE FUNCTION pagos_proveedor_congelados();

            -- Las líneas y montos de una factura registrada no cambian.
            CREATE OR REPLACE FUNCTION facturas_proveedor_montos() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una factura de proveedor no se elimina: se rechaza';
                END IF;
                IF (NEW.monto_neto, NEW.monto_exento, NEW.monto_iva, NEW.monto_total, NEW.folio, NEW.proveedor_id, NEW.tipo_dte)
                    IS DISTINCT FROM (OLD.monto_neto, OLD.monto_exento, OLD.monto_iva, OLD.monto_total, OLD.folio, OLD.proveedor_id, OLD.tipo_dte) THEN
                    RAISE EXCEPTION 'Los montos y la identificación de una factura de proveedor no cambian';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER facturas_proveedor_montos BEFORE UPDATE OR DELETE ON facturas_proveedor
                FOR EACH ROW EXECUTE FUNCTION facturas_proveedor_montos();

            CREATE INDEX proveedores_busqueda ON proveedores (empresa_id, razon_social);
        SQL);

        foreach (['proveedores', 'ordenes_compra', 'orden_compra_lineas', 'orden_compra_cotizaciones', 'facturas_proveedor', 'factura_proveedor_lineas', 'pagos_proveedor'] as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        Schema::table('recepcion_lineas', fn (Blueprint $t) => $t->dropConstrainedForeignId('orden_compra_linea_id'));
        Schema::table('recepciones', function (Blueprint $t) {
            $t->dropConstrainedForeignId('orden_compra_id');
            $t->dropConstrainedForeignId('proveedor_id');
        });
        foreach (['pagos_proveedor', 'factura_proveedor_lineas', 'facturas_proveedor', 'orden_compra_cotizaciones', 'orden_compra_lineas', 'ordenes_compra', 'proveedores'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS pagos_proveedor_congelados(); DROP FUNCTION IF EXISTS facturas_proveedor_montos();');
    }
};
