<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Evaluación de calidad de una llamada con checklist y puntaje. */
class AuditoriaCalidad extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'auditorias_calidad';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'puntaje' => 'float'];
    }

    public function llamada(): BelongsTo
    {
        return $this->belongsTo(Llamada::class);
    }

    public function agente(): BelongsTo
    {
        return $this->belongsTo(Agente::class);
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }
}
