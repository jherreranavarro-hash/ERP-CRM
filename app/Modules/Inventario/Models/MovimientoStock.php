<?php

namespace App\Modules\Inventario\Models;

use App\Models\User;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Movimiento de kardex: solo inserción; el saldo y el costo los fija el trigger. */
class MovimientoStock extends Model
{
    use PerteneceAEmpresa;

    public const UPDATED_AT = null;

    public const TIPOS = ['recepcion' => 'Recepción', 'venta' => 'Venta', 'anulacion_venta' => 'Anulación de venta', 'merma' => 'Merma', 'ajuste' => 'Ajuste'];

    protected $table = 'movimientos_stock';

    protected $fillable = ['producto_id', 'local_id', 'tipo', 'cantidad', 'costo_unitario', 'origen_tipo', 'origen_id', 'referencia', 'usuario_id'];

    protected function casts(): array
    {
        return ['cantidad' => 'decimal:6', 'costo_unitario' => 'decimal:6', 'saldo_cantidad' => 'decimal:6', 'saldo_costo_promedio' => 'decimal:6', 'created_at' => 'datetime'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
