<?php

namespace App\Modules\Comercial\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Descuento automático por categoría, lista del cliente y cantidad mínima, con vigencia. */
class ReglaDescuento extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'reglas_descuento';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'vigente_desde' => 'date', 'vigente_hasta' => 'date'];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }
}
