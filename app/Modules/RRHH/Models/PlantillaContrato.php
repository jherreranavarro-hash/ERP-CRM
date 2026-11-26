<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plantilla de contrato con variables {{nombre}}. */
class PlantillaContrato extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'plantillas_contrato';

    protected $guarded = ['id', 'empresa_id'];

    public const VARIABLES = ['empresa', 'rut_empresa', 'nombre', 'rut', 'domicilio', 'cargo', 'jornada', 'sueldo', 'sueldo_palabras', 'colacion', 'movilizacion', 'fecha_inicio', 'fecha_termino', 'tipo', 'local', 'fecha'];

    protected function casts(): array
    {
        return ['activa' => 'boolean'];
    }
}
