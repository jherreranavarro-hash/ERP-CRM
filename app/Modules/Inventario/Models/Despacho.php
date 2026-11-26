<?php

namespace App\Modules\Inventario\Models;

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
 * Despacho de un documento emitido. Máquina de estados explícita (la misma
 * tabla de transiciones se valida en un trigger de la base de datos).
 */
class Despacho extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['por_aprobar' => 'Por aprobar', 'programado' => 'Programado', 'en_ruta' => 'En ruta', 'entregado' => 'Entregado', 'fallido' => 'Entrega fallida', 'anulado' => 'Anulado'];

    /** estado => estados a los que puede pasar. */
    public const TRANSICIONES = [
        'por_aprobar' => ['programado', 'anulado'],
        'programado' => ['en_ruta', 'anulado'],
        'en_ruta' => ['entregado', 'fallido'],
        'fallido' => ['programado', 'anulado'],
        'entregado' => [],
        'anulado' => [],
    ];

    protected $table = 'despachos';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'cobro' => 'integer', 'intentos' => 'integer', 'motivos' => 'array', 'fecha_programada' => 'date', 'entregado_at' => 'datetime'];
    }

    public function puedePasarA(string $estado): bool
    {
        return in_array($estado, self::TRANSICIONES[$this->estado] ?? [], true);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(DespachoEvento::class)->orderBy('id');
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
