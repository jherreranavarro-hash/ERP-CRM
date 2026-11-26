<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Registro inmutable de cada vez que alguien escucha o descarga una grabación. */
class GrabacionAcceso extends Model
{
    use PerteneceAEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'grabacion_accesos';

    protected $guarded = ['id', 'empresa_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function llamada(): BelongsTo
    {
        return $this->belongsTo(Llamada::class);
    }
}
