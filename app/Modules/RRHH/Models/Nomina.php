<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Proceso de remuneraciones de un mes. */
class Nomina extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'nominas';

    protected $guarded = ['id', 'empresa_id'];

    public const ESTADOS = ['borrador' => 'Borrador', 'aprobada' => 'Aprobada', 'pagada' => 'Pagada'];

    protected function casts(): array
    {
        return ['indicadores' => 'array', 'aprobado_at' => 'datetime', 'fecha_pago' => 'date', 'total_haberes' => 'integer', 'total_descuentos' => 'integer', 'total_liquido' => 'integer', 'total_empleador' => 'integer'];
    }

    public function liquidaciones(): HasMany
    {
        return $this->hasMany(Liquidacion::class);
    }

    public function generador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
