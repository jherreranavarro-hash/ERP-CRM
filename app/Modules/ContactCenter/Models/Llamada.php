<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Llamada entrante o saliente, con su resultado y la referencia a la grabación en la PBX. */
class Llamada extends Model
{
    use PerteneceAEmpresa;

    public const ESTADOS = ['marcando' => 'Marcando', 'en_cola' => 'En cola', 'en_curso' => 'En curso', 'terminada' => 'Terminada', 'no_contesta' => 'No contesta', 'ocupado' => 'Ocupado', 'fallida' => 'Fallida'];

    public const ACTIVAS = ['marcando', 'en_cola', 'en_curso'];

    public const RESULTADOS = ['interesado' => 'Interesado', 'cotizacion' => 'Pidió cotización', 'venta' => 'Venta cerrada', 'volver_a_llamar' => 'Volver a llamar',
        'no_interesado' => 'No interesado', 'numero_erroneo' => 'Número erróneo', 'no_contactar' => 'Pidió no ser contactado', 'consulta' => 'Consulta resuelta', 'reclamo' => 'Reclamo'];

    protected $table = 'llamadas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['inicio' => 'datetime', 'contestada_at' => 'datetime', 'fin' => 'datetime', 'espera_seg' => 'integer', 'duracion_seg' => 'integer'];
    }

    public function agente(): BelongsTo
    {
        return $this->belongsTo(Agente::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function campana(): BelongsTo
    {
        return $this->belongsTo(Campana::class);
    }

    public function auditoria(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AuditoriaCalidad::class);
    }
}
