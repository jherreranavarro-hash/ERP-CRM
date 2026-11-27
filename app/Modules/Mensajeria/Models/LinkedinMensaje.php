<?php

namespace App\Modules\Mensajeria\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkedinMensaje extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'li_mensajes';

    protected $guarded = ['id', 'empresa_id'];

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(LinkedinConversacion::class, 'conversacion_id');
    }
}
