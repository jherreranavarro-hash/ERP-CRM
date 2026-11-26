<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anticipo de sueldo (quincena), descontado en la liquidación del mes. */
class Anticipo extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['registrado' => 'Por descontar', 'descontado' => 'Descontado', 'anulado' => 'Anulado'];

    protected $table = 'anticipos';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'integer'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
