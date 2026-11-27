<?php

namespace App\Modules\Operacion\Models;

use Illuminate\Database\Eloquent\Model;

/** Alerta de seguridad u operación. empresa_id null = alerta de plataforma (RLS la deja ver a todos). */
class Alerta extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'alertas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['detalle' => 'array', 'notificada_a' => 'array', 'atendida_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
