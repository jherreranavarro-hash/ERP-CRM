<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParametroCambio extends Model
{
    protected $table = 'parametro_cambios';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valor' => 'json',
            'vigente_desde' => 'datetime',
            'resuelto_at' => 'datetime',
            'auto_aprobado' => 'boolean',
        ];
    }

    public function proponente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'propuesto_por')->withTrashed();
    }

    public function resolutor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por')->withTrashed();
    }

    public function alcance(): string
    {
        return $this->local_id ? 'local' : ($this->empresa_id ? 'empresa' : 'global');
    }
}
