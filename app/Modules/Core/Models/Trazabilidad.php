<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Auditoría inmutable: solo inserción (la base también lo exige con trigger y privilegios). */
class Trazabilidad extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'trazabilidad';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valor_anterior' => 'array', 'valor_nuevo' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La trazabilidad es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('La trazabilidad es de solo inserción.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
