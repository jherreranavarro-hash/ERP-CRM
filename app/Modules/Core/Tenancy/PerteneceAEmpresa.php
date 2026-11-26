<?php

namespace App\Modules\Core\Tenancy;

use App\Modules\Core\Models\Empresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tercera capa del multi-tenant: filtro por empresa en cada consulta Eloquent y
 * asignación automática de empresa_id al crear. El RLS de la base es la barrera
 * real; esto evita depender solo de ella y da errores claros en desarrollo.
 */
trait PerteneceAEmpresa
{
    public static function bootPerteneceAEmpresa(): void
    {
        static::addGlobalScope('empresa', function (Builder $q) {
            $q->where($q->getModel()->getTable().'.empresa_id', app(Tenancy::class)->id());
        });

        static::creating(function ($modelo) {
            $id = app(Tenancy::class)->id()
                ?? throw new \LogicException('No hay empresa activa para crear '.class_basename($modelo));
            $modelo->empresa_id = $id;
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
