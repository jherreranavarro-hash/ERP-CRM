<?php

namespace App\Modules\Terreno;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Integraciones\Mapas\Mapas;
use App\Modules\Terreno\Models\Parada;
use App\Modules\Terreno\Models\Recorrido;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lista de recorrido: el ejecutivo arma su ruta del día con clientes y
 * prospectos, el sistema la ordena para recorrer menos kilómetros y en
 * terreno marca cada visita con su resultado.
 *
 * Al proveedor de mapas solo viaja dirección y comuna. El orden se calcula
 * aquí: vecino más cercano y luego mejora 2-opt sobre distancias en línea
 * recta corregidas por un factor de calle.
 */
class Recorridos
{
    public const MAX_PARADAS = 25;

    private const FACTOR_CALLE = 1.35;

    private const KMH = 25;

    private const MIN_POR_VISITA = 20;

    public function __construct(private Mapas $mapas, private Auditoria $auditoria) {}

    /** @param  list<array{cliente_id?: int|null, lead_id?: int|null}>  $paradas */
    public function crear(User $ejecutivo, string $fecha, ?string $origen, ?string $comunaOrigen, array $paradas, User $por): Recorrido
    {
        if (Recorrido::where('user_id', $ejecutivo->id)->whereDate('fecha', $fecha)->exists()) {
            throw ValidationException::withMessages(['fecha' => 'Ese ejecutivo ya tiene un recorrido para ese día: agrégale paradas.']);
        }

        return DB::transaction(function () use ($ejecutivo, $fecha, $origen, $comunaOrigen, $paradas, $por) {
            [$lat, $lng] = $origen ? ($this->mapas->geocodificar($origen, $comunaOrigen) ?? [null, null]) : [null, null];
            $r = Recorrido::create(['user_id' => $ejecutivo->id, 'fecha' => $fecha, 'origen' => $origen ? trim($origen.($comunaOrigen ? ', '.$comunaOrigen : '')) : null,
                'origen_lat' => $lat, 'origen_lng' => $lng, 'creado_por' => $por->id]);
            $this->agregar($r, $paradas, $ejecutivo);
            $this->optimizar($r);
            $this->auditoria->registrar('recorrido.crear', $r, null, ['paradas' => count($paradas)]);

            return $r->fresh('paradas');
        });
    }

    /** @param  list<array{cliente_id?: int|null, lead_id?: int|null}>  $paradas */
    public function agregar(Recorrido $r, array $paradas, ?User $ejecutivo = null): void
    {
        if ($r->estado === 'cerrado') {
            throw ValidationException::withMessages(['recorrido' => 'El recorrido está cerrado.']);
        }
        $ejecutivo ??= User::findOrFail($r->user_id);
        $existentes = $r->paradas()->get(['cliente_id', 'lead_id']);
        if ($existentes->count() + count($paradas) > self::MAX_PARADAS) {
            throw ValidationException::withMessages(['paradas' => 'Un recorrido admite hasta '.self::MAX_PARADAS.' paradas.']);
        }
        $orden = (int) $r->paradas()->max('orden');
        foreach ($paradas as $i => $p) {
            $origen = $this->origenParada($p, $ejecutivo, $i);
            if ($existentes->contains(fn ($e) => ($origen['cliente_id'] && $e->cliente_id === $origen['cliente_id']) || ($origen['lead_id'] && $e->lead_id === $origen['lead_id']))) {
                continue;
            }
            [$lat, $lng] = $this->mapas->geocodificar($origen['direccion'], $origen['comuna']) ?? [null, null];
            $r->paradas()->create($origen + ['orden' => ++$orden, 'lat' => $lat, 'lng' => $lng]);
            $existentes->push((object) $origen);
        }
        if ($r->optimizado_at) {
            $r->forceFill(['optimizado_at' => null])->save();
        }
    }

    public function quitar(Parada $p): void
    {
        if ($p->estado !== 'pendiente') {
            throw ValidationException::withMessages(['parada' => 'Solo se quitan paradas pendientes.']);
        }
        $r = Recorrido::findOrFail($p->recorrido_id);
        $p->delete();
        $this->renumerar($r);
        $this->metricas($r);
    }

    /** Reordena las paradas pendientes; las ya visitadas quedan primero, en el orden en que se hicieron. */
    public function optimizar(Recorrido $r): Recorrido
    {
        $todas = $r->paradas()->get();
        $hechas = $todas->where('estado', '!=', 'pendiente')->sortBy(fn ($p) => [$p->visitada_at?->timestamp ?? PHP_INT_MAX, $p->orden])->values();
        $pendientes = $todas->where('estado', 'pendiente')->values();
        $conCoord = $pendientes->filter(fn ($p) => $p->lat !== null)->values();
        $sinCoord = $pendientes->filter(fn ($p) => $p->lat === null)->values();

        $ultima = $hechas->filter(fn ($p) => $p->lat !== null)->last();
        $inicio = $ultima ? [$ultima->lat, $ultima->lng] : ($r->origen_lat !== null ? [$r->origen_lat, $r->origen_lng] : null);
        $puntos = $conCoord->map(fn ($p) => [$p->lat, $p->lng])->all();
        $orden = $this->ordenar($inicio, $puntos);

        DB::transaction(function () use ($hechas, $orden, $conCoord, $sinCoord, $r) {
            $n = 0;
            foreach ([...$hechas->all(), ...array_map(fn ($i) => $conCoord[$i], $orden), ...$sinCoord->all()] as $p) {
                $p->forceFill(['orden' => ++$n])->save();
            }
            $r->forceFill(['optimizado_at' => now()])->save();
        });
        $this->metricas($r);

        return $r->fresh('paradas');
    }

    public function marcar(Parada $p, string $estado, ?string $resultado, ?string $notas, User $user): Parada
    {
        $r = Recorrido::findOrFail($p->recorrido_id);
        if ($r->user_id !== $user->id) {
            throw ValidationException::withMessages(['parada' => 'Solo el ejecutivo del recorrido registra sus visitas.']);
        }
        if ($r->estado === 'cerrado') {
            throw ValidationException::withMessages(['parada' => 'El recorrido está cerrado.']);
        }
        if ($estado === 'visitada' && ! $resultado) {
            throw ValidationException::withMessages(['resultado' => 'Indica el resultado de la visita.']);
        }

        return DB::transaction(function () use ($p, $r, $estado, $resultado, $notas) {
            $p->forceFill(['estado' => $estado, 'resultado' => $estado === 'visitada' ? $resultado : ($resultado ?: 'ausente'), 'notas' => $notas ? mb_substr(trim($notas), 0, 1000) : null,
                'visitada_at' => now()])->save();
            if ($r->estado === 'planificado') {
                $r->forceFill(['estado' => 'en_curso'])->save();
            }
            if ($p->lead_id) {
                $this->moverLead($p);
            }

            return $p;
        });
    }

    public function cerrar(Recorrido $r, User $user): Recorrido
    {
        if ($r->estado === 'cerrado') {
            return $r;
        }
        $r->paradas()->where('estado', 'pendiente')->update(['estado' => 'no_visitada', 'resultado' => 'reagendar', 'visitada_at' => now()]);
        $r->forceFill(['estado' => 'cerrado'])->save();
        $this->auditoria->registrar('recorrido.cerrar', $r, null, ['por' => $user->id]);

        return $r;
    }

    /** @return array{cliente_id: int|null, lead_id: int|null, nombre: string, direccion: string, comuna: string|null} */
    private function origenParada(array $p, User $ejecutivo, int $i): array
    {
        if (! empty($p['cliente_id'])) {
            $c = Cliente::find($p['cliente_id']);
            if (! $c || ! trim((string) $c->direccion)) {
                throw ValidationException::withMessages(["paradas.{$i}" => 'El cliente no existe o no tiene dirección.']);
            }

            return ['cliente_id' => $c->id, 'lead_id' => null, 'nombre' => $c->razon_social, 'direccion' => $c->direccion, 'comuna' => $c->comuna];
        }
        if (! empty($p['lead_id'])) {
            $l = Lead::with('agente')->find($p['lead_id']);
            if (! $l || ! trim((string) $l->direccion)) {
                throw ValidationException::withMessages(["paradas.{$i}" => 'El prospecto no existe o no tiene dirección.']);
            }
            if ($l->agente && $l->agente->user_id !== $ejecutivo->id) {
                throw ValidationException::withMessages(["paradas.{$i}" => "{$l->nombre} está asignado a otro agente."]);
            }
            if ($l->estado === 'no_contactar') {
                throw ValidationException::withMessages(["paradas.{$i}" => "{$l->nombre} pidió no ser contactado."]);
            }

            return ['cliente_id' => $l->cliente_id, 'lead_id' => $l->id, 'nombre' => $l->nombre, 'direccion' => $l->direccion, 'comuna' => $l->comuna];
        }
        throw ValidationException::withMessages(["paradas.{$i}" => 'Elige un cliente o un prospecto.']);
    }

    private function moverLead(Parada $p): void
    {
        $l = Lead::find($p->lead_id);
        if (! $l || ! in_array($l->estado, Lead::ABIERTOS, true)) {
            return;
        }
        $nuevo = match ($p->resultado) {
            'interesado', 'cotizacion', 'seguimiento' => 'interesado',
            'venta' => 'ganado',
            'sin_interes' => 'perdido',
            default => $l->estado === 'nuevo' || $l->estado === 'asignado' ? 'contactado' : $l->estado,
        };
        $l->forceFill(['estado' => $p->estado === 'visitada' ? $nuevo : $l->estado, 'ultimo_contacto_at' => now()])->save();
    }

    private function renumerar(Recorrido $r): void
    {
        foreach ($r->paradas()->get() as $i => $p) {
            $p->forceFill(['orden' => $i + 1])->save();
        }
    }

    private function metricas(Recorrido $r): void
    {
        $paradas = $r->paradas()->whereNotNull('lat')->get();
        $prev = $r->origen_lat !== null ? [$r->origen_lat, $r->origen_lng] : null;
        $km = 0.0;
        foreach ($paradas as $p) {
            if ($prev) {
                $km += $this->km($prev, [$p->lat, $p->lng]);
            }
            $prev = [$p->lat, $p->lng];
        }
        $km *= self::FACTOR_CALLE;
        $r->forceFill(['distancia_km' => round($km, 2), 'duracion_min' => (int) round($km / self::KMH * 60 + $r->paradas()->count() * self::MIN_POR_VISITA)])->save();
    }

    /**
     * Vecino más cercano desde el inicio y luego 2-opt hasta que no mejore.
     *
     * @param  list<array{0: float, 1: float}>  $puntos
     * @return list<int> índices en el orden de visita
     */
    public function ordenar(?array $inicio, array $puntos): array
    {
        $n = count($puntos);
        if ($n <= 1) {
            return array_keys($puntos);
        }
        $libres = range(0, $n - 1);
        $ruta = [];
        $actual = $inicio;
        while ($libres) {
            usort($libres, fn ($a, $b) => $actual ? $this->km($actual, $puntos[$a]) <=> $this->km($actual, $puntos[$b]) : $a <=> $b);
            $sig = array_shift($libres);
            $ruta[] = $sig;
            $actual = $puntos[$sig];
        }
        $largo = fn (array $r) => array_sum(array_map(fn ($i) => $this->km($i === 0 ? ($inicio ?? $puntos[$r[0]]) : $puntos[$r[$i - 1]], $puntos[$r[$i]]), array_keys($r)));
        $mejor = $largo($ruta);
        for ($vuelta = 0, $mejoro = true; $mejoro && $vuelta < 50; $vuelta++) {
            $mejoro = false;
            for ($i = 0; $i < $n - 1; $i++) {
                for ($k = $i + 1; $k < $n; $k++) {
                    $nueva = [...array_slice($ruta, 0, $i), ...array_reverse(array_slice($ruta, $i, $k - $i + 1)), ...array_slice($ruta, $k + 1)];
                    $l = $largo($nueva);
                    if ($l + 1e-9 < $mejor) {
                        [$ruta, $mejor, $mejoro] = [$nueva, $l, true];
                    }
                }
            }
        }

        return $ruta;
    }

    /** Distancia en línea recta (haversine), en km. */
    public function km(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $dLat = ($b[0] - $a[0]) * $rad;
        $dLng = ($b[1] - $a[1]) * $rad;
        $h = sin($dLat / 2) ** 2 + cos($a[0] * $rad) * cos($b[0] * $rad) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }
}
