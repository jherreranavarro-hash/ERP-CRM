<?php

namespace App\Modules\Comercial\Models;

use App\Modules\Core\Models\Rol;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Escalón de aprobación: un descuento de hasta "hasta_pct" lo aprueba este rol (o uno de un escalón superior). */
class NivelDescuento extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'niveles_descuento';

    protected $guarded = ['id', 'empresa_id'];

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }
}
