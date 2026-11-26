<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Guion de venta por pasos, con respuestas que llevan al paso siguiente. */
class Guion extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'guiones';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['pasos' => 'array', 'activo' => 'boolean', 'version' => 'integer'];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Comercial\Models\Categoria::class);
    }

    public function campanas(): HasMany
    {
        return $this->hasMany(Campana::class);
    }
}
