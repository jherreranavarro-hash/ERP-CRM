<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Turno de trabajo: horario y días. */
class Turno extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'turnos';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['dias' => 'array', 'activo' => 'boolean', 'colacion_min' => 'integer'];
    }

    /** Minutos efectivos del turno (descontando colación). */
    public function minutos(): int
    {
        [$h1, $m1] = array_map('intval', explode(':', $this->hora_inicio));
        [$h2, $m2] = array_map('intval', explode(':', $this->hora_fin));
        $min = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
        if ($min <= 0) {
            $min += 24 * 60; // turno que cruza la medianoche
        }

        return max(0, $min - $this->colacion_min);
    }
}
