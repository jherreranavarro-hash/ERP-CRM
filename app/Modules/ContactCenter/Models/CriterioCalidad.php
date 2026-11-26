<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Criterio del checklist de calidad con su peso. */
class CriterioCalidad extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'criterios_calidad';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['peso' => 'integer', 'critico' => 'boolean', 'activo' => 'boolean', 'orden' => 'integer'];
    }
}
