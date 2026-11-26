<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Factura recibida de un proveedor. Si calza con la OC y la recepción, es una cuenta por pagar. */
class FacturaProveedor extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['por_revisar' => 'Por revisar', 'por_pagar' => 'Por pagar', 'pagada' => 'Pagada', 'rechazada' => 'Rechazada'];

    public const TIPOS = [33 => 'Factura', 34 => 'Factura exenta', 46 => 'Factura de compra'];

    protected $table = 'facturas_proveedor';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['folio' => 'integer', 'tipo_dte' => 'integer', 'fecha_emision' => 'date', 'fecha_vencimiento' => 'date', 'diferencias' => 'array',
            'monto_neto' => 'integer', 'monto_exento' => 'integer', 'monto_iva' => 'integer', 'monto_total' => 'integer', 'saldo' => 'integer'];
    }

    public function nombre(): string
    {
        return self::TIPOS[$this->tipo_dte]." N° {$this->folio}";
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(FacturaProveedorLinea::class)->orderBy('id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PagoProveedor::class)->orderBy('id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    public function ordenCompra(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class);
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
