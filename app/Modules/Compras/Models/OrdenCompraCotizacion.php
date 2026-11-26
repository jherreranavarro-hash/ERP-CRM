<?php

namespace App\Modules\Compras\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

class OrdenCompraCotizacion extends Model
{
    use PerteneceAEmpresa;

    public $timestamps = false;

    protected $table = 'orden_compra_cotizaciones';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['monto_neto' => 'integer', 'plazo_entrega_dias' => 'integer', 'elegida' => 'boolean'];
    }
}
