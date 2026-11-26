<?php

namespace App\Modules\Core\Models;

use Laravel\Sanctum\PersonalAccessToken;

/** Token Sanctum de integración: con empresa, vencimiento obligatorio, scopes e IP permitidas. */
class TokenAcceso extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'empresa_id', 'ips_permitidas', 'limite_por_minuto', 'creado_por', 'revocado_at'];

    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'ips_permitidas' => 'array',
        'revocado_at' => 'datetime',
    ];
}
