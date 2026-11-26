<?php

namespace App\Modules\Comercial\Models;

use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Precios unitarios netos en numeric(18,6), manejados como string (nunca float). */
class Producto extends Model
{
    use Auditable, PerteneceAEmpresa, SoftDeletes;

    protected $fillable = ['sku', 'nombre', 'categoria_id', 'unidad', 'precio_neto', 'precio_mayorista', 'costo', 'exento', 'activo'];

    protected function casts(): array
    {
        return ['exento' => 'boolean', 'activo' => 'boolean'];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    /** Precio neto de lista según la lista del cliente (mesón o mayorista). */
    public function precioNetoPara(string $lista): string
    {
        return $lista === 'mayorista' && $this->precio_mayorista !== null ? (string) $this->precio_mayorista : (string) $this->precio_neto;
    }
}
