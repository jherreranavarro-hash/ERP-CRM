<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitacion extends Model
{
    protected $table = 'invitaciones';

    protected $fillable = ['email', 'nombre', 'token_hash', 'empresa_id', 'rol_id', 'local_id', 'invitado_por', 'expira_at', 'aceptada_at', 'revocada_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expira_at' => 'datetime', 'aceptada_at' => 'datetime', 'revocada_at' => 'datetime'];
    }

    public function vigente(): bool
    {
        return ! $this->aceptada_at && ! $this->revocada_at && $this->expira_at->isFuture();
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class);
    }

    public function invitador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitado_por');
    }
}
