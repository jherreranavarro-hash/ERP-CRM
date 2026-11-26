<?php

namespace App\Modules\Inventario\Models;

use App\Models\User;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora de transiciones de un despacho (solo inserción). */
class DespachoEvento extends Model
{
    use PerteneceAEmpresa;

    public const UPDATED_AT = null;

    protected $table = 'despacho_eventos';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
