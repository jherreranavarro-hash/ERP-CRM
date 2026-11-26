<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    protected $table = 'permisos';

    protected $fillable = ['clave', 'modulo', 'submodulo', 'accion', 'descripcion', 'restringido'];

    protected function casts(): array
    {
        return ['restringido' => 'boolean'];
    }
}
