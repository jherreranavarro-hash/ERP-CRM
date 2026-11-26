<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Membresía: qué rol tiene un usuario en una empresa (y opcionalmente en un local). */
class UsuarioEmpresa extends Model
{
    use Auditable;

    protected $table = 'usuario_empresa';

    protected $fillable = ['user_id', 'empresa_id', 'rol_id', 'local_id', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(Local::class)->withoutGlobalScopes();
    }
}
