<?php

namespace App\Modules\Mensajeria\Linkedin;

use App\Models\User;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\ListaNegra;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Integraciones\Linkedin\Linkedin;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Mensajeria\Models\LinkedinConversacion;
use App\Modules\Mensajeria\Models\LinkedinMensaje;
use App\Modules\Mensajeria\Trabajos\EnviarLinkedin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bandeja de LinkedIn. Sin webhook: se sincroniza por cola (idempotente por el id de
 * cada mensaje). Los envíos van en cola con reintentos. Una conversación se puede
 * convertir en prospecto del contact center.
 */
class BandejaLinkedin
{
    public function __construct(private Linkedin $li, private Tenancy $tenancy, private Auditoria $auditoria) {}

    /** @return int mensajes nuevos */
    public function sincronizar(): int
    {
        if (! Integracion::activa('linkedin')) {
            throw ValidationException::withMessages(['linkedin' => 'Activa la integración de LinkedIn en Configuración → Integraciones.']);
        }
        $ultimo = LinkedinMensaje::where('direccion', 'entrante')->max('created_at');
        $desde = $ultimo ? Carbon::parse($ultimo)->subDay() : now()->subDays(30);

        try {
            $mensajes = $this->li->mensajesDesde($desde);
        } catch (\Throwable $e) {
            Integracion::where('tipo', 'linkedin')->update(['salud' => 'caida', 'salud_at' => now()]);
            throw ValidationException::withMessages(['linkedin' => 'LinkedIn no respondió: '.mb_substr($e->getMessage(), 0, 150)]);
        }

        $n = 0;
        foreach ($mensajes as $m) {
            DB::transaction(function () use ($m, &$n) {
                if (LinkedinMensaje::where('externo_id', $m['id'])->exists()) {
                    return;
                }
                $fecha = Carbon::parse($m['fecha']);
                $c = LinkedinConversacion::firstOrNew(['externo_id' => $m['conversacion']]);
                $c->fill(['nombre' => mb_substr($m['nombre'], 0, 120), 'cargo' => $m['cargo'] ? mb_substr($m['cargo'], 0, 120) : null,
                    'organizacion' => $m['organizacion'] ? mb_substr($m['organizacion'], 0, 120) : null,
                    'perfil_url' => $m['perfil'] && str_starts_with($m['perfil'], 'https://') ? mb_substr($m['perfil'], 0, 200) : $c->perfil_url]);
                $c->no_leidos = ($c->no_leidos ?? 0) + 1;
                $c->estado = 'abierta';
                $c->ultimo_mensaje_at = $c->ultimo_mensaje_at && $c->ultimo_mensaje_at->gt($fecha) ? $c->ultimo_mensaje_at : $fecha;
                $c->save();
                LinkedinMensaje::create(['conversacion_id' => $c->id, 'direccion' => 'entrante', 'cuerpo' => mb_substr($m['texto'], 0, 4000), 'externo_id' => $m['id'], 'estado' => 'recibido', 'created_at' => $fecha]);
                $n++;
            });
        }
        Integracion::where('tipo', 'linkedin')->update(['salud' => 'ok', 'salud_at' => now()]);

        return $n;
    }

    public function enviar(LinkedinConversacion $c, string $texto, User $user): LinkedinMensaje
    {
        if (! Integracion::activa('linkedin')) {
            throw ValidationException::withMessages(['texto' => 'La integración de LinkedIn no está activa.']);
        }
        $m = LinkedinMensaje::create(['conversacion_id' => $c->id, 'direccion' => 'saliente', 'cuerpo' => $texto, 'estado' => 'en_cola', 'user_id' => $user->id]);
        $c->forceFill(['ultimo_mensaje_at' => now(), 'asignado_a' => $c->asignado_a ?? $user->id, 'estado' => 'abierta'])->save();
        EnviarLinkedin::dispatch($this->tenancy->id(), $m->id, Integracion::de('linkedin')?->reintentos ?? 5)->afterCommit();

        return $m;
    }

    public function leer(LinkedinConversacion $c): void
    {
        if ($c->no_leidos > 0) {
            $c->forceFill(['no_leidos' => 0])->save();
        }
    }

    /** Crea el prospecto en una campaña del contact center a partir de la conversación. */
    public function crearLead(LinkedinConversacion $c, Campana $campana, string $telefono, ?string $comuna, User $user): Lead
    {
        if ($c->lead_id) {
            throw ValidationException::withMessages(['telefono' => 'Esta conversación ya tiene un prospecto.']);
        }
        $tel = preg_replace('/[^\d+]/', '', $telefono);
        if (strlen(preg_replace('/\D/', '', $tel)) < 8) {
            throw ValidationException::withMessages(['telefono' => 'Teléfono no válido.']);
        }
        if (Lead::where('campana_id', $campana->id)->where('telefono', $tel)->exists()) {
            throw ValidationException::withMessages(['telefono' => 'Ese teléfono ya es prospecto de la campaña.']);
        }

        return DB::transaction(function () use ($c, $campana, $tel, $comuna, $user) {
            $lead = Lead::create(['campana_id' => $campana->id, 'nombre' => $c->nombre, 'telefono' => $tel, 'comuna' => $comuna ? mb_substr($comuna, 0, 60) : null,
                'estado' => ListaNegra::bloqueado($tel) ? 'no_contactar' : 'nuevo',
                'notas' => trim('LinkedIn: '.implode(' · ', array_filter([$c->cargo, $c->organizacion, $c->perfil_url])))]);
            $c->forceFill(['lead_id' => $lead->id, 'asignado_a' => $c->asignado_a ?? $user->id])->save();
            $this->auditoria->registrar('linkedin.lead', $c, null, ['lead_id' => $lead->id, 'campana_id' => $campana->id]);

            return $lead;
        });
    }
}
