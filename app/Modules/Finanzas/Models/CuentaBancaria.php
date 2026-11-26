<?php

namespace App\Modules\Finanzas\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CuentaBancaria extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'cuentas_bancarias';

    protected $fillable = ['banco', 'numero', 'nombre', 'cuenta_contable_id', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function cuentaContable(): BelongsTo
    {
        return $this->belongsTo(CuentaContable::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoBancario::class);
    }
}
