<?php

namespace App\Modules\Finanzas\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Boleta de honorarios recibida (libro de honorarios). */
class Honorario extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ESTADOS = ['por_pagar' => 'Por pagar', 'pagada' => 'Pagada', 'anulada' => 'Anulada'];

    protected $table = 'honorarios';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['folio' => 'integer', 'fecha' => 'date', 'fecha_pago' => 'date', 'bruto' => 'integer', 'retencion' => 'integer', 'liquido' => 'integer'];
    }

    public function centro(): BelongsTo
    {
        return $this->belongsTo(CentroCosto::class, 'centro_costo_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
