<?php

namespace App\Modules\Core\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Empresa extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'empresas';

    protected $fillable = [
        'rut', 'razon_social', 'nombre_fantasia', 'giro', 'acteco', 'direccion', 'comuna',
        'ciudad', 'telefono', 'email', 'resolucion_sii_numero', 'resolucion_sii_fecha', 'activo',
    ];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'resolucion_sii_fecha' => 'date'];
    }

    public function locales(): HasMany
    {
        return $this->hasMany(Local::class);
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'usuario_empresa')->withPivot(['rol_id', 'local_id', 'activo'])->withTimestamps();
    }

    public function nombreCorto(): string
    {
        return $this->nombre_fantasia ?: $this->razon_social;
    }

    protected static function newFactory()
    {
        return \Database\Factories\EmpresaFactory::new();
    }
}
