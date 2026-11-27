<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4b: ficha de integraciones externas (secreto cifrado, nunca devuelto),
 * lista de recorrido de ejecutivos de terreno, bandeja de WhatsApp y registro
 * de uso del asistente de IA (sin contenido: solo quién, qué y cuánto).
 */
return new class extends Migration
{
    private const TABLAS = ['integraciones', 'recorridos', 'recorrido_paradas', 'wa_plantillas', 'wa_conversaciones', 'wa_mensajes', 'ia_usos'];

    public function up(): void
    {
        Schema::create('integraciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('tipo', 12); // pbx, dte, whatsapp, meta_ads, linkedin, correo, ia, mapas
            $t->boolean('activa')->default(false);
            $t->string('ambiente', 14)->default('certificacion');
            $t->string('url', 200)->nullable();
            $t->string('identificador', 80)->nullable(); // p. ej. phone_number_id de WhatsApp
            $t->text('secreto_cifrado')->nullable();
            $t->timestamp('secreto_rotado_at')->nullable();
            $t->integer('reintentos')->default(5);
            $t->integer('timeout_seg')->default(15);
            $t->string('salud', 8)->nullable(); // ok, caida
            $t->timestamp('salud_at')->nullable();
            $t->timestamps();
            $t->unique(['empresa_id', 'tipo']);
        });

        Schema::create('recorridos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('user_id')->constrained('users'); // ejecutivo de terreno
            $t->date('fecha');
            $t->string('estado', 12)->default('planificado'); // planificado, en_curso, cerrado
            $t->string('origen', 160)->nullable();
            $t->decimal('origen_lat', 9, 6)->nullable();
            $t->decimal('origen_lng', 9, 6)->nullable();
            $t->decimal('distancia_km', 8, 2)->nullable();
            $t->integer('duracion_min')->nullable();
            $t->timestamp('optimizado_at')->nullable();
            $t->foreignId('creado_por')->constrained('users');
            $t->timestamps();
            $t->unique(['empresa_id', 'user_id', 'fecha']);
        });
        Schema::create('recorrido_paradas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('recorrido_id')->constrained('recorridos')->cascadeOnDelete();
            $t->integer('orden');
            $t->foreignId('cliente_id')->nullable()->constrained('clientes');
            $t->foreignId('lead_id')->nullable()->constrained('leads');
            $t->string('nombre', 120);
            $t->string('direccion', 160);
            $t->string('comuna', 60)->nullable();
            $t->decimal('lat', 9, 6)->nullable();
            $t->decimal('lng', 9, 6)->nullable();
            $t->string('estado', 12)->default('pendiente'); // pendiente, visitada, no_visitada
            $t->string('resultado', 20)->nullable();
            $t->text('notas')->nullable();
            $t->timestamp('visitada_at')->nullable();
            $t->timestamps();
            $t->index(['recorrido_id', 'orden']);
        });

        Schema::create('wa_plantillas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nombre', 60);
            $t->string('idioma', 8)->default('es');
            $t->string('categoria', 14)->default('utility'); // utility, marketing
            $t->text('cuerpo'); // con {{1}}, {{2}}…
            $t->string('estado', 10)->default('aprobada'); // pendiente, aprobada, rechazada
            $t->timestamps();
            $t->unique(['empresa_id', 'nombre', 'idioma']);
        });
        Schema::create('wa_conversaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('telefono', 16); // E.164 sin +
            $t->string('nombre', 120)->nullable();
            $t->foreignId('cliente_id')->nullable()->constrained('clientes');
            $t->foreignId('lead_id')->nullable()->constrained('leads');
            $t->foreignId('asignado_a')->nullable()->constrained('users');
            $t->string('estado', 8)->default('abierta'); // abierta, cerrada
            $t->integer('no_leidos')->default(0);
            $t->timestamp('ultimo_mensaje_at')->nullable();
            $t->timestamp('ventana_hasta')->nullable(); // 24 h desde el último mensaje del cliente
            $t->timestamps();
            $t->unique(['empresa_id', 'telefono']);
            $t->index(['empresa_id', 'ultimo_mensaje_at']);
        });
        Schema::create('wa_mensajes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('conversacion_id')->constrained('wa_conversaciones');
            $t->string('direccion', 8); // entrante, saliente
            $t->string('tipo', 10)->default('texto'); // texto, plantilla, documento
            $t->text('cuerpo');
            $t->foreignId('plantilla_id')->nullable()->constrained('wa_plantillas');
            $t->string('wa_id', 100)->nullable();
            $t->string('estado', 10); // recibido, en_cola, enviado, entregado, leido, fallido
            $t->string('error', 200)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['empresa_id', 'wa_id']);
            $t->index(['conversacion_id', 'id']);
        });

        Schema::create('ia_usos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('user_id')->constrained('users');
            $t->string('accion', 20); // sugerir, resumir
            $t->string('contexto', 40); // p. ej. whatsapp:12
            $t->integer('caracteres');
            $t->integer('datos_ocultados'); // cuántos datos personales se reemplazaron antes de enviar
            $t->timestamp('created_at');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE integraciones ADD CONSTRAINT integraciones_tipo CHECK (tipo IN ('pbx','dte','whatsapp','meta_ads','linkedin','correo','ia','mapas'));
            ALTER TABLE integraciones ADD CONSTRAINT integraciones_ambiente CHECK (ambiente IN ('certificacion','produccion'));
            ALTER TABLE integraciones ADD CONSTRAINT integraciones_limites CHECK (reintentos BETWEEN 0 AND 20 AND timeout_seg BETWEEN 1 AND 120);
            ALTER TABLE recorridos ADD CONSTRAINT recorridos_estado CHECK (estado IN ('planificado','en_curso','cerrado'));
            ALTER TABLE recorrido_paradas ADD CONSTRAINT paradas_estado CHECK (estado IN ('pendiente','visitada','no_visitada'));
            ALTER TABLE recorrido_paradas ADD CONSTRAINT paradas_origen CHECK (cliente_id IS NOT NULL OR lead_id IS NOT NULL);
            ALTER TABLE wa_conversaciones ADD CONSTRAINT wa_conv_estado CHECK (estado IN ('abierta','cerrada'));
            ALTER TABLE wa_conversaciones ADD CONSTRAINT wa_conv_telefono CHECK (telefono ~ '^[0-9]{8,15}$');
            ALTER TABLE wa_mensajes ADD CONSTRAINT wa_msj_direccion CHECK (direccion IN ('entrante','saliente'));
            ALTER TABLE wa_mensajes ADD CONSTRAINT wa_msj_estado CHECK (estado IN ('recibido','en_cola','enviado','entregado','leido','fallido'));
            CREATE TRIGGER ia_usos_inmutables BEFORE UPDATE OR DELETE ON ia_usos FOR EACH ROW EXECUTE FUNCTION solo_insercion();

            -- El webhook de WhatsApp llega sin empresa: la ubica por el identificador del número
            -- (phone_number_id) sin abrir el resto de la tabla, que sigue protegida por RLS.
            CREATE OR REPLACE FUNCTION empresa_por_integracion(p_tipo text, p_identificador text) RETURNS bigint
                LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS
                $$ SELECT empresa_id FROM integraciones WHERE tipo = p_tipo AND identificador = p_identificador AND activa LIMIT 1 $$;
            REVOKE ALL ON FUNCTION empresa_por_integracion(text, text) FROM PUBLIC;
        SQL);

        foreach (self::TABLAS as $t) {
            Rls::habilitar($t);
        }
        $app = config('database.connections.pgsql.username');
        DB::unprepared("GRANT EXECUTE ON FUNCTION empresa_por_integracion(text, text) TO \"{$app}\"");
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS empresa_por_integracion(text, text)');
        foreach (array_reverse(self::TABLAS) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
