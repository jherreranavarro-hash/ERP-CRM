<?php

namespace App\Modules\Inventario\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Solicitud de cambio de precios (uno o varios productos); sobre la variación permitida la aprueba otra persona. */
class CambioPrecio extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['por_aprobar' => 'Por aprobar', 'aplicado' => 'Aplicado', 'rechazado' => 'Rechazado'];

    protected $table = 'cambios_precio';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'motivos' => 'array', 'aplicado_at' => 'datetime'];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(CambioPrecioLinea::class)->orderBy('id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
