<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Marca de asistencia (inmutable: se corrige con otra marca). */
class Marca extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'marcas';

    protected $guarded = ['id', 'empresa_id'];

    public const UPDATED_AT = null;

    public const ORIGENES = ['reloj' => 'Reloj control', 'app' => 'App', 'manual' => 'Manual'];

    protected function casts(): array
    {
        return ['momento' => 'datetime'];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
