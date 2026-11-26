<?php

namespace App\Modules\Inventario\Models;

use App\Models\User;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Merma con motivo y responsable; sobre el umbral la aprueba otra persona antes de bajar stock. */
class Merma extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const MOTIVOS = ['rotura' => 'Rotura', 'vencimiento' => 'Vencimiento', 'robo' => 'Robo o pérdida', 'diferencia' => 'Diferencia de inventario', 'otro' => 'Otro'];

    protected $table = 'mermas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['costo_total' => 'integer', 'aprobado_at' => 'datetime'];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
