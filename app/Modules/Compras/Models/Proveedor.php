<?php

namespace App\Modules\Compras\Models;

use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use Auditable, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'proveedores';

    protected $fillable = ['rut', 'razon_social', 'giro', 'direccion', 'comuna', 'email', 'telefono', 'contacto', 'plazo_pago_dias', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'plazo_pago_dias' => 'integer'];
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(FacturaProveedor::class);
    }

    /** Deuda vigente con el proveedor (facturas por pagar). */
    public function deuda(): int
    {
        return (int) $this->facturas()->where('estado', 'por_pagar')->sum('saldo');
    }
}
