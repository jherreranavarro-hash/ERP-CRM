<?php

namespace App\Modules\Compras\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaProveedorLinea extends Model
{
    use PerteneceAEmpresa;

    public $timestamps = false;

    protected $table = 'factura_proveedor_lineas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['monto' => 'integer'];
    }

    public function lineaOc(): BelongsTo
    {
        return $this->belongsTo(OrdenCompraLinea::class, 'orden_compra_linea_id');
    }
}
