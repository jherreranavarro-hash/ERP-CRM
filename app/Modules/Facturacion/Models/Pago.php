<?php

namespace App\Modules\Facturacion\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pago extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const METODOS = ['efectivo' => 'Efectivo', 'debito' => 'Débito', 'credito' => 'Crédito', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'nota_credito' => 'Nota de crédito'];

    protected $fillable = ['documento_id', 'turno_id', 'monto', 'metodo', 'fecha', 'referencia', 'conciliado', 'registrado_por'];

    protected function casts(): array
    {
        return ['monto' => 'integer', 'fecha' => 'date', 'conciliado' => 'boolean'];
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Caja\Models\TurnoCaja::class, 'turno_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
