<?php

namespace App\Modules\Comercial\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListaPrecioItem extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'lista_precio_items';

    protected $guarded = ['id', 'empresa_id'];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
