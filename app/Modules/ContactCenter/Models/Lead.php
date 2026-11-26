<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Prospecto de una campaña, asignado a un agente. */
class Lead extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['nuevo' => 'Nuevo', 'asignado' => 'Asignado', 'contactado' => 'Contactado', 'interesado' => 'Interesado', 'cotizado' => 'Cotizado',
        'ganado' => 'Ganado', 'perdido' => 'Perdido', 'no_contactar' => 'No contactar'];

    public const ABIERTOS = ['nuevo', 'asignado', 'contactado', 'interesado', 'cotizado'];

    protected $table = 'leads';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['asignado_at' => 'datetime', 'ultimo_contacto_at' => 'datetime', 'proximo_contacto_at' => 'datetime', 'intentos' => 'integer'];
    }

    public function campana(): BelongsTo
    {
        return $this->belongsTo(Campana::class);
    }

    public function agente(): BelongsTo
    {
        return $this->belongsTo(Agente::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Comercial\Models\Cliente::class);
    }

    public function llamadas(): HasMany
    {
        return $this->hasMany(Llamada::class);
    }
}
