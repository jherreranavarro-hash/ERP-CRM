<?php

namespace App\Modules\Terreno\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Recorrido diario de un ejecutivo de terreno. */
class Recorrido extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['planificado' => 'Planificado', 'en_curso' => 'En curso', 'cerrado' => 'Cerrado'];

    protected $table = 'recorridos';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'origen_lat' => 'float', 'origen_lng' => 'float', 'distancia_km' => 'float', 'duracion_min' => 'integer', 'optimizado_at' => 'datetime'];
    }

    public function ejecutivo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function paradas(): HasMany
    {
        return $this->hasMany(Parada::class, 'recorrido_id')->orderBy('orden');
    }
}
