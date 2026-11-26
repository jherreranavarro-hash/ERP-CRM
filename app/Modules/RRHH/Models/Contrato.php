<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Contrato de trabajo (el historial de cargos de la ficha). */
class Contrato extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'contratos';

    protected $guarded = ['id', 'empresa_id'];

    public const TIPOS = ['indefinido' => 'Indefinido', 'plazo_fijo' => 'Plazo fijo', 'obra' => 'Por obra o faena'];

    public const ESTADOS = ['borrador' => 'Borrador', 'por_firmar' => 'Por firmar', 'vigente' => 'Vigente', 'terminado' => 'Terminado'];

    protected function casts(): array
    {
        return ['fecha_inicio' => 'date', 'fecha_termino' => 'date', 'firmado_at' => 'datetime', 'gratificacion' => 'boolean', 'sueldo_base' => 'integer',
            'colacion' => 'integer', 'movilizacion' => 'integer', 'jornada_horas' => 'integer', 'comision_pct' => 'decimal:2'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaContrato::class);
    }
}
