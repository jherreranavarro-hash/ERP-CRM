<?php

namespace App\Modules\ContactCenter\Eventos;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Cambio de estado de un agente o una llamada, empujado al wallboard por WebSocket. */
class ContactCenterActualizado implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    public function __construct(public int $empresaId, public string $tipo, public array $datos) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("contact-center.{$this->empresaId}")];
    }

    public function broadcastAs(): string
    {
        return 'actualizado';
    }

    public function broadcastWith(): array
    {
        return ['tipo' => $this->tipo, 'datos' => $this->datos, 'momento' => now()->toIso8601String()];
    }
}
