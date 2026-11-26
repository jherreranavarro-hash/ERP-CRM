<?php

namespace App\Modules\ContactCenter;

use App\Models\User;
use App\Modules\ContactCenter\Models\AuditoriaCalidad;
use App\Modules\ContactCenter\Models\CriterioCalidad;
use App\Modules\ContactCenter\Models\GrabacionAcceso;
use App\Modules\ContactCenter\Models\Llamada;
use App\Modules\ContactCenter\Telefonia\Pbx;
use App\Modules\Core\Auditoria\Auditoria;
use Illuminate\Validation\ValidationException;

/**
 * Calidad y grabaciones: evaluación de llamadas con checklist ponderado (un
 * criterio crítico incumplido deja la nota en 0) y acceso a grabaciones con
 * permiso específico, motivo y registro inmutable de cada acceso.
 */
class Calidad
{
    public function __construct(private Pbx $pbx, private Auditoria $auditoria) {}

    /** @param array<int,bool> $cumple criterio_id => cumple */
    public function evaluar(Llamada $llamada, array $cumple, ?string $comentario, User $auditor): AuditoriaCalidad
    {
        $ll = Llamada::with('agente')->findOrFail($llamada->id);
        if (! $ll->contestada_at || in_array($ll->estado, Llamada::ACTIVAS, true)) {
            throw ValidationException::withMessages(['llamada' => 'Solo se evalúa una llamada contestada y terminada.']);
        }
        if ($ll->agente?->user_id === $auditor->id) {
            throw ValidationException::withMessages(['llamada' => 'Nadie evalúa sus propias llamadas.']);
        }
        if (AuditoriaCalidad::where('llamada_id', $ll->id)->exists()) {
            throw ValidationException::withMessages(['llamada' => 'La llamada ya fue evaluada.']);
        }
        $criterios = CriterioCalidad::where('activo', true)->orderBy('orden')->orderBy('id')->get();
        if ($criterios->isEmpty()) {
            throw ValidationException::withMessages(['llamada' => 'Define primero los criterios del checklist.']);
        }
        $lista = $criterios->map(fn ($c) => ['criterio' => $c->nombre, 'peso' => $c->peso, 'critico' => $c->critico, 'cumple' => (bool) ($cumple[$c->id] ?? false)]);
        $total = $lista->sum('peso');
        $puntaje = $lista->contains(fn ($x) => $x['critico'] && ! $x['cumple']) ? 0 : round($lista->where('cumple', true)->sum('peso') / max(1, $total) * 100, 1);
        $a = AuditoriaCalidad::create(['llamada_id' => $ll->id, 'agente_id' => $ll->agente_id, 'auditor_id' => $auditor->id, 'checklist' => $lista->all(), 'puntaje' => $puntaje,
            'comentario' => $comentario ? mb_substr(trim($comentario), 0, 1000) : null]);

        return $a;
    }

    /** Devuelve el audio y deja registro. El permiso se exige antes, en la ruta. */
    public function grabacion(Llamada $llamada, User $user, string $motivo, string $accion, ?string $ip): string
    {
        if (! $llamada->grabacion_ref) {
            throw ValidationException::withMessages(['llamada' => 'La llamada no tiene grabación.']);
        }
        if (mb_strlen(trim($motivo)) < 5) {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo del acceso (mínimo 5 caracteres).']);
        }
        $audio = $this->pbx->grabacion($llamada->grabacion_ref) ?? throw ValidationException::withMessages(['llamada' => 'La grabación ya no está disponible en la central.']);
        GrabacionAcceso::create(['llamada_id' => $llamada->id, 'user_id' => $user->id, 'accion' => $accion, 'motivo' => mb_substr(trim($motivo), 0, 200), 'ip' => $ip]);
        $this->auditoria->registrar('grabacion.'.$accion, $llamada, null, ['motivo' => mb_substr(trim($motivo), 0, 200)]);

        return $audio;
    }
}
