<?php

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use App\Modules\Inventario\Models\Recepcion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Orden de compra: borrador → (por aprobar) → aprobada → parcial → recibida; o cerrada, anulada, rechazada. */
class OrdenCompra extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['borrador' => 'Borrador', 'por_aprobar' => 'Por aprobar', 'aprobada' => 'Aprobada', 'parcial' => 'Recibida en parte', 'recibida' => 'Recibida', 'cerrada' => 'Cerrada', 'anulada' => 'Anulada', 'rechazada' => 'Rechazada'];

    /** Estados en que se puede recibir mercadería. */
    public const RECIBIBLE = ['aprobada', 'parcial'];

    protected $table = 'ordenes_compra';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'fecha' => 'date', 'fecha_entrega' => 'date', 'motivos' => 'array', 'aprobado_at' => 'datetime',
            'monto_neto' => 'integer', 'monto_iva' => 'integer', 'monto_total' => 'integer'];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(OrdenCompraLinea::class)->orderBy('id');
    }

    public function cotizaciones(): HasMany
    {
        return $this->hasMany(OrdenCompraCotizacion::class)->orderBy('monto_neto');
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(Recepcion::class);
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(FacturaProveedor::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
