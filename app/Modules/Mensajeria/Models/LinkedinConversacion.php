<?php

namespace App\Modules\Mensajeria\Models;

use App\Models\User;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LinkedinConversacion extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'li_conversaciones';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['no_leidos' => 'integer', 'ultimo_mensaje_at' => 'datetime'];
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(LinkedinMensaje::class, 'conversacion_id')->orderBy('id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function asignado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_a');
    }
}
