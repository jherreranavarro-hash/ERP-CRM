<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4a: contact center. Telefonía configurada aparte del modelo de usuario
 * (proveedores IP con secreto cifrado, números entrantes, IVR, colas, lista
 * negra), agentes, campañas con metas y horario, leads con asignación,
 * guiones, llamadas, grabaciones con registro inmutable de cada acceso y
 * auditoría de calidad con checklist y puntaje.
 */
return new class extends Migration
{
    private const TABLAS = ['proveedores_ip', 'ivrs', 'colas', 'numeros_entrantes', 'lista_negra', 'guiones', 'campanas', 'agentes', 'campana_agente',
        'leads', 'llamadas', 'grabacion_accesos', 'criterios_calidad', 'auditorias_calidad'];

    public function up(): void
    {
        Schema::create('proveedores_ip', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nombre', 80);
            $t->string('tipo', 12)->default('asterisk'); // asterisk, freeswitch, vicidial
            $t->string('host', 120);
            $t->integer('puerto')->default(5038);
            $t->string('protocolo', 8)->default('ami'); // ami, ari, esl
            $t->string('usuario_servicio', 60);
            $t->text('secreto_cifrado')->nullable(); // nunca se devuelve ni se exporta: solo se reemplaza
            $t->timestamp('secreto_rotado_at')->nullable();
            $t->boolean('activo')->default(true);
            $t->string('salud', 12)->default('desconocida'); // ok, caida, desconocida
            $t->timestamp('salud_at')->nullable();
            $t->timestamps();
            $t->unique(['empresa_id', 'nombre']);
        });

        Schema::create('ivrs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nombre', 80);
            $t->text('bienvenida');
            $t->text('fuera_de_horario')->nullable();
            $t->jsonb('opciones'); // [{tecla, etiqueta, accion: cola|ivr|numero|colgar, destino}]
            $t->jsonb('horario')->nullable(); // {dias:[1..7], desde, hasta}
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['empresa_id', 'nombre']);
        });

        Schema::create('colas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nombre', 60);
            $t->string('estrategia', 16)->default('menos_reciente'); // todos, round_robin, menos_reciente, menor_carga
            $t->integer('espera_max_seg')->default(180);
            $t->integer('nivel_servicio_seg')->default(20);
            $t->foreignId('ivr_desborde_id')->nullable()->constrained('ivrs');
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['empresa_id', 'nombre']);
        });

        Schema::create('numeros_entrantes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('numero', 20);
            $t->string('descripcion', 120)->nullable();
            $t->foreignId('proveedor_ip_id')->nullable()->constrained('proveedores_ip');
            $t->string('destino_tipo', 8); // ivr, cola
            $t->unsignedBigInteger('destino_id');
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['empresa_id', 'numero']);
        });

        Schema::create('lista_negra', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('numero', 20);
            $t->string('motivo', 200);
            $t->date('hasta')->nullable(); // null: indefinido
            $t->foreignId('creado_por')->constrained('users');
            $t->timestamps();
            $t->unique(['empresa_id', 'numero']);
        });

        Schema::create('guiones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nombre', 100);
            $t->foreignId('categoria_id')->nullable()->constrained('categorias');
            $t->jsonb('pasos'); // [{titulo, texto, respuestas:[{texto, siguiente}]}]
            $t->integer('version')->default(1);
            $t->boolean('activo')->default(true);
            $t->foreignId('actualizado_por')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['empresa_id', 'nombre']);
        });

        Schema::create('campanas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nombre', 100);
            $t->string('tipo', 10)->default('saliente'); // saliente, entrante, terreno
            $t->string('estado', 10)->default('borrador'); // borrador, activa, pausada, terminada
            $t->text('descripcion')->nullable();
            $t->date('fecha_inicio');
            $t->date('fecha_fin')->nullable();
            $t->integer('meta_contactos')->default(0);
            $t->integer('meta_cotizaciones')->default(0);
            $t->bigInteger('meta_ventas')->default(0);
            $t->jsonb('horario'); // {dias:[1..7], desde, hasta}
            $t->integer('max_intentos')->default(3);
            $t->string('asignacion', 12)->default('round_robin'); // round_robin, zona, carga
            $t->foreignId('guion_id')->nullable()->constrained('guiones');
            $t->foreignId('cola_id')->nullable()->constrained('colas');
            $t->foreignId('creado_por')->constrained('users');
            $t->timestamps();
            $t->unique(['empresa_id', 'nombre']);
        });

        Schema::create('agentes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('user_id')->constrained('users');
            $t->string('extension', 10);
            $t->jsonb('habilidades')->default('[]');
            $t->jsonb('zonas')->default('[]'); // comunas que atiende
            $t->integer('capacidad')->default(50); // leads abiertos simultáneos
            $t->string('estado', 12)->default('desconectado'); // disponible, en_llamada, pausa, desconectado
            $t->string('motivo_pausa', 40)->nullable();
            $t->timestamp('estado_desde')->nullable();
            $t->foreignId('cola_id')->nullable()->constrained('colas');
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->unique(['empresa_id', 'user_id']);
            $t->unique(['empresa_id', 'extension']);
        });

        Schema::create('campana_agente', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('campana_id')->constrained('campanas')->cascadeOnDelete();
            $t->foreignId('agente_id')->constrained('agentes')->cascadeOnDelete();
            $t->unique(['campana_id', 'agente_id']);
        });

        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('campana_id')->constrained('campanas');
            $t->foreignId('cliente_id')->nullable()->constrained('clientes');
            $t->string('nombre', 120);
            $t->string('rut', 12)->nullable();
            $t->string('telefono', 20);
            $t->string('email', 120)->nullable();
            $t->string('comuna', 60)->nullable();
            $t->string('direccion', 160)->nullable();
            $t->string('estado', 14)->default('nuevo'); // nuevo, asignado, contactado, interesado, cotizado, ganado, perdido, no_contactar
            $t->foreignId('agente_id')->nullable()->constrained('agentes');
            $t->timestamp('asignado_at')->nullable();
            $t->integer('intentos')->default(0);
            $t->timestamp('ultimo_contacto_at')->nullable();
            $t->timestamp('proximo_contacto_at')->nullable();
            $t->foreignId('cotizacion_id')->nullable()->constrained('cotizaciones');
            $t->text('notas')->nullable();
            $t->timestamps();
            $t->unique(['campana_id', 'telefono']);
            $t->index(['agente_id', 'estado']);
        });

        Schema::create('llamadas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('campana_id')->nullable()->constrained('campanas');
            $t->foreignId('lead_id')->nullable()->constrained('leads');
            $t->foreignId('agente_id')->nullable()->constrained('agentes');
            $t->foreignId('cola_id')->nullable()->constrained('colas');
            $t->string('direccion', 8); // saliente, entrante
            $t->string('numero', 20);
            $t->string('estado', 12)->default('marcando'); // marcando, en_cola, en_curso, terminada, no_contesta, ocupado, fallida
            $t->string('pbx_id', 80)->nullable();
            $t->timestamp('inicio');
            $t->timestamp('contestada_at')->nullable();
            $t->timestamp('fin')->nullable();
            $t->integer('espera_seg')->default(0);
            $t->integer('duracion_seg')->default(0);
            $t->string('resultado', 20)->nullable(); // tipificación
            $t->text('notas')->nullable();
            $t->string('grabacion_ref', 200)->nullable();
            $t->timestamps();
            $t->unique(['empresa_id', 'pbx_id']);
            $t->index(['agente_id', 'inicio']);
        });

        Schema::create('grabacion_accesos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('llamada_id')->constrained('llamadas');
            $t->foreignId('user_id')->constrained('users');
            $t->string('accion', 10); // escuchar, descargar
            $t->string('motivo', 200);
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('criterios_calidad', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('nombre', 120);
            $t->integer('peso'); // puntos
            $t->boolean('critico')->default(false); // si no se cumple, la nota queda en 0
            $t->boolean('activo')->default(true);
            $t->integer('orden')->default(0);
            $t->timestamps();
        });

        Schema::create('auditorias_calidad', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('llamada_id')->constrained('llamadas');
            $t->foreignId('agente_id')->constrained('agentes');
            $t->foreignId('auditor_id')->constrained('users');
            $t->jsonb('checklist'); // [{criterio, peso, critico, cumple}]
            $t->decimal('puntaje', 5, 1);
            $t->text('comentario')->nullable();
            $t->timestamps();
            $t->unique('llamada_id');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE campanas ADD CONSTRAINT campanas_estado CHECK (estado IN ('borrador','activa','pausada','terminada'));
            ALTER TABLE campanas ADD CONSTRAINT campanas_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio);
            ALTER TABLE campanas ADD CONSTRAINT campanas_asignacion CHECK (asignacion IN ('round_robin','zona','carga'));
            ALTER TABLE agentes ADD CONSTRAINT agentes_estado CHECK (estado IN ('disponible','en_llamada','pausa','desconectado'));
            ALTER TABLE leads ADD CONSTRAINT leads_estado CHECK (estado IN ('nuevo','asignado','contactado','interesado','cotizado','ganado','perdido','no_contactar'));
            ALTER TABLE llamadas ADD CONSTRAINT llamadas_estado CHECK (estado IN ('marcando','en_cola','en_curso','terminada','no_contesta','ocupado','fallida'));
            ALTER TABLE llamadas ADD CONSTRAINT llamadas_direccion CHECK (direccion IN ('saliente','entrante'));
            ALTER TABLE numeros_entrantes ADD CONSTRAINT numeros_destino CHECK (destino_tipo IN ('ivr','cola'));
            ALTER TABLE auditorias_calidad ADD CONSTRAINT auditorias_puntaje CHECK (puntaje BETWEEN 0 AND 100);
            ALTER TABLE grabacion_accesos ADD CONSTRAINT grabacion_motivo CHECK (length(trim(motivo)) >= 5);
            CREATE TRIGGER grabacion_accesos_inmutables BEFORE UPDATE OR DELETE ON grabacion_accesos FOR EACH ROW EXECUTE FUNCTION solo_insercion();
        SQL);

        foreach (self::TABLAS as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
