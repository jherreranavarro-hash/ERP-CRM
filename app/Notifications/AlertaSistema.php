<?php

namespace App\Notifications;

use App\Modules\Operacion\Models\Alerta;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Correo de una alerta del panel "Operación y continuidad". Nunca lleva secretos. */
class AlertaSistema extends Notification
{
    use Queueable;

    public function __construct(private Alerta $alerta) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)
            ->subject(($this->alerta->tipo === 'seguridad' ? '[Seguridad] ' : '[Operación] ').$this->alerta->titulo)
            ->line($this->alerta->titulo);
        foreach ((array) $this->alerta->detalle as $k => $v) {
            $m->line(ucfirst(str_replace('_', ' ', (string) $k)).': '.(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)));
        }

        return $m->action('Ver alertas', route('configuracion.operacion.index'));
    }
}
