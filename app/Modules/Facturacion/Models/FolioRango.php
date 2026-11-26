<?php

namespace App\Modules\Facturacion\Models;

use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;

class FolioRango extends Model
{
    use Auditable, PerteneceAEmpresa;

    protected $table = 'folio_rangos';

    protected $fillable = ['tipo_dte', 'desde', 'hasta', 'siguiente', 'caf_referencia', 'vence', 'activo'];

    protected function casts(): array
    {
        return ['tipo_dte' => 'integer', 'desde' => 'integer', 'hasta' => 'integer', 'siguiente' => 'integer', 'vence' => 'date', 'activo' => 'boolean'];
    }

    public function restantes(): int
    {
        return max(0, $this->hasta - $this->siguiente + 1);
    }
}
