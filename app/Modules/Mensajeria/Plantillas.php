<?php

namespace App\Modules\Mensajeria;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Integraciones\Whatsapp\Whatsapp;
use App\Modules\Mensajeria\Models\Mensaje;
use App\Modules\Mensajeria\Models\Plantilla;
use Illuminate\Validation\ValidationException;

/**
 * Plantillas de WhatsApp: se crean en borrador, se envían a revisión de Meta y solo
 * una aprobada se puede usar. Editar una rechazada la devuelve a borrador; una
 * aprobada ya no se edita (Meta exige crear otra) y una usada no se elimina.
 */
class Plantillas
{
    public function __construct(private Whatsapp $wa, private Auditoria $auditoria) {}

    public function guardar(array $d, User $user, ?Plantilla $p = null): Plantilla
    {
        if ($p && in_array($p->estado, ['aprobada', 'pendiente'], true)) {
            throw ValidationException::withMessages(['plantilla' => $p->estado === 'aprobada' ? 'Una plantilla aprobada no se edita: crea una nueva versión.' : 'Está en revisión de Meta.']);
        }
        $this->validarVariables($d['cuerpo']);
        $antes = $p?->only(['nombre', 'idioma', 'categoria', 'cuerpo']);
        $p ??= new Plantilla(['creado_por' => $user->id]);
        $p->fill(['nombre' => $d['nombre'], 'idioma' => $d['idioma'], 'categoria' => $d['categoria'], 'cuerpo' => $d['cuerpo'], 'estado' => 'borrador', 'motivo_rechazo' => null, 'revisada_at' => null])->save();
        $this->auditoria->registrar($antes ? 'plantilla.editar' : 'plantilla.crear', $p, $antes, $p->only(['nombre', 'idioma', 'categoria', 'cuerpo']));

        return $p;
    }

    public function enviarARevision(Plantilla $p): Plantilla
    {
        if ($p->estado !== 'borrador') {
            throw ValidationException::withMessages(['plantilla' => 'Solo se envía a revisión una plantilla en borrador.']);
        }
        if (! Integracion::activa('whatsapp')) {
            throw ValidationException::withMessages(['plantilla' => 'Activa la integración de WhatsApp para enviar plantillas a Meta.']);
        }
        $this->wa->solicitarPlantilla($p->nombre, $p->idioma, $p->categoria, $p->cuerpo);
        $p->update(['estado' => 'pendiente']);
        $this->auditoria->registrar('plantilla.revision', $p, null, ['nombre' => $p->nombre]);

        return $p;
    }

    /** Consulta a Meta el estado de las plantillas en revisión. @return int cuántas cambiaron */
    public function actualizarPendientes(): int
    {
        $n = 0;
        foreach (Plantilla::where('estado', 'pendiente')->get() as $p) {
            $r = $this->wa->estadoPlantilla($p->nombre, $p->idioma, $p->categoria, $p->cuerpo);
            if ($r['estado'] !== 'pendiente') {
                $p->update(['estado' => $r['estado'], 'motivo_rechazo' => $r['motivo'] ? mb_substr($r['motivo'], 0, 200) : null, 'revisada_at' => now()]);
                $this->auditoria->registrar('plantilla.'.$r['estado'], $p, null, ['motivo' => $r['motivo']]);
                $n++;
            }
        }

        return $n;
    }

    public function eliminar(Plantilla $p): void
    {
        if (Mensaje::where('plantilla_id', $p->id)->exists()) {
            throw ValidationException::withMessages(['plantilla' => 'Ya se envió con esta plantilla: no se elimina para conservar el historial.']);
        }
        $this->auditoria->registrar('plantilla.eliminar', $p, $p->only(['nombre', 'idioma', 'estado']), null);
        $p->delete();
    }

    /** Las variables deben ser {{1}}, {{2}}… consecutivas desde 1. */
    private function validarVariables(string $cuerpo): void
    {
        preg_match_all('/\{\{(\d+)\}\}/', $cuerpo, $m);
        $nums = array_values(array_unique(array_map('intval', $m[1])));
        sort($nums);
        if ($nums && $nums !== range(1, count($nums))) {
            throw ValidationException::withMessages(['cuerpo' => 'Las variables deben ser consecutivas desde {{1}}.']);
        }
        $resto = preg_replace('/\{\{\d+\}\}/', '', $cuerpo);
        if (str_contains($resto, '{{') || str_contains($resto, '}}')) {
            throw ValidationException::withMessages(['cuerpo' => 'Hay llaves sueltas: usa variables como {{1}}.']);
        }
    }
}
