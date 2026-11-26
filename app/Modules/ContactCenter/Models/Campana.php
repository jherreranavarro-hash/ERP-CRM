<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Campaña comercial con metas, horario, guion y agentes. */
class Campana extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const TIPOS = ['saliente' => 'Llamadas salientes', 'entrante' => 'Atención entrante', 'terreno' => 'Visitas en terreno'];

    public const ESTADOS = ['borrador' => 'Borrador', 'activa' => 'Activa', 'pausada' => 'Pausada', 'terminada' => 'Terminada'];

    public const ASIGNACION = ['round_robin' => 'Rotativa (round-robin)', 'zona' => 'Por zona (comuna)', 'carga' => 'Por carga (menos leads abiertos)'];

    protected $table = 'campanas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['horario' => 'array', 'fecha_inicio' => 'date', 'fecha_fin' => 'date', 'meta_contactos' => 'integer', 'meta_cotizaciones' => 'integer', 'meta_ventas' => 'integer', 'max_intentos' => 'integer'];
    }

    public function agentes(): BelongsToMany
    {
        return $this->belongsToMany(Agente::class, 'campana_agente')->withPivot('empresa_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function guion(): BelongsTo
    {
        return $this->belongsTo(Guion::class);
    }

    public function cola(): BelongsTo
    {
        return $this->belongsTo(Cola::class);
    }

    /** ¿Se puede llamar ahora según el horario de la campaña? */
    public function enHorario(?\Carbon\CarbonInterface $momento = null): bool
    {
        $m = $momento ?? now();
        $h = $this->horario ?? [];

        return in_array($m->dayOfWeekIso, $h['dias'] ?? [], true) && $m->format('H:i') >= ($h['desde'] ?? '00:00') && $m->format('H:i') < ($h['hasta'] ?? '23:59');
    }
}
