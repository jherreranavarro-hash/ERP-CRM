<?php

namespace App\Modules\Mensajeria\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mensaje extends Model
{
    use PerteneceAEmpresa;

    /** Orden de avance del estado: un aviso atrasado no retrocede el mensaje. */
    public const AVANCE = ['en_cola' => 0, 'enviado' => 1, 'entregado' => 2, 'leido' => 3];

    protected $table = 'wa_mensajes';

    protected $guarded = ['id', 'empresa_id'];

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(Conversacion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
