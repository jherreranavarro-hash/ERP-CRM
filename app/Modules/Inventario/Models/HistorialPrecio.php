<?php

namespace App\Modules\Inventario\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historial de precio y costo: lo escribe un trigger de la base de datos; solo lectura. */
class HistorialPrecio extends Model
{
    use PerteneceAEmpresa;

    public const CAMPOS = ['precio_neto' => 'Precio mesón', 'precio_mayorista' => 'Precio mayorista', 'costo' => 'Costo'];

    public const ORIGENES = ['mantenedor' => 'Ficha del producto', 'actualizacion' => 'Actualización de precios', 'recepcion' => 'Recepción de mercadería'];

    public const UPDATED_AT = null;

    protected $table = 'historial_precios';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
