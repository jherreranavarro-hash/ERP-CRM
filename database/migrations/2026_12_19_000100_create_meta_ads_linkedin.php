<?php

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4d: campañas de Meta Ads con sus formularios de prospectos (importados a una
 * campaña del contact center), revisión de plantillas de WhatsApp por Meta y bandeja
 * de LinkedIn sincronizada por cola.
 */
return new class extends Migration
{
    private const TABLAS = ['ads_campanas', 'ads_leads', 'li_conversaciones', 'li_mensajes'];

    public function up(): void
    {
        Schema::create('ads_campanas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('externo_id', 40);
            $t->string('nombre', 120);
            $t->string('estado', 10); // activa, pausada, terminada
            $t->string('objetivo', 20)->nullable();
            $t->bigInteger('presupuesto_diario')->default(0);
            $t->bigInteger('gasto')->default(0);
            $t->bigInteger('impresiones')->default(0);
            $t->bigInteger('clics')->default(0);
            $t->integer('leads')->default(0);
            $t->foreignId('campana_id')->nullable()->constrained('campanas'); // destino de los prospectos
            $t->timestamp('sincronizada_at')->nullable();
            $t->timestamps();
            $t->unique(['empresa_id', 'externo_id']);
        });
        Schema::create('ads_leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('ads_campana_id')->constrained('ads_campanas');
            $t->string('externo_id', 40);
            $t->string('nombre', 120);
            $t->string('telefono', 20);
            $t->string('email', 120)->nullable();
            $t->string('comuna', 60)->nullable();
            $t->string('resultado', 12); // importado, duplicado, bloqueado, sin_destino, invalido
            $t->foreignId('lead_id')->nullable()->constrained('leads');
            $t->timestamp('recibido_at');
            $t->timestamps();
            $t->unique(['empresa_id', 'externo_id']);
        });

        Schema::table('wa_plantillas', function (Blueprint $t) {
            $t->string('motivo_rechazo', 200)->nullable();
            $t->timestamp('revisada_at')->nullable();
            $t->foreignId('creado_por')->nullable()->constrained('users');
        });

        Schema::create('li_conversaciones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->string('externo_id', 60);
            $t->string('nombre', 120);
            $t->string('cargo', 120)->nullable();
            $t->string('organizacion', 120)->nullable();
            $t->string('perfil_url', 200)->nullable();
            $t->foreignId('lead_id')->nullable()->constrained('leads');
            $t->foreignId('asignado_a')->nullable()->constrained('users');
            $t->string('estado', 8)->default('abierta');
            $t->integer('no_leidos')->default(0);
            $t->timestamp('ultimo_mensaje_at')->nullable();
            $t->timestamps();
            $t->unique(['empresa_id', 'externo_id']);
            $t->index(['empresa_id', 'ultimo_mensaje_at']);
        });
        Schema::create('li_mensajes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('empresa_id')->constrained('empresas');
            $t->foreignId('conversacion_id')->constrained('li_conversaciones');
            $t->string('direccion', 8);
            $t->text('cuerpo');
            $t->string('externo_id', 60)->nullable();
            $t->string('estado', 10); // recibido, en_cola, enviado, fallido
            $t->string('error', 200)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->timestamps();
            $t->unique(['empresa_id', 'externo_id']);
            $t->index(['conversacion_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE ads_campanas ADD CONSTRAINT ads_campanas_estado CHECK (estado IN ('activa','pausada','terminada'));
            ALTER TABLE ads_campanas ADD CONSTRAINT ads_campanas_metricas CHECK (presupuesto_diario >= 0 AND gasto >= 0 AND impresiones >= 0 AND clics >= 0 AND leads >= 0);
            ALTER TABLE ads_leads ADD CONSTRAINT ads_leads_resultado CHECK (resultado IN ('importado','duplicado','bloqueado','sin_destino','invalido'));
            ALTER TABLE ads_leads ADD CONSTRAINT ads_leads_importado CHECK ((resultado = 'importado') = (lead_id IS NOT NULL));
            ALTER TABLE wa_plantillas ADD CONSTRAINT wa_plantillas_estado CHECK (estado IN ('borrador','pendiente','aprobada','rechazada'));
            ALTER TABLE wa_plantillas ADD CONSTRAINT wa_plantillas_nombre CHECK (nombre ~ '^[a-z0-9_]{1,60}$');
            ALTER TABLE li_conversaciones ADD CONSTRAINT li_conv_estado CHECK (estado IN ('abierta','cerrada'));
            ALTER TABLE li_mensajes ADD CONSTRAINT li_msj_direccion CHECK (direccion IN ('entrante','saliente'));
            ALTER TABLE li_mensajes ADD CONSTRAINT li_msj_estado CHECK (estado IN ('recibido','en_cola','enviado','fallido'));
        SQL);

        foreach (self::TABLAS as $t) {
            Rls::habilitar($t);
        }
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE wa_plantillas DROP CONSTRAINT IF EXISTS wa_plantillas_estado; ALTER TABLE wa_plantillas DROP CONSTRAINT IF EXISTS wa_plantillas_nombre;');
        Schema::table('wa_plantillas', function (Blueprint $t) {
            $t->dropConstrainedForeignId('creado_por');
            $t->dropColumn(['motivo_rechazo', 'revisada_at']);
        });
        foreach (array_reverse(self::TABLAS) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
