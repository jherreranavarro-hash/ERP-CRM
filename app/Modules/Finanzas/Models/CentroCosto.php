<?php

namespace App\Modules\Finanzas\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentroCosto extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'centros_costo';

    protected $fillable = ['codigo', 'nombre', 'local_id', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Core\Models\Local::class);
    }
}
