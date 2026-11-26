<?php

namespace App\Modules\Caja\Models;

use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Punto de caja de un local (p. ej. "Caja 1"). */
class Caja extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'cajas';

    protected $fillable = ['local_id', 'nombre', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function turnos(): HasMany
    {
        return $this->hasMany(TurnoCaja::class);
    }
}
