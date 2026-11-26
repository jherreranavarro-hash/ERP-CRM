<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tasa de comisión para una categoría de producto (para un vendedor o para todos). */
class ReglaComision extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'reglas_comision';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['tasa' => 'decimal:2'];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Comercial\Models\Categoria::class);
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }
}
