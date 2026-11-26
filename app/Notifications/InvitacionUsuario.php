<?php

namespace App\Notifications;

use App\Modules\Core\Models\Invitacion;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitacionUsuario extends Notification
{
    use Queueable;

    public function __construct(private Invitacion $invitacion, private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Invitación al Sistema de Gestión OBMAX')
            ->greeting('Hola '.$this->invitacion->nombre)
            ->line('Te invitaron a usar el Sistema de Gestión de '.$this->invitacion->empresa->nombreCorto().'.')
            ->action('Activar mi cuenta', route('invitacion.mostrar', $this->token))
            ->line('El enlace vence el '.$this->invitacion->expira_at->timezone('America/Santiago')->format('d-m-Y H:i').'.')
            ->line('Si no esperabas este correo, ignóralo.');
    }
}
