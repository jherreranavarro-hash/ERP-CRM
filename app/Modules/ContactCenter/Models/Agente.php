<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Agente del contact center: usuario del sistema con extensión, habilidades y estado. */
class Agente extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['disponible' => 'Disponible', 'en_llamada' => 'En llamada', 'pausa' => 'En pausa', 'desconectado' => 'Desconectado'];

    public const PAUSAS = ['colacion' => 'Colación', 'bano' => 'Baño', 'capacitacion' => 'Capacitación', 'administrativo' => 'Trabajo administrativo'];

    protected $table = 'agentes';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['habilidades' => 'array', 'zonas' => 'array', 'activo' => 'boolean', 'capacidad' => 'integer', 'estado_desde' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cola(): BelongsTo
    {
        return $this->belongsTo(Cola::class);
    }

    public function campanas(): BelongsToMany
    {
        return $this->belongsToMany(Campana::class, 'campana_agente')->withPivot('empresa_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function nombre(): string
    {
        return $this->user?->name ?? 'Agente '.$this->extension;
    }
}
