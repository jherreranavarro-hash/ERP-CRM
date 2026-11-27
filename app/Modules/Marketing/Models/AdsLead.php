<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

/** Prospecto recibido de un formulario de Meta y qué se hizo con él. */
class AdsLead extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'ads_leads';

    protected $guarded = ['id', 'empresa_id'];

    public const RESULTADOS = ['importado' => 'Importado', 'duplicado' => 'Ya existía', 'bloqueado' => 'Lista negra', 'sin_destino' => 'Sin campaña de destino', 'invalido' => 'Datos no válidos'];

    protected function casts(): array
    {
        return ['recibido_at' => 'datetime'];
    }
}
