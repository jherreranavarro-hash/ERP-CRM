<?php

namespace App\Modules\ContactCenter;

use App\Models\User;
use App\Modules\ContactCenter\Eventos\ContactCenterActualizado;
use App\Modules\ContactCenter\Models\Agente;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Cola;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\ListaNegra;
use App\Modules\ContactCenter\Models\Llamada;
use App\Modules\ContactCenter\Models\NumeroEntrante;
use App\Modules\ContactCenter\Telefonia\Pbx;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Operación del contact center: estado de los agentes, llamadas salientes y
 * entrantes, eventos de la central y tipificación. Cada cambio se empuja al
 * wallboard por WebSocket; si el servidor de tiempo real no responde, la
 * operación sigue y solo se registra el aviso.
 */
class CentroContacto
{
    public function __construct(private Pbx $pbx, private Tenancy $tenancy, private Auditoria $auditoria) {}

    public function cambiarEstado(Agente $agente, string $estado, ?string $motivo = null): Agente
    {
        if (! in_array($estado, ['disponible', 'pausa', 'desconectado'], true)) {
            throw ValidationException::withMessages(['estado' => 'Estado no válido.']);
        }
        if ($estado === 'pausa' && ! array_key_exists((string) $motivo, Agente::PAUSAS)) {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo de la pausa.']);
        }
        if (Llamada::where('agente_id', $agente->id)->whereIn('estado', Llamada::ACTIVAS)->exists()) {
            throw ValidationException::withMessages(['estado' => 'Termina la llamada en curso antes de cambiar de estado.']);
        }
        $agente->forceFill(['estado' => $estado, 'motivo_pausa' => $estado === 'pausa' ? $motivo : null, 'estado_desde' => now()])->save();
        $this->emitir('agente', $this->filaAgente($agente));
        if ($estado === 'disponible' && $agente->cola_id) {
            $this->distribuir(Cola::find($agente->cola_id));
        }

        return $agente;
    }

    public function llamar(Agente $agente, Lead $lead, User $user): Llamada
    {
        // La lista negra se revisa fuera de la transacción: el prospecto queda marcado aunque la llamada no se haga.
        if (ListaNegra::bloqueado($lead->telefono)) {
            if ($lead->agente_id === $agente->id && in_array($lead->estado, Lead::ABIERTOS, true)) {
                $lead->forceFill(['estado' => 'no_contactar'])->save();
            }
            throw ValidationException::withMessages(['lead' => 'El número está en la lista negra: no se llama.']);
        }

        return DB::transaction(function () use ($agente, $lead, $user) {
            $a = Agente::whereKey($agente->id)->lockForUpdate()->firstOrFail();
            $l = Lead::with('campana')->whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($a->user_id !== $user->id) {
                throw ValidationException::withMessages(['agente' => 'Solo el propio agente marca desde su consola.']);
            }
            if ($a->estado !== 'disponible') {
                throw ValidationException::withMessages(['agente' => 'Ponte disponible para llamar.']);
            }
            if ($l->agente_id !== $a->id) {
                throw ValidationException::withMessages(['lead' => 'Este prospecto está asignado a otro agente.']);
            }
            if (! in_array($l->estado, Lead::ABIERTOS, true)) {
                throw ValidationException::withMessages(['lead' => 'El prospecto ya está cerrado.']);
            }
            $c = $l->campana;
            if ($c->estado !== 'activa') {
                throw ValidationException::withMessages(['lead' => 'La campaña no está activa.']);
            }
            if (! $c->enHorario()) {
                throw ValidationException::withMessages(['lead' => "Fuera del horario de la campaña ({$c->horario['desde']}–{$c->horario['hasta']})."]);
            }
            if ($l->intentos >= $c->max_intentos) {
                throw ValidationException::withMessages(['lead' => "Se alcanzó el máximo de {$c->max_intentos} intentos."]);
            }
            $llamada = Llamada::create(['campana_id' => $c->id, 'lead_id' => $l->id, 'agente_id' => $a->id, 'direccion' => 'saliente', 'numero' => $l->telefono,
                'estado' => 'marcando', 'inicio' => now()]);
            $llamada->forceFill(['pbx_id' => $this->pbx->originar($a->extension, $l->telefono, 'llamada-'.$llamada->id)])->save();
            $l->forceFill(['intentos' => $l->intentos + 1, 'ultimo_contacto_at' => now()])->save();
            $a->forceFill(['estado' => 'en_llamada', 'estado_desde' => now()])->save();
            DB::afterCommit(function () use ($a, $llamada) {
                $this->emitir('agente', $this->filaAgente($a));
                $this->emitir('llamada', $this->filaLlamada($llamada));
            });

            return $llamada;
        });
    }

    /** Llamada que entra por un número: va a la cola de su destino y se reparte a un agente libre. */
    public function entrante(string $numeroDestino, string $origen, string $pbxId): Llamada
    {
        $n = NumeroEntrante::where('numero', $numeroDestino)->where('activo', true)->first()
            ?? throw ValidationException::withMessages(['numero' => 'Número entrante no configurado.']);
        $colaId = $n->destino_tipo === 'cola' ? $n->destino_id : $this->colaDesdeIvr($n->destino_id);
        $campana = Campana::where('tipo', 'entrante')->where('estado', 'activa')->where('cola_id', $colaId)->value('id');
        $llamada = Llamada::firstOrCreate(['pbx_id' => $pbxId], ['direccion' => 'entrante', 'numero' => $origen, 'estado' => 'en_cola', 'cola_id' => $colaId, 'campana_id' => $campana, 'inicio' => now()]);
        $this->emitir('llamada', $this->filaLlamada($llamada));
        if ($colaId) {
            $this->distribuir(Cola::find($colaId));
        }

        return $llamada->fresh();
    }

    /** Reparte las llamadas en espera de una cola entre sus agentes disponibles según la estrategia. */
    public function distribuir(?Cola $cola): void
    {
        if (! $cola || ! $cola->activo) {
            return;
        }
        DB::transaction(function () use ($cola) {
            foreach (Llamada::where('cola_id', $cola->id)->where('estado', 'en_cola')->orderBy('inicio')->lockForUpdate()->get() as $ll) {
                $libres = Agente::where('cola_id', $cola->id)->where('activo', true)->where('estado', 'disponible')->lockForUpdate()->get();
                if ($libres->isEmpty()) {
                    return;
                }
                $hoy = Llamada::whereIn('agente_id', $libres->pluck('id'))->where('inicio', '>=', today())->groupBy('agente_id')->selectRaw('agente_id, count(*) as n')->pluck('n', 'agente_id');
                $agente = match ($cola->estrategia) {
                    'menor_carga' => $libres->sortBy(fn ($a) => [$hoy[$a->id] ?? 0, $a->id])->first(),
                    'round_robin' => $libres->sortBy('id')->first(fn ($a) => $a->id > (int) Llamada::where('cola_id', $cola->id)->whereNotNull('agente_id')->latest('id')->value('agente_id')) ?? $libres->sortBy('id')->first(),
                    default => $libres->sortBy(fn ($a) => $a->estado_desde?->timestamp ?? 0)->first(),
                };
                $ll->forceFill(['agente_id' => $agente->id, 'estado' => 'marcando', 'espera_seg' => (int) $ll->inicio->diffInSeconds(now())])->save();
                $agente->forceFill(['estado' => 'en_llamada', 'estado_desde' => now()])->save();
                DB::afterCommit(function () use ($agente, $ll) {
                    $this->emitir('agente', $this->filaAgente($agente));
                    $this->emitir('llamada', $this->filaLlamada($ll));
                });
            }
        });
    }

    /**
     * Evento de la central: contestada, terminada, no_contesta, ocupado, fallida o grabacion.
     * Es idempotente: un evento repetido o fuera de orden no retrocede la llamada.
     */
    public function evento(string $pbxId, string $tipo, array $datos = []): ?Llamada
    {
        return DB::transaction(function () use ($pbxId, $tipo, $datos) {
            $ll = Llamada::where('pbx_id', $pbxId)->lockForUpdate()->first();
            if (! $ll) {
                return null;
            }
            $activa = in_array($ll->estado, Llamada::ACTIVAS, true);
            switch ($tipo) {
                case 'contestada':
                    if (in_array($ll->estado, ['marcando', 'en_cola'], true)) {
                        $ll->forceFill(['estado' => 'en_curso', 'contestada_at' => now()])->save();
                    }
                    break;
                case 'terminada':
                case 'no_contesta':
                case 'ocupado':
                case 'fallida':
                    if ($activa) {
                        $final = $tipo === 'terminada' ? ($ll->contestada_at ? 'terminada' : 'no_contesta') : $tipo;
                        $ll->forceFill(['estado' => $final, 'fin' => now(), 'duracion_seg' => $ll->contestada_at ? (int) $ll->contestada_at->diffInSeconds(now()) : 0,
                            'grabacion_ref' => $datos['grabacion'] ?? $ll->grabacion_ref ?? ($ll->contestada_at && $this->pbx->simulada() ? 'sim/'.$ll->pbx_id.'.wav' : null)])->save();
                        $this->liberar($ll);
                    }
                    break;
                case 'grabacion':
                    if (! empty($datos['grabacion'])) {
                        $ll->forceFill(['grabacion_ref' => mb_substr((string) $datos['grabacion'], 0, 200)])->save();
                    }
                    break;
                default:
                    throw ValidationException::withMessages(['tipo' => 'Evento desconocido.']);
            }
            DB::afterCommit(fn () => $this->emitir('llamada', $this->filaLlamada($ll)));

            return $ll;
        });
    }

    /** El agente corta la llamada desde su consola. */
    public function colgar(Llamada $llamada, User $user): Llamada
    {
        $ll = Llamada::with('agente')->findOrFail($llamada->id);
        if ($ll->agente?->user_id !== $user->id) {
            throw ValidationException::withMessages(['llamada' => 'Solo el agente de la llamada puede colgarla.']);
        }
        if ($ll->pbx_id) {
            $this->pbx->colgar($ll->pbx_id);
        }

        return $this->evento($ll->pbx_id, 'terminada') ?? $ll;
    }

    /** Resultado de la gestión: mueve el prospecto en el embudo. */
    public function tipificar(Llamada $llamada, array $d, User $user): Llamada
    {
        return DB::transaction(function () use ($llamada, $d, $user) {
            $ll = Llamada::with('agente', 'lead')->whereKey($llamada->id)->lockForUpdate()->firstOrFail();
            if ($ll->agente?->user_id !== $user->id) {
                throw ValidationException::withMessages(['llamada' => 'Solo el agente de la llamada la tipifica.']);
            }
            if (in_array($ll->estado, Llamada::ACTIVAS, true)) {
                throw ValidationException::withMessages(['llamada' => 'Tipifica cuando la llamada termine.']);
            }
            if (! array_key_exists($d['resultado'], Llamada::RESULTADOS)) {
                throw ValidationException::withMessages(['resultado' => 'Resultado no válido.']);
            }
            if ($d['resultado'] === 'volver_a_llamar' && empty($d['proximo_contacto'])) {
                throw ValidationException::withMessages(['proximo_contacto' => 'Indica cuándo volver a llamar.']);
            }
            $ll->forceFill(['resultado' => $d['resultado'], 'notas' => isset($d['notas']) ? mb_substr(trim($d['notas']), 0, 1000) : null])->save();
            if ($l = $ll->lead) {
                $estado = match ($d['resultado']) {
                    'interesado', 'cotizacion' => 'interesado',
                    'venta' => 'ganado',
                    'no_interesado', 'numero_erroneo' => 'perdido',
                    'no_contactar' => 'no_contactar',
                    default => $l->estado === 'asignado' || $l->estado === 'nuevo' ? 'contactado' : $l->estado,
                };
                $l->forceFill(['estado' => $estado, 'proximo_contacto_at' => $d['resultado'] === 'volver_a_llamar' ? $d['proximo_contacto'] : null])->save();
                if ($d['resultado'] === 'no_contactar') {
                    ListaNegra::firstOrCreate(['numero' => $l->telefono], ['motivo' => 'Pidió no ser contactado (llamada '.$ll->id.')', 'creado_por' => $user->id]);
                }
            }
            $this->auditoria->registrar('llamada.tipificar', $ll, null, ['resultado' => $d['resultado']]);

            return $ll;
        });
    }

    /** Foto del contact center para el wallboard. */
    public function tablero(): array
    {
        $agentes = Agente::with('user:id,name', 'cola:id,nombre')->where('activo', true)->orderBy('extension')->get();
        $activas = Llamada::with('campana:id,nombre')->whereIn('estado', Llamada::ACTIVAS)->get();
        $hoy = Llamada::where('inicio', '>=', today());
        $entrantes = (clone $hoy)->where('direccion', 'entrante');
        $atendidas = (clone $entrantes)->whereNotNull('contestada_at');
        $total = (clone $entrantes)->whereNotIn('estado', ['en_cola', 'marcando'])->count();

        return [
            'agentes' => $agentes->map(fn ($a) => $this->filaAgente($a, $activas->firstWhere('agente_id', $a->id)))->values()->all(),
            'llamadas' => $activas->map(fn ($l) => $this->filaLlamada($l))->values()->all(),
            'colas' => Cola::where('activo', true)->orderBy('nombre')->get()->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombre, 'nivel_servicio_seg' => $c->nivel_servicio_seg])->all(),
            'hoy' => [
                'llamadas' => (clone $hoy)->count(),
                'salientes' => (clone $hoy)->where('direccion', 'saliente')->count(),
                'contactadas' => (clone $hoy)->whereNotNull('contestada_at')->count(),
                'entrantes' => (clone $entrantes)->count(),
                'abandonadas' => (clone $entrantes)->whereNull('contestada_at')->whereNotIn('estado', Llamada::ACTIVAS)->count(),
                'nivel_servicio' => $total ? round((clone $atendidas)->where('espera_seg', '<=', 20)->count() / $total * 100, 1) : null,
                'tmo' => (int) round((float) (clone $hoy)->whereNotNull('contestada_at')->where('duracion_seg', '>', 0)->avg('duracion_seg')),
            ],
        ];
    }

    public function filaAgente(Agente $a, ?Llamada $actual = null): array
    {
        $a->loadMissing('user:id,name', 'cola:id,nombre');
        $actual ??= Llamada::where('agente_id', $a->id)->whereIn('estado', Llamada::ACTIVAS)->latest('id')->first();

        return ['id' => $a->id, 'nombre' => $a->nombre(), 'extension' => $a->extension, 'estado' => $a->estado, 'motivo' => $a->motivo_pausa ? Agente::PAUSAS[$a->motivo_pausa] ?? $a->motivo_pausa : null,
            'desde' => $a->estado_desde?->toIso8601String(), 'cola' => $a->cola?->nombre, 'llamada_id' => $actual?->id];
    }

    public function filaLlamada(Llamada $l): array
    {
        $l->loadMissing('campana:id,nombre');

        return ['id' => $l->id, 'agente_id' => $l->agente_id, 'cola_id' => $l->cola_id, 'direccion' => $l->direccion, 'numero' => self::enmascarar($l->numero), 'estado' => $l->estado,
            'campana' => $l->campana?->nombre, 'inicio' => $l->inicio?->toIso8601String(), 'contestada' => $l->contestada_at?->toIso8601String()];
    }

    /** En el wallboard el número va enmascarado: es una pantalla a la vista de todos. */
    public static function enmascarar(string $numero): string
    {
        $d = preg_replace('/\D/', '', $numero);

        return strlen($d) > 4 ? str_repeat('•', strlen($d) - 4).substr($d, -4) : $d;
    }

    private function liberar(Llamada $ll): void
    {
        if (! $ll->agente_id) {
            return;
        }
        $a = Agente::whereKey($ll->agente_id)->lockForUpdate()->first();
        if ($a && $a->estado === 'en_llamada') {
            $a->forceFill(['estado' => 'disponible', 'estado_desde' => now()])->save();
            DB::afterCommit(function () use ($a) {
                $this->emitir('agente', $this->filaAgente($a));
                if ($a->cola_id) {
                    $this->distribuir(Cola::find($a->cola_id));
                }
            });
        }
    }

    private function colaDesdeIvr(int $ivrId): ?int
    {
        $ivr = \App\Modules\ContactCenter\Models\Ivr::find($ivrId);
        $op = collect($ivr?->opciones ?? [])->firstWhere('accion', 'cola');

        return $op ? (int) $op['destino'] : null;
    }

    private function emitir(string $tipo, array $datos): void
    {
        try {
            broadcast(new ContactCenterActualizado((int) $this->tenancy->id(), $tipo, $datos));
        } catch (\Throwable $e) {
            Log::warning('Tiempo real no disponible: '.$e->getMessage());
        }
    }
}
