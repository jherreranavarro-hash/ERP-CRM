<?php

namespace App\Modules\Mensajeria\Trabajos;

use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Integraciones\Linkedin\Linkedin;
use App\Modules\Mensajeria\Models\LinkedinConversacion;
use App\Modules\Mensajeria\Models\LinkedinMensaje;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Envía un mensaje de LinkedIn por la cola, con reintentos y espera exponencial. */
class EnviarLinkedin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries;

    public function __construct(public int $empresaId, public int $mensajeId, int $reintentos = 5)
    {
        $this->tries = max(1, $reintentos + 1);
        $this->onQueue('correo');
    }

    public function backoff(): array
    {
        return array_map(fn ($i) => 30 * 2 ** $i, range(0, max(0, $this->tries - 2)));
    }

    public function handle(Linkedin $li, Tenancy $tenancy): void
    {
        $tenancy->como(Empresa::findOrFail($this->empresaId), function () use ($li) {
            $m = LinkedinMensaje::find($this->mensajeId);
            if (! $m || $m->estado !== 'en_cola') {
                return;
            }
            $c = LinkedinConversacion::findOrFail($m->conversacion_id);
            try {
                $m->forceFill(['externo_id' => $li->enviar($c->externo_id, $m->cuerpo), 'estado' => 'enviado', 'error' => null])->save();
            } catch (\Throwable $e) {
                $ultimo = $this->attempts() >= $this->tries;
                $m->forceFill(['error' => mb_substr($e->getMessage(), 0, 200)] + ($ultimo ? ['estado' => 'fallido'] : []))->save();
                if (! $ultimo) {
                    $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
                }
            }
        });
    }
}
