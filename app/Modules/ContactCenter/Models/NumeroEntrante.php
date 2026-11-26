<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Número de teléfono (DID) que entra a un IVR o a una cola. */
class NumeroEntrante extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'numeros_entrantes';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(ProveedorIp::class, 'proveedor_ip_id');
    }
}
