<?php

namespace App\Modules\Finanzas\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Movimiento de una cartola bancaria: abono (+) o cargo (−), conciliado con un pago, un pago a proveedor o un asiento. */
class MovimientoBancario extends Model
{
    use PerteneceAEmpresa;

    public const ESTADOS = ['pendiente' => 'Pendiente', 'conciliado' => 'Conciliado', 'ignorado' => 'Ignorado'];

    protected $table = 'movimientos_bancarios';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'integer', 'conciliado_at' => 'datetime'];
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class);
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Facturacion\Models\Pago::class);
    }

    public function pagoProveedor(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Compras\Models\PagoProveedor::class);
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }

    public function conciliador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conciliado_por');
    }
}
