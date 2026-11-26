<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Menú de voz: bienvenida, opciones por tecla y horario. */
class Ivr extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const ACCIONES = ['cola' => 'Pasar a cola', 'ivr' => 'Otro menú', 'colgar' => 'Mensaje y colgar'];

    protected $table = 'ivrs';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['opciones' => 'array', 'horario' => 'array', 'activo' => 'boolean'];
    }
}
