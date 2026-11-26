<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Ficha de personal. */
class Empleado extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'empleados';

    protected $guarded = ['id', 'empresa_id'];

    public const ESTADOS = ['activo' => 'Activo', 'desvinculado' => 'Desvinculado'];

    public const AFPS = ['capital' => 'Capital', 'cuprum' => 'Cuprum', 'habitat' => 'Habitat', 'modelo' => 'Modelo', 'planvital' => 'PlanVital', 'provida' => 'ProVida', 'uno' => 'Uno'];

    protected function casts(): array
    {
        return ['fecha_nacimiento' => 'date', 'fecha_ingreso' => 'date', 'fecha_egreso' => 'date', 'plan_isapre_uf' => 'decimal:4', 'anios_previos' => 'integer'];
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombres.' '.$this->apellidos);
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(Contrato::class)->orderByDesc('fecha_inicio');
    }

    public function contratoVigente(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Contrato::class)->where('estado', 'vigente');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(EmpleadoDocumento::class)->latest('id');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function local(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Core\Models\Local::class);
    }

    public function centro(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Finanzas\Models\CentroCosto::class, 'centro_costo_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
