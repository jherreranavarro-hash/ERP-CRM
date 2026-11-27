<?php

namespace App\Modules\Mensajeria\Eventos;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** Aviso de que una conversación cambió. No lleva el texto: la pantalla vuelve a pedir los datos con permisos. */
class WhatsappActualizado implements ShouldBroadcastNow
{
    public function __construct(public int $empresaId, public int $conversacionId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("whatsapp.{$this->empresaId}")];
    }

    public function broadcastAs(): string
    {
        return 'actualizado';
    }

    public function broadcastWith(): array
    {
        return ['conversacion' => $this->conversacionId];
    }
}
