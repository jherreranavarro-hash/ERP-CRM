<?php

namespace App\Modules\Operacion\Models;

use Illuminate\Database\Eloquent\Model;

class RetencionEjecucion extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'retencion_ejecuciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'limite' => 'date', 'registros' => 'integer'];
    }
}
