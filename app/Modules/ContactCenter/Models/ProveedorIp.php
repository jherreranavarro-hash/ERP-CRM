<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Proveedor de telefonía IP (PBX). El secreto se guarda cifrado y nunca se devuelve. */
class ProveedorIp extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const TIPOS = ['asterisk' => 'Asterisk', 'freeswitch' => 'FreeSWITCH', 'vicidial' => 'VICIdial'];

    public const PROTOCOLOS = ['ami' => 'AMI', 'ari' => 'ARI', 'esl' => 'ESL'];

    protected $table = 'proveedores_ip';

    protected $guarded = ['id', 'empresa_id', 'secreto_cifrado'];

    protected $hidden = ['secreto_cifrado'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'puerto' => 'integer', 'secreto_rotado_at' => 'datetime', 'salud_at' => 'datetime'];
    }
}
