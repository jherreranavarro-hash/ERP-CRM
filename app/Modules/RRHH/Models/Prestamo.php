<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Préstamo al personal, descontado en cuotas de la liquidación. */
class Prestamo extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'prestamos';

    protected $guarded = ['id', 'empresa_id'];

    public const ESTADOS = ['por_aprobar' => 'Por aprobar', 'vigente' => 'Vigente', 'pagado' => 'Pagado', 'rechazado' => 'Rechazado'];

    protected function casts(): array
    {
        return ['monto' => 'integer', 'cuotas' => 'integer', 'valor_cuota' => 'integer', 'saldo' => 'integer', 'aprobado_at' => 'datetime'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }
}
