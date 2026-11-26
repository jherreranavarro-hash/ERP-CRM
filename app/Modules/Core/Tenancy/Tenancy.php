<?php

namespace App\Modules\Core\Tenancy;

use App\Modules\Core\Models\Empresa;
use Illuminate\Support\Facades\DB;

/**
 * Empresa activa de la petición. Tres capas (ver prompt, "Multi-tenant en tres capas"):
 *  1. El servidor la resuelve desde la membresía del usuario, nunca desde el cuerpo de la petición.
 *  2. Se fija en la conexión (set_config app.empresa_id) y el RLS de PostgreSQL filtra.
 *  3. Los modelos con PerteneceAEmpresa agregan además un global scope por empresa_id.
 */
class Tenancy
{
    private ?Empresa $empresa = null;

    public function establecer(Empresa $empresa): void
    {
        $this->empresa = $empresa;
        DB::select("select set_config('app.empresa_id', ?, false)", [(string) $empresa->id]);
    }

    public function limpiar(): void
    {
        $this->empresa = null;
        DB::select("select set_config('app.empresa_id', '', false)");
    }

    public function empresa(): ?Empresa
    {
        return $this->empresa;
    }

    public function id(): ?int
    {
        return $this->empresa?->id;
    }

    public function activa(): bool
    {
        return $this->empresa !== null;
    }

    /** Ejecuta $fn con otra empresa fijada y restaura la anterior. */
    public function como(Empresa $empresa, callable $fn): mixed
    {
        $anterior = $this->empresa;
        $this->establecer($empresa);
        try {
            return $fn();
        } finally {
            $anterior ? $this->establecer($anterior) : $this->limpiar();
        }
    }
}
