<?php

namespace App\Modules\Finanzas\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Cuenta del plan de cuentas. La clave marca las cuentas que usan los asientos automáticos. */
class CuentaContable extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const TIPOS = ['activo' => 'Activo', 'pasivo' => 'Pasivo', 'patrimonio' => 'Patrimonio', 'ingreso' => 'Ingreso', 'gasto' => 'Gasto'];

    /** Saldo natural deudor (activo, gasto) o acreedor (pasivo, patrimonio, ingreso). */
    public const DEUDORAS = ['activo', 'gasto'];

    protected $table = 'cuentas_contables';

    protected $fillable = ['codigo', 'nombre', 'tipo', 'clave', 'activa'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(AsientoLinea::class, 'cuenta_id');
    }
}
