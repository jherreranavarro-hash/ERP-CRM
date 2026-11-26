<?php

namespace App\Modules\Compras\Models;

use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenCompraLinea extends Model
{
    use PerteneceAEmpresa;

    public $timestamps = false;

    protected $table = 'orden_compra_lineas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['monto' => 'integer'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function ordenCompra(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class);
    }

    public function pendiente(): BigDecimal
    {
        return BigDecimal::of((string) $this->cantidad)->minus((string) $this->cantidad_recibida);
    }

    /** Recibido y aún no facturado. */
    public function porFacturar(): BigDecimal
    {
        return BigDecimal::of((string) $this->cantidad_recibida)->minus((string) $this->cantidad_facturada);
    }
}
