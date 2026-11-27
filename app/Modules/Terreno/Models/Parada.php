<?php

namespace App\Modules\Terreno\Models;

use App\Modules\Comercial\Models\Cliente;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Parada extends Model
{
    use PerteneceAEmpresa;

    public const ESTADOS = ['pendiente' => 'Pendiente', 'visitada' => 'Visitada', 'no_visitada' => 'No visitada'];

    public const RESULTADOS = ['interesado' => 'Interesado', 'cotizacion' => 'Pide cotización', 'venta' => 'Venta cerrada', 'seguimiento' => 'Seguimiento', 'sin_interes' => 'Sin interés',
        'ausente' => 'No estaba', 'reagendar' => 'Reagendar'];

    protected $table = 'recorrido_paradas';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['orden' => 'integer', 'lat' => 'float', 'lng' => 'float', 'visitada_at' => 'datetime'];
    }

    public function recorrido(): BelongsTo
    {
        return $this->belongsTo(Recorrido::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
