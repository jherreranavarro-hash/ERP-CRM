<?php

namespace App\Modules\Mensajeria\Trabajos;

use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Integraciones\Whatsapp\Whatsapp;
use App\Modules\Mensajeria\Bandeja;
use App\Modules\Mensajeria\Models\Mensaje;
use App\Modules\Mensajeria\Models\Plantilla;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/** Envía un mensaje por la cola, con reintentos y espera exponencial según la ficha de la integración. */
class EnviarWhatsapp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    /** @param  list<string>  $variables */
    public function __construct(public int $empresaId, public int $mensajeId, public array $variables = [], int $reintentos = 5)
    {
        $this->tries = max(1, $reintentos + 1);
        $this->onQueue('correo');
    }

    public function backoff(): array
    {
        return array_map(fn ($i) => 30 * 2 ** $i, range(0, max(0, $this->tries - 2)));   // 30 s, 1, 2, 4, 8 min…
    }

    public function handle(Whatsapp $wa, Tenancy $tenancy, Bandeja $bandeja): void
    {
        $tenancy->como(Empresa::findOrFail($this->empresaId), function () use ($wa, $bandeja) {
            $m = Mensaje::with('conversacion')->find($this->mensajeId);
            if (! $m || $m->estado !== 'en_cola') {
                return;
            }
            try {
                $id = $m->plantilla_id
                    ? $wa->enviarPlantilla($m->conversacion->telefono, ($p = Plantilla::findOrFail($m->plantilla_id))->nombre, $p->idioma, $this->variables)
                    : $wa->enviarTexto($m->conversacion->telefono, $m->cuerpo);
                $m->forceFill(['wa_id' => $id, 'estado' => 'enviado', 'error' => null])->save();
                Integracion::where('tipo', 'whatsapp')->update(['salud' => 'ok', 'salud_at' => now()]);
            } catch (\Throwable $e) {
                $ultimo = $this->attempts() >= $this->tries;
                $m->forceFill(['error' => mb_substr($e->getMessage(), 0, 200)] + ($ultimo ? ['estado' => 'fallido'] : []))->save();
                if (! $ultimo) {
                    $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                } else {
                    Log::warning('WhatsApp: mensaje '.$m->id.' sin enviar tras '.$this->tries.' intentos.');
                }
            }
            $bandeja->avisar($m->conversacion);
        });
    }
}
