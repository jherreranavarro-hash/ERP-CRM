<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;

class ApiRegistro extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'api_registros';

    protected $guarded = ['id'];
}
