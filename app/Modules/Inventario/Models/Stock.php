<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Existencia de un producto en un local. Solo la mueve el kardex (trigger). */
class Stock extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'stock';

    protected $fillable = ['producto_id', 'local_id', 'minimo'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:6', 'costo_promedio' => 'decimal:6', 'minimo' => 'decimal:6'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }
}
