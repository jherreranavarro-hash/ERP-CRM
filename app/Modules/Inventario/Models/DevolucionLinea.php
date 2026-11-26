<?php

namespace App\Modules\Inventario\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use App\Modules\Facturacion\Models\DocumentoLinea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DevolucionLinea extends Model
{
    use PerteneceAEmpresa;

    public $timestamps = false;

    protected $table = 'devolucion_lineas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['reingresa' => 'boolean'];
    }

    public function lineaOriginal(): BelongsTo
    {
        return $this->belongsTo(DocumentoLinea::class, 'documento_linea_id');
    }
}
