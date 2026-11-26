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
 * Cotización: montos netos (como una factura). Se escribe solo a través de
 * App\Modules\Ventas\Cotizaciones; convertida o rechazada queda congelada en BD.
 */
class Cotizacion extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'cotizaciones';

    public const ESTADOS = ['borrador' => 'Borrador', 'por_aprobar' => 'Por aprobar', 'enviada' => 'Enviada', 'aceptada' => 'Aceptada', 'rechazada' => 'Rechazada', 'convertida' => 'Convertida', 'vencida' => 'Vencida'];

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return [
            'numero' => 'integer', 'fecha' => 'date', 'valida_hasta' => 'date',
            'descuento_global_monto' => 'integer', 'monto_neto' => 'integer', 'monto_exento' => 'integer',
            'monto_iva' => 'integer', 'monto_total' => 'integer', 'aprobacion_motivos' => 'array',
        ];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(CotizacionLinea::class)->orderBy('orden');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }

    /** Una enviada también se edita (p. ej. para renovar la vigencia): vuelve a borrador y se reenvía. */
    public function editable(): bool
    {
        return in_array($this->estado, ['borrador', 'por_aprobar', 'enviada'], true);
    }

    /** Vencida no se guarda: se deduce de la vigencia mientras sigue abierta. */
    public function vencida(): bool
    {
        return in_array($this->estado, ['borrador', 'por_aprobar', 'enviada'], true) && $this->valida_hasta->lt(now()->startOfDay());
    }

    public function estadoVisible(): string
    {
        return $this->vencida() ? 'vencida' : $this->estado;
    }

    public function destinatario(): string
    {
        return $this->cliente?->razon_social ?? (string) $this->contacto_nombre;
    }
}
