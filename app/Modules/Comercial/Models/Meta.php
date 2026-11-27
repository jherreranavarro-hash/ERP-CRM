<?php

namespace App\Modules\Comercial\Models;

use App\Models\User;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Meta comercial de un mes para la empresa, un local o un vendedor. */
class Meta extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'metas';

    protected $guarded = ['id', 'empresa_id'];

    public const INDICADORES = ['venta_neta' => 'Venta neta', 'documentos' => 'Documentos emitidos', 'cotizaciones' => 'Cotizaciones'];

    public const ALCANCES = ['empresa' => 'Empresa', 'local' => 'Local', 'vendedor' => 'Vendedor'];

    protected function casts(): array
    {
        return ['periodo' => 'date'];
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
