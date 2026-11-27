<?php

namespace App\Modules\Mensajeria\Models;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversacion extends Model
{
    use PerteneceAEmpresa;

    protected $table = 'wa_conversaciones';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['no_leidos' => 'integer', 'ultimo_mensaje_at' => 'datetime', 'ventana_hasta' => 'datetime'];
    }

    public function enVentana(): bool
    {
        return $this->ventana_hasta !== null && $this->ventana_hasta->isFuture();
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(Mensaje::class, 'conversacion_id')->orderBy('id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
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
