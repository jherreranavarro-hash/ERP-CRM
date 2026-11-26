<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Perfil extends Model
{
    protected $table = 'perfiles';

    protected $fillable = ['clave', 'nombre', 'descripcion'];

    public function roles(): HasMany
    {
        return $this->hasMany(Rol::class);
    }
}
