<?php

namespace App\Modules\RRHH\Models;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Documento adjunto a la ficha, guardado cifrado en reposo. */
class EmpleadoDocumento extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'empleado_documentos';

    protected $guarded = ['id', 'empresa_id'];

    public const TIPOS = ['contrato' => 'Contrato o anexo', 'cedula' => 'Cédula de identidad', 'certificado' => 'Certificado', 'licencia' => 'Licencia médica', 'otro' => 'Otro'];

    protected $hidden = ['ruta'];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function subidor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
