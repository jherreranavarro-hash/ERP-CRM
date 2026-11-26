<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3d: finanzas. Plan de cuentas y centros de costo, asientos de partida
 * doble (la base de datos exige que cuadren y los hace inmutables: se corrigen
 * con un reverso), cuentas bancarias con cartolas importadas y conciliación,
 * y boletas de honorarios recibidas (libro de honorarios).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_contables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('codigo', 20);
            $table->string('nombre', 100);
            $table->string('tipo', 12);                       // activo, pasivo, patrimonio, ingreso, gasto
            $table->string('clave', 30)->nullable();          // uso en asientos automáticos (caja, banco, clientes...)
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'codigo']);
        });

        Schema::create('centros_costo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('codigo', 20);
            $table->string('nombre', 100);
            $table->foreignId('local_id')->nullable()->constrained('locales');
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'codigo']);
        });

        Schema::create('asientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->unsignedInteger('numero');
            $table->date('fecha');
            $table->string('glosa', 200);
            $table->string('tipo', 12);                       // manual, automatico, reverso
            $table->string('origen_tipo', 40)->nullable();
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->foreignId('revierte_id')->nullable()->constrained('asientos');
            $table->bigInteger('total');
            $table->foreignId('creado_por')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'fecha']);
        });

        Schema::create('asiento_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('asiento_id')->constrained('asientos');
            $table->foreignId('cuenta_id')->constrained('cuentas_contables');
            $table->foreignId('centro_costo_id')->nullable()->constrained('centros_costo');
            $table->bigInteger('debe')->default(0);
            $table->bigInteger('haber')->default(0);
            $table->string('glosa', 200)->nullable();
            $table->index(['empresa_id', 'cuenta_id']);
        });

        Schema::create('cuentas_bancarias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('banco', 60);
            $table->string('numero', 30);
            $table->string('nombre', 100);
            $table->foreignId('cuenta_contable_id')->constrained('cuentas_contables');
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->unique(['empresa_id', 'banco', 'numero']);
        });

        Schema::create('cartolas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias');
            $table->string('archivo', 150);
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();
            $table->unsignedInteger('movimientos')->default(0);
            $table->unsignedInteger('duplicados')->default(0);
            $table->unsignedInteger('conciliados')->default(0);
            $table->foreignId('importado_por')->constrained('users');
            $table->timestampsTz();
        });

        Schema::create('movimientos_bancarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->foreignId('cuenta_bancaria_id')->constrained('cuentas_bancarias');
            $table->foreignId('cartola_id')->constrained('cartolas');
            $table->date('fecha');
            $table->string('glosa', 200);
            $table->string('documento', 40)->nullable();
            $table->bigInteger('monto');                      // abono positivo, cargo negativo
            $table->string('huella', 64);                     // evita importar dos veces el mismo movimiento
            $table->string('estado', 12)->default('pendiente');   // pendiente, conciliado, ignorado
            $table->foreignId('pago_id')->nullable()->constrained('pagos');
            $table->foreignId('pago_proveedor_id')->nullable()->constrained('pagos_proveedor');
            $table->foreignId('asiento_id')->nullable()->constrained('asientos');
            $table->string('forma', 12)->nullable();          // automatica, manual
            $table->foreignId('conciliado_por')->nullable()->constrained('users');
            $table->timestampTz('conciliado_at')->nullable();
            $table->timestampsTz();
            $table->unique(['empresa_id', 'cuenta_bancaria_id', 'huella']);
            $table->index(['empresa_id', 'estado']);
        });

        Schema::create('honorarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas');
            $table->string('rut', 12);
            $table->string('nombre', 100);
            $table->unsignedBigInteger('folio');
            $table->date('fecha');
            $table->string('descripcion', 200);
            $table->bigInteger('bruto');
            $table->decimal('tasa_retencion', 5, 2);
            $table->bigInteger('retencion');
            $table->bigInteger('liquido');
            $table->string('estado', 10)->default('por_pagar');   // por_pagar, pagada, anulada
            $table->date('fecha_pago')->nullable();
            $table->foreignId('centro_costo_id')->nullable()->constrained('centros_costo');
            $table->foreignId('registrado_por')->constrained('users');
            $table->timestampsTz();
            $table->unique(['empresa_id', 'rut', 'folio']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE cuentas_contables
                ADD CONSTRAINT cc_tipo CHECK (tipo IN ('activo', 'pasivo', 'patrimonio', 'ingreso', 'gasto'));
            CREATE UNIQUE INDEX cuentas_contables_clave ON cuentas_contables (empresa_id, clave) WHERE clave IS NOT NULL;

            ALTER TABLE asientos
                ADD CONSTRAINT asientos_tipo CHECK (tipo IN ('manual', 'automatico', 'reverso')),
                ADD CONSTRAINT asientos_total CHECK (total > 0),
                ADD CONSTRAINT asientos_reverso CHECK ((tipo = 'reverso') = (revierte_id IS NOT NULL));
            -- Un documento genera un solo asiento automático, y un asiento se revierte una vez.
            CREATE UNIQUE INDEX asientos_origen ON asientos (empresa_id, origen_tipo, origen_id) WHERE tipo = 'automatico';
            CREATE UNIQUE INDEX asientos_revierte ON asientos (revierte_id) WHERE revierte_id IS NOT NULL;

            ALTER TABLE asiento_lineas
                ADD CONSTRAINT asiento_lineas_monto CHECK (debe >= 0 AND haber >= 0 AND (debe > 0) <> (haber > 0));

            -- Partida doble: al cerrar la transacción cada asiento cuadra, tiene dos líneas o más y su total coincide.
            CREATE OR REPLACE FUNCTION asiento_cuadra() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                a_id bigint;
                d bigint; h bigint; n int; t bigint;
            BEGIN
                IF TG_TABLE_NAME = 'asientos' THEN a_id := NEW.id; ELSE a_id := NEW.asiento_id; END IF;
                SELECT COALESCE(sum(debe), 0), COALESCE(sum(haber), 0), count(*) INTO d, h, n FROM asiento_lineas WHERE asiento_id = a_id;
                SELECT total INTO t FROM asientos WHERE id = a_id;
                IF n < 2 OR d <> h OR d <> t THEN
                    RAISE EXCEPTION 'El asiento % no cuadra (debe %, haber %, total %, % líneas)', a_id, d, h, t, n;
                END IF;
                RETURN NULL;
            END $$;
            CREATE CONSTRAINT TRIGGER asientos_cuadran AFTER INSERT ON asientos
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION asiento_cuadra();
            CREATE CONSTRAINT TRIGGER asiento_lineas_cuadran AFTER INSERT ON asiento_lineas
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION asiento_cuadra();

            -- Lo contabilizado no se edita ni se borra: se revierte con otro asiento.
            CREATE TRIGGER asientos_inmutables BEFORE UPDATE OR DELETE ON asientos FOR EACH ROW EXECUTE FUNCTION solo_insercion();
            CREATE TRIGGER asiento_lineas_inmutables BEFORE UPDATE OR DELETE ON asiento_lineas FOR EACH ROW EXECUTE FUNCTION solo_insercion();

            ALTER TABLE movimientos_bancarios
                ADD CONSTRAINT mb_estado CHECK (estado IN ('pendiente', 'conciliado', 'ignorado')),
                ADD CONSTRAINT mb_monto CHECK (monto <> 0),
                ADD CONSTRAINT mb_conciliado CHECK (estado <> 'conciliado' OR num_nonnulls(pago_id, pago_proveedor_id, asiento_id) = 1),
                ADD CONSTRAINT mb_sentido CHECK ((pago_id IS NULL OR monto > 0) AND (pago_proveedor_id IS NULL OR monto < 0));
            -- Un pago se concilia con un solo movimiento del banco.
            CREATE UNIQUE INDEX mb_pago ON movimientos_bancarios (pago_id) WHERE pago_id IS NOT NULL;
            CREATE UNIQUE INDEX mb_pago_proveedor ON movimientos_bancarios (pago_proveedor_id) WHERE pago_proveedor_id IS NOT NULL;

            ALTER TABLE honorarios
                ADD CONSTRAINT honorarios_montos CHECK (bruto > 0 AND retencion >= 0 AND liquido = bruto - retencion),
                ADD CONSTRAINT honorarios_estado CHECK (estado IN ('por_pagar', 'pagada', 'anulada')),
                ADD CONSTRAINT honorarios_pago CHECK ((estado = 'pagada') = (fecha_pago IS NOT NULL));
        SQL);

        foreach (['cuentas_contables', 'centros_costo', 'asientos', 'asiento_lineas', 'cuentas_bancarias', 'cartolas', 'movimientos_bancarios', 'honorarios'] as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        foreach (['honorarios', 'movimientos_bancarios', 'cartolas', 'cuentas_bancarias', 'asiento_lineas', 'asientos', 'centros_costo', 'cuentas_contables'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS asiento_cuadra();');
    }
};
