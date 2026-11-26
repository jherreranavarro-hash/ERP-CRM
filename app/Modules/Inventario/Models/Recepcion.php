<?php

namespace App\Modules\Inventario\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Recepción de mercadería: al confirmarla genera entradas de kardex con su costo. */
class Recepcion extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'recepciones';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'fecha' => 'date', 'costo_total' => 'integer', 'confirmado_at' => 'datetime'];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(RecepcionLinea::class)->orderBy('id');
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function confirmador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }
}
