<?php

namespace App\Modules\Operacion\Models;

use Illuminate\Database\Eloquent\Model;

/** Bitácora de respaldos y pruebas de restauración (la escriben los scripts del servidor). */
class Respaldo extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'respaldos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'bytes' => 'integer', 'duracion_s' => 'integer'];
    }
}
