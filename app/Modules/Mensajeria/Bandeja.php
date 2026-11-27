<?php

namespace App\Modules\Mensajeria;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\ListaNegra;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Mensajeria\Eventos\WhatsappActualizado;
use App\Modules\Mensajeria\Models\Conversacion;
use App\Modules\Mensajeria\Models\Mensaje;
use App\Modules\Mensajeria\Models\Plantilla;
use App\Modules\Mensajeria\Trabajos\EnviarWhatsapp;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Bandeja de WhatsApp. Reglas de Meta que se respetan aquí y no en la
 * pantalla: texto libre solo dentro de las 24 h desde el último mensaje del
 * cliente; fuera de ella, solo plantillas aprobadas. A un número en lista
 * negra no se le escribe.
 */
class Bandeja
{
    public function __construct(private Tenancy $tenancy) {}

    public static function normalizar(string $telefono): string
    {
        $d = preg_replace('/\D/', '', $telefono);

        return strlen($d) === 9 && $d[0] === '9' ? '56'.$d : $d;
    }

    /** Mensaje entrante desde el webhook. Idempotente por el id de WhatsApp. */
    public function recibir(string $telefono, ?string $nombre, string $waId, string $texto, CarbonImmutable $momento): ?Mensaje
    {
        if (Mensaje::where('wa_id', $waId)->exists()) {
            return null;
        }

        return DB::transaction(function () use ($telefono, $nombre, $waId, $texto, $momento) {
            $c = $this->conversacion($telefono, $nombre);
            $m = $c->mensajes()->create(['direccion' => 'entrante', 'tipo' => 'texto', 'cuerpo' => mb_substr($texto, 0, 4096), 'wa_id' => $waId, 'estado' => 'recibido']);
            $c->forceFill(['estado' => 'abierta', 'no_leidos' => $c->no_leidos + 1, 'ultimo_mensaje_at' => $momento, 'ventana_hasta' => $momento->addDay()])->save();
            DB::afterCommit(fn () => $this->avisar($c));

            return $m;
        });
    }

    /** Aviso de estado (enviado, entregado, leído, fallido). Nunca retrocede. */
    public function estado(string $waId, string $estado, ?string $error = null): void
    {
        $m = Mensaje::where('wa_id', $waId)->where('direccion', 'saliente')->first();
        if (! $m) {
            return;
        }
        $avanza = $estado === 'fallido' ? $m->estado !== 'leido' : (Mensaje::AVANCE[$estado] ?? -1) > (Mensaje::AVANCE[$m->estado] ?? 99);
        if ($avanza) {
            $m->forceFill(['estado' => $estado, 'error' => $error ? mb_substr($error, 0, 200) : $m->error])->save();
            $this->avisar(Conversacion::findOrFail($m->conversacion_id));
        }
    }

    public function enviarTexto(Conversacion $c, string $texto, User $user): Mensaje
    {
        $this->puedeEscribir($c);
        if (! $c->enVentana()) {
            throw ValidationException::withMessages(['texto' => 'Pasaron más de 24 horas desde el último mensaje del cliente: WhatsApp solo permite enviar una plantilla aprobada.']);
        }
        $m = $c->mensajes()->create(['direccion' => 'saliente', 'tipo' => 'texto', 'cuerpo' => trim($texto), 'estado' => 'en_cola', 'user_id' => $user->id]);
        $this->despachar($c, $m);

        return $m;
    }

    /** @param  list<string>  $variables */
    public function enviarPlantilla(Conversacion $c, Plantilla $p, array $variables, User $user): Mensaje
    {
        $this->puedeEscribir($c);
        if ($p->estado !== 'aprobada') {
            throw ValidationException::withMessages(['plantilla_id' => 'La plantilla no está aprobada por Meta.']);
        }
        $variables = array_values(array_map(fn ($v) => trim((string) $v), $variables));
        if (count(array_filter($variables, fn ($v) => $v !== '')) < $p->variables()) {
            throw ValidationException::withMessages(['variables' => 'Completa las '.$p->variables().' variables de la plantilla.']);
        }
        $m = $c->mensajes()->create(['direccion' => 'saliente', 'tipo' => 'plantilla', 'plantilla_id' => $p->id, 'cuerpo' => $p->completar($variables), 'estado' => 'en_cola', 'user_id' => $user->id]);
        $this->despachar($c, $m, $variables);

        return $m;
    }

    /** Abre (o retoma) una conversación con un cliente o prospecto para escribirle con plantilla. */
    public function iniciar(string $telefono, ?string $nombre, ?int $clienteId, ?int $leadId, User $user): Conversacion
    {
        $tel = self::normalizar($telefono);
        if (! preg_match('/^\d{8,15}$/', $tel)) {
            throw ValidationException::withMessages(['telefono' => 'Número no válido.']);
        }
        $c = $this->conversacion($tel, $nombre);
        $c->forceFill(['cliente_id' => $c->cliente_id ?? $clienteId, 'lead_id' => $c->lead_id ?? $leadId, 'asignado_a' => $c->asignado_a ?? $user->id])->save();

        return $c;
    }

    public function leer(Conversacion $c): void
    {
        if ($c->no_leidos) {
            $c->forceFill(['no_leidos' => 0])->save();
        }
    }

    public function avisar(Conversacion $c): void
    {
        try {
            broadcast(new WhatsappActualizado((int) $this->tenancy->id(), $c->id));
        } catch (\Throwable $e) {
            Log::warning('Tiempo real no disponible: '.$e->getMessage());
        }
    }

    private function puedeEscribir(Conversacion $c): void
    {
        if (! Integracion::activa('whatsapp')) {
            throw ValidationException::withMessages(['texto' => 'La integración de WhatsApp no está activa (Configuración → Integraciones).']);
        }
        if (ListaNegra::bloqueado($c->telefono)) {
            throw ValidationException::withMessages(['texto' => 'El número está en la lista negra: no se le escribe.']);
        }
    }

    /** @param  list<string>  $variables */
    private function despachar(Conversacion $c, Mensaje $m, array $variables = []): void
    {
        $c->forceFill(['ultimo_mensaje_at' => now(), 'estado' => 'abierta'])->save();
        $reintentos = (int) (Integracion::de('whatsapp')?->reintentos ?? 5);
        EnviarWhatsapp::dispatch((int) $this->tenancy->id(), $m->id, $variables, $reintentos)->afterCommit();
        $this->avisar($c);
    }

    private function conversacion(string $telefono, ?string $nombre): Conversacion
    {
        $tel = self::normalizar($telefono);
        $c = Conversacion::firstOrNew(['telefono' => $tel]);
        if (! $c->exists) {
            $ultimos = substr($tel, -9);
            $c->cliente_id = Cliente::whereRaw("right(regexp_replace(coalesce(telefono, ''), '\\D', '', 'g'), 9) = ?", [$ultimos])->value('id');
            $lead = Lead::with('agente')->whereRaw("right(regexp_replace(telefono, '\\D', '', 'g'), 9) = ?", [$ultimos])->latest('id')->first();
            $c->lead_id = $lead?->id;
            $c->asignado_a = $lead?->agente?->user_id;
            $c->nombre = $nombre ? mb_substr($nombre, 0, 120) : ($lead?->nombre ?? null);
            $c->save();
        }

        return $c;
    }
}
