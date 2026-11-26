<?php

namespace App\Modules\Ventas\Models;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use App\Modules\Facturacion\Models\Documento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Orden de venta confirmada: reserva stock en su local hasta que se factura,
 * se anula o vence la reserva. Se escribe solo vía App\Modules\Ventas\OrdenesVenta.
 */
class OrdenVenta extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['confirmada' => 'Confirmada', 'facturada' => 'Facturada', 'anulada' => 'Anulada', 'vencida' => 'Reserva vencida'];

    protected $table = 'ordenes_venta';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return [
            'numero' => 'integer', 'fecha' => 'date', 'reserva_hasta' => 'date',
            'descuento_global_monto' => 'integer', 'monto_neto' => 'integer', 'monto_exento' => 'integer', 'monto_iva' => 'integer', 'monto_total' => 'integer',
        ];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(OrdenVentaLinea::class)->orderBy('orden');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /** La reserva cuenta mientras la orden está confirmada y vigente. */
    public function reservando(): bool
    {
        return $this->estado === 'confirmada' && ! $this->reserva_hasta->lt(now()->startOfDay());
    }

    public function estadoVisible(): string
    {
        return $this->estado === 'confirmada' && ! $this->reservando() ? 'vencida' : $this->estado;
    }
}
