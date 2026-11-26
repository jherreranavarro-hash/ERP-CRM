<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CambioPrecioLinea extends Model
{
    use PerteneceAEmpresa;

    public const CAMPOS = ['precio_neto' => 'Precio mesón', 'precio_mayorista' => 'Precio mayorista'];

    public $timestamps = false;

    protected $table = 'cambio_precio_lineas';

    protected $guarded = ['id', 'empresa_id'];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }
}
