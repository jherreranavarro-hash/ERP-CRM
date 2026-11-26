<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago a proveedor: sobre el umbral espera una segunda aprobación antes de rebajar la deuda. */
class PagoProveedor extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const METODOS = ['transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'vale_vista' => 'Vale vista', 'efectivo' => 'Efectivo'];

    public const ESTADOS = ['por_aprobar' => 'Por aprobar', 'aplicado' => 'Aplicado', 'rechazado' => 'Rechazado'];

    protected $table = 'pagos_proveedor';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['monto' => 'integer', 'fecha' => 'date', 'aprobado_at' => 'datetime'];
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(FacturaProveedor::class, 'factura_proveedor_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
