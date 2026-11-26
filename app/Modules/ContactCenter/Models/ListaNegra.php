<?php

namespace App\Modules\ContactCenter\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Número que no se debe llamar (por solicitud del titular o por norma). */
class ListaNegra extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'lista_negra';

    protected $guarded = ['id', 'empresa_id'];

    protected function casts(): array
    {
        return ['hasta' => 'date'];
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /** ¿El número está bloqueado hoy? Compara solo dígitos (últimos 9). */
    public static function bloqueado(string $numero): bool
    {
        $n = substr(preg_replace('/\D/', '', $numero), -9);

        return $n !== '' && static::whereRaw("right(regexp_replace(numero, '\\D', '', 'g'), 9) = ?", [$n])
            ->where(fn ($q) => $q->whereNull('hasta')->orWhere('hasta', '>=', now()->toDateString()))->exists();
    }
}
