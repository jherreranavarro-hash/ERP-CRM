<?php

namespace App\Modules\Comercial\Models;

use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use App\Modules\Facturacion\Models\Documento;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use Auditable, PerteneceAEmpresa, SoftDeletes;

    protected $fillable = ['rut', 'razon_social', 'giro', 'direccion', 'comuna', 'ciudad', 'email', 'telefono', 'lista_precio', 'tiene_credito', 'cupo_credito', 'plazo_dias', 'activo'];

    protected function casts(): array
    {
        return ['tiene_credito' => 'boolean', 'activo' => 'boolean', 'cupo_credito' => 'integer', 'plazo_dias' => 'integer'];
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(Documento::class);
    }

    /** Deuda vigente: saldo de documentos emitidos a crédito. */
    public function deuda(): int
    {
        return (int) $this->documentos()->where('estado', 'emitido')->where('forma_pago', 'credito')->sum('saldo');
    }

    public function receptorCompleto(): bool
    {
        return filled($this->rut) && filled($this->razon_social) && filled($this->giro) && filled($this->direccion) && filled($this->comuna);
    }
}
