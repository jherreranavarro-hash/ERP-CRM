<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cola de atención de llamadas entrantes. */
class Cola extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTRATEGIAS = ['todos' => 'Timbrar a todos', 'round_robin' => 'Rotativa', 'menos_reciente' => 'Agente con más tiempo libre', 'menor_carga' => 'Menor cantidad de llamadas'];

    protected $table = 'colas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'espera_max_seg' => 'integer', 'nivel_servicio_seg' => 'integer'];
    }

    public function agentes(): HasMany
    {
        return $this->hasMany(Agente::class);
    }

    public function desborde(): BelongsTo
    {
        return $this->belongsTo(Ivr::class, 'ivr_desborde_id');
    }
}
