<?php

namespace App\Modules\Operacion\Models;

use App\Models\User;
use App\Modules\Core\Models\Rol;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cuatro ojos sobre RBAC: agregar permisos a un rol u otorgar la marca SuperAdmin. */
class SolicitudPrivilegio extends Model
{
    protected $table = 'solicitudes_privilegio';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['permisos' => 'array', 'otorgar' => 'boolean', 'resuelto_at' => 'datetime'];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class)->withTrashed();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }
}
