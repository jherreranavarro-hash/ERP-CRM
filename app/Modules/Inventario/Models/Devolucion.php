<?php

namespace App\Modules\Inventario\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use App\Modules\Facturacion\Models\Documento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Devolución de una venta: al aprobarse genera la nota de crédito y reingresa el stock. */
class Devolucion extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['solicitada' => 'Por aprobar', 'aprobada' => 'Aprobada', 'rechazada' => 'Rechazada'];

    public const MOTIVOS = ['falla' => 'Producto con falla', 'error_despacho' => 'Error de despacho', 'arrepentimiento' => 'Cliente se arrepintió', 'otro' => 'Otro'];

    protected $table = 'devoluciones';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'monto_estimado' => 'integer'];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(DevolucionLinea::class)->orderBy('id');
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function notaCredito(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'nota_credito_id');
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
