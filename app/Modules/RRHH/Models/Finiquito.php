<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Término de contrato y cálculo del finiquito. */
class Finiquito extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'finiquitos';

    protected $guarded = ['id', 'empresa_id'];

    public const CAUSALES = [
        '159-1' => 'Mutuo acuerdo de las partes (art. 159 N° 1)',
        '159-2' => 'Renuncia voluntaria (art. 159 N° 2)',
        '159-4' => 'Vencimiento del plazo convenido (art. 159 N° 4)',
        '159-5' => 'Conclusión del trabajo o servicio (art. 159 N° 5)',
        '160' => 'Conductas indebidas o incumplimiento grave (art. 160)',
        '161-1' => 'Necesidades de la empresa (art. 161 inciso 1°)',
        '161-2' => 'Desahucio (art. 161 inciso 2°)',
    ];

    /** Causales que dan derecho a indemnización por años de servicio y a la sustitutiva del aviso previo. */
    public const CON_INDEMNIZACION = ['161-1', '161-2'];

    public const ESTADOS = ['borrador' => 'Borrador', 'aprobado' => 'Aprobado', 'firmado' => 'Firmado', 'anulado' => 'Anulado'];

    protected function casts(): array
    {
        return ['fecha_termino' => 'date', 'aviso_previo' => 'boolean', 'conceptos' => 'array', 'base_calculo' => 'integer', 'total' => 'integer', 'anios_servicio' => 'decimal:2', 'aprobado_at' => 'datetime'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(Contrato::class);
    }
}
