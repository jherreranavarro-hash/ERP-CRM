<?php

namespace App\Modules\Facturacion\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentoReferencia extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'documento_referencias';

    /** Códigos SII de referencia para NC/ND. */
    public const CODIGOS = [1 => 'Anula documento', 2 => 'Corrige texto', 3 => 'Corrige montos'];

    protected $fillable = ['documento_referido_id', 'tipo_dte_ref', 'folio_ref', 'fecha_ref', 'codigo', 'razon'];

    protected function casts(): array
    {
        return ['fecha_ref' => 'date', 'codigo' => 'integer', 'tipo_dte_ref' => 'integer', 'folio_ref' => 'integer'];
    }

    public function referido(): BelongsTo
    {
        return $this->belongsTo(Documento::class, 'documento_referido_id');
    }
}
