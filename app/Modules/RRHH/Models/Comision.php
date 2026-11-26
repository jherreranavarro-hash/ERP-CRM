<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Comisión de un vendedor por un documento emitido. */
class Comision extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'comisiones';

    protected $guarded = ['id', 'empresa_id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['base' => 'integer', 'monto' => 'integer', 'tasa' => 'decimal:2'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Facturacion\Models\Documento::class);
    }
}
