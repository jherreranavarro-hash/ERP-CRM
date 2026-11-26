<?php

namespace App\Modules\Facturacion\Models;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Documento tributario. Se escribe solo a través de App\Modules\Facturacion\Documentos;
 * una vez emitido la base de datos lo vuelve inmutable (trigger documentos_inmutables).
 */
class Documento extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const TIPOS = [
        33 => 'Factura electrónica',
        34 => 'Factura exenta',
        39 => 'Boleta electrónica',
        41 => 'Boleta exenta',
        52 => 'Guía de despacho',
        56 => 'Nota de débito',
        61 => 'Nota de crédito',
    ];

    public const CORTOS = [33 => 'Factura', 34 => 'Factura exenta', 39 => 'Boleta', 41 => 'Boleta exenta', 52 => 'Guía', 56 => 'ND', 61 => 'NC'];

    /** Tipos que exigen receptor completo (no admiten RUT genérico). */
    public const CON_RECEPTOR = [33, 34, 52, 56, 61];

    public const ESTADOS = ['borrador' => 'Borrador', 'por_aprobar' => 'Por aprobar', 'emitido' => 'Emitido', 'pagado' => 'Pagado', 'anulado' => 'Anulado'];

    public array $auditarExcluir = ['sii_respuesta', 'sii_intentos'];

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return [
            'tipo_dte' => 'integer', 'folio' => 'integer',
            'fecha_emision' => 'date', 'fecha_vencimiento' => 'date', 'emitido_at' => 'datetime',
            'descuento_global_monto' => 'integer', 'monto_neto' => 'integer', 'monto_exento' => 'integer',
            'monto_iva' => 'integer', 'monto_total' => 'integer', 'saldo' => 'integer', 'sii_intentos' => 'integer',
            'sii_respuesta' => 'array', 'aprobacion_motivos' => 'array', 'iva_incluido' => 'boolean',
        ];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(DocumentoLinea::class)->orderBy('orden');
    }

    public function referencias(): HasMany
    {
        return $this->hasMany(DocumentoReferencia::class);
    }

    /** Notas de crédito/débito emitidas que refieren a este documento. */
    public function referidoPor(): HasMany
    {
        return $this->hasMany(DocumentoReferencia::class, 'documento_referido_id');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function ordenVenta(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Ventas\Models\OrdenVenta::class);
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Ventas\Models\Cotizacion::class);
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function esBorrador(): bool
    {
        return in_array($this->estado, ['borrador', 'por_aprobar'], true);
    }

    public function esNota(): bool
    {
        return in_array($this->tipo_dte, [56, 61], true);
    }

    public function nombre(): string
    {
        return self::CORTOS[$this->tipo_dte].($this->folio ? " N° {$this->folio}" : ' (borrador)');
    }

    /** Lo que aún se puede acreditar con NC: total menos NC emitidas previas. */
    public function saldoAcreditable(): int
    {
        $nc = (int) Documento::query()
            ->where('tipo_dte', 61)->whereIn('estado', ['emitido', 'pagado', 'anulado'])
            ->whereHas('referencias', fn ($q) => $q->where('documento_referido_id', $this->id))
            ->sum('monto_total');

        return max(0, $this->monto_total - $nc);
    }
}
