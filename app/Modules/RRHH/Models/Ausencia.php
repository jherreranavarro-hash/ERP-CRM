<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Vacaciones, licencia o permiso. */
class Ausencia extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'ausencias';

    protected $guarded = ['id', 'empresa_id'];

    public const TIPOS = ['vacaciones' => 'Vacaciones', 'licencia' => 'Licencia médica', 'permiso_con_goce' => 'Permiso con goce', 'permiso_sin_goce' => 'Permiso sin goce'];

    public const ESTADOS = ['solicitada' => 'Solicitada', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada', 'anulada' => 'Anulada'];

    /** Tipos que no se pagan (rebajan días trabajados). La licencia la paga la institución de salud. */
    public const NO_PAGADAS = ['licencia', 'permiso_sin_goce'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'resuelto_at' => 'datetime', 'dias_habiles' => 'integer'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }
}
