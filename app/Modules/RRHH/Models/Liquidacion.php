<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Liquidación de sueldo de una persona en una nómina. */
class Liquidacion extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'liquidaciones';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['haberes' => 'array', 'descuentos' => 'array', 'aportes' => 'array', 'horas_extra' => 'decimal:2', 'dias_trabajados' => 'integer',
            'imponible' => 'integer', 'no_imponible' => 'integer', 'tributable' => 'integer', 'impuesto' => 'integer', 'total_descuentos' => 'integer', 'liquido' => 'integer', 'costo_empleador' => 'integer'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function nomina(): BelongsTo
    {
        return $this->belongsTo(Nomina::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }
}
