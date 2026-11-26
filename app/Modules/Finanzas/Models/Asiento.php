<?php

namespace App\Modules\Finanzas\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Asiento contable de partida doble. Inmutable: se corrige con un reverso. */
class Asiento extends Model
{
    use PerteneceAEmpresa;

    public const UPDATED_AT = null;

    public const TIPOS = ['manual' => 'Manual', 'automatico' => 'Automático', 'reverso' => 'Reverso'];

    protected $table = 'asientos';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['numero' => 'integer', 'fecha' => 'date', 'total' => 'integer', 'origen_id' => 'integer'];
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(AsientoLinea::class)->orderBy('id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function revierte(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revierte_id');
    }

    public function reverso(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'revierte_id');
    }
}
