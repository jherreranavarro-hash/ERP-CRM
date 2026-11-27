<?php

namespace App\Modules\Comercial\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lista de precios. Mesón es la base (precio neto del producto); mayorista y las
 * listas propias se calculan con un porcentaje bajo mesón o con precio propio.
 */
class ListaPrecio extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'listas_precios';

    protected $guarded = ['id', 'empresa_id', 'sistema'];

    public const REGLAS = ['base' => 'Base (precio del producto)', 'porcentaje' => '% bajo mesón', 'propio' => 'Precio propio por producto'];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'sistema' => 'boolean', 'vigente_desde' => 'date', 'vigente_hasta' => 'date'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ListaPrecioItem::class, 'lista_id');
    }

    public function vigente(?\DateTimeInterface $fecha = null): bool
    {
        $f = $fecha ? \Illuminate\Support\Carbon::instance($fecha)->startOfDay() : now()->startOfDay();

        return $this->activa
            && ($this->vigente_desde === null || $this->vigente_desde->lte($f))
            && ($this->vigente_hasta === null || $this->vigente_hasta->gte($f));
    }
}
