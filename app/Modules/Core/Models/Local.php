<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Local extends Model
{
    use Auditable, PerteneceAEmpresa, SoftDeletes;

    protected $table = 'locales';

    protected $fillable = ['codigo', 'nombre', 'direccion', 'comuna', 'telefono', 'es_bodega', 'activo'];

    protected function casts(): array
    {
        return ['es_bodega' => 'boolean', 'activo' => 'boolean'];
    }
}
