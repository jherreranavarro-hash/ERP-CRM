<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecepcionLinea extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'recepcion_lineas';

    protected $fillable = ['producto_id', 'orden_compra_linea_id', 'cantidad', 'costo_unitario'];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }
}
