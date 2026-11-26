<?php

namespace App\Modules\Caja\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use App\Modules\Facturacion\Models\Pago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turno de caja de un usuario: apertura con fondo, pagos del turno y cierre con
 * cuadratura por medio de pago. Se escribe solo a través de App\Modules\Caja\Turnos.
 */
class TurnoCaja extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'turnos_caja';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return [
            'monto_apertura' => 'integer', 'diferencia' => 'integer', 'abierto_at' => 'datetime', 'cerrado_at' => 'datetime', 'aprobado_at' => 'datetime',
            'esperado' => 'array', 'declarado' => 'array', 'requiere_aprobacion' => 'boolean',
        ];
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'turno_id');
    }

    public function abierto(): bool
    {
        return $this->estado === 'abierto';
    }

    public function pendienteDeAprobacion(): bool
    {
        return $this->requiere_aprobacion && ! $this->aprobado_por;
    }
}
