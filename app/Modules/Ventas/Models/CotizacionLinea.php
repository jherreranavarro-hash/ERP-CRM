<?php

namespace App\Modules\Ventas\Models;

use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotizacionLinea extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'cotizacion_lineas';

    protected $fillable = ['orden', 'producto_id', 'codigo', 'descripcion', 'cantidad', 'unidad', 'precio_unitario', 'precio_lista', 'descuento_pct', 'descuento_monto', 'monto_item', 'exento'];

    protected function casts(): array
    {
        return ['exento' => 'boolean', 'descuento_monto' => 'integer', 'monto_item' => 'integer', 'orden' => 'integer'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }
}
