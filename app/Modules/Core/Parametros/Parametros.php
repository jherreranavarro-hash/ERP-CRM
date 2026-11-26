<?php

namespace App\Modules\Core\Parametros;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\ParametroCambio;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lectura y administración de los parámetros de los mantenedores.
 *
 *  - Resolución con herencia: local → empresa → global → valor inicial del catálogo.
 *  - Vigencia: un cambio rige desde vigente_desde; se conserva el historial.
 *  - Piso y techo validados en el backend; empresa/local no pueden ser más laxos que el global.
 *  - Mantenedores sensibles: un usuario propone y otro distinto aprueba (cuatro ojos).
 *  - Caché en Redis invalidada al aprobarse un cambio.
 */
class Parametros
{
    private const TTL = 300;

    public function __construct(private Tenancy $tenancy, private Auditoria $auditoria) {}

    public function valor(string $clave, ?int $localId = null): mixed
    {
        $def = Catalogo::definicion($clave);
        $empresaId = $this->tenancy->id();
        $key = sprintf('param:v%d:%s:%s:%s', $this->version(), $clave, $empresaId ?? '-', $localId ?? '-');

        $valor = Cache::remember($key, self::TTL, function () use ($clave, $empresaId, $localId, $def) {
            $vigente = $this->vigente($clave, $empresaId, $localId);

            return ['v' => $vigente ? $vigente->valor : $def['inicial']];
        })['v'];

        return $this->aplicarPiso($def, $valor);
    }

    /** Cambio aprobado vigente más específico para el alcance pedido. */
    public function vigente(string $clave, ?int $empresaId, ?int $localId): ?ParametroCambio
    {
        return ParametroCambio::query()
            ->where('clave', $clave)
            ->where('estado', 'aprobado')
            ->where('vigente_desde', '<=', now())
            ->where(function ($q) use ($empresaId, $localId) {
                $q->whereNull('empresa_id');
                if ($empresaId) {
                    $q->orWhere(fn ($q) => $q->where('empresa_id', $empresaId)->whereNull('local_id'));
                }
                if ($empresaId && $localId) {
                    $q->orWhere(fn ($q) => $q->where('empresa_id', $empresaId)->where('local_id', $localId));
                }
            })
            ->orderByRaw('(local_id IS NOT NULL)::int + (empresa_id IS NOT NULL)::int DESC')
            ->orderByDesc('vigente_desde')
            ->orderByDesc('id')
            ->first();
    }

    /** Valor vigente en un alcance exacto (sin herencia), o null si no hay. */
    public function valorEnAlcance(string $clave, string $alcance, ?int $localId = null): mixed
    {
        [$empresaId, $localId] = $this->ids($alcance, $localId);

        return ParametroCambio::query()
            ->where('clave', $clave)->where('estado', 'aprobado')->where('vigente_desde', '<=', now())
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId), fn ($q) => $q->whereNull('empresa_id'))
            ->when($localId, fn ($q) => $q->where('local_id', $localId), fn ($q) => $q->whereNull('local_id'))
            ->orderByDesc('vigente_desde')->orderByDesc('id')
            ->value('valor');
    }

    public function proponer(
        string $clave,
        mixed $valor,
        string $alcance,
        string $motivo,
        User $user,
        ?int $localId = null,
        ?Carbon $vigenteDesde = null,
        ?int $revierteA = null,
    ): ParametroCambio {
        $def = Catalogo::definicion($clave);

        if (! in_array($alcance, $def['alcances'], true)) {
            throw ValidationException::withMessages(['alcance' => "Este parámetro no se puede fijar a nivel {$alcance}."]);
        }
        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'El motivo es obligatorio.']);
        }

        $valor = $this->normalizar($def, $valor);

        if ($alcance !== 'global') {
            $this->validarNoMasLaxoQueGlobal($clave, $def, $valor);
        }

        [$empresaId, $localId] = $this->ids($alcance, $localId);
        $auto = ! $def['sensible'];

        return DB::transaction(function () use ($clave, $valor, $empresaId, $localId, $motivo, $user, $vigenteDesde, $auto, $revierteA) {
            $cambio = ParametroCambio::create([
                'clave' => $clave,
                'empresa_id' => $empresaId,
                'local_id' => $localId,
                'valor' => $valor,
                'vigente_desde' => $vigenteDesde ?? now(),
                'estado' => $auto ? 'aprobado' : 'propuesto',
                'motivo' => $motivo,
                'propuesto_por' => $user->id,
                'resuelto_por' => $auto ? $user->id : null,
                'resuelto_at' => $auto ? now() : null,
                'auto_aprobado' => $auto,
                'revierte_a' => $revierteA,
            ]);

            $this->auditoria->registrar(
                $auto ? 'parametro.cambiar' : 'parametro.proponer',
                $cambio,
                ['valor' => $this->valorEnAlcanceIds($clave, $empresaId, $localId)],
                ['clave' => $clave, 'valor' => $valor, 'vigente_desde' => $cambio->vigente_desde->toIso8601String()],
                $motivo,
                $empresaId,
                global: $empresaId === null,
            );

            if ($auto) {
                $this->invalidar();
            }

            return $cambio;
        });
    }

    public function aprobar(ParametroCambio $cambio, User $user, ?string $comentario = null): ParametroCambio
    {
        $this->exigirPropuesto($cambio);

        if ($cambio->propuesto_por === $user->id) {
            throw ValidationException::withMessages(['cambio' => 'Nadie aprueba su propio cambio: debe aprobarlo otro usuario.']);
        }

        // Se revalida contra el catálogo vigente por si el piso cambió entre propuesta y aprobación.
        $this->normalizar(Catalogo::definicion($cambio->clave), $cambio->valor);

        $antes = $this->valorEnAlcanceIds($cambio->clave, $cambio->empresa_id, $cambio->local_id);
        $cambio->update([
            'estado' => 'aprobado',
            'resuelto_por' => $user->id,
            'resuelto_at' => now(),
            'comentario_resolucion' => $comentario,
        ]);

        $this->auditoria->registrar('parametro.aprobar', $cambio, ['valor' => $antes], ['clave' => $cambio->clave, 'valor' => $cambio->valor], $comentario, $cambio->empresa_id, global: $cambio->empresa_id === null);
        $this->invalidar();

        return $cambio;
    }

    public function rechazar(ParametroCambio $cambio, User $user, string $comentario): ParametroCambio
    {
        $this->exigirPropuesto($cambio);
        if ($cambio->propuesto_por === $user->id) {
            throw ValidationException::withMessages(['cambio' => 'Para retirar tu propia propuesta, pide a otro usuario que la rechace.']);
        }
        $cambio->update([
            'estado' => 'rechazado',
            'resuelto_por' => $user->id,
            'resuelto_at' => now(),
            'comentario_resolucion' => $comentario,
        ]);
        $this->auditoria->registrar('parametro.rechazar', $cambio, null, ['clave' => $cambio->clave], $comentario, $cambio->empresa_id, global: $cambio->empresa_id === null);

        return $cambio;
    }

    /** Volver a una versión anterior: genera una propuesta nueva con ese valor (también auditada). */
    public function revertir(ParametroCambio $anterior, User $user, string $motivo): ParametroCambio
    {
        if ($anterior->estado !== 'aprobado') {
            throw ValidationException::withMessages(['cambio' => 'Solo se puede volver a una versión aprobada.']);
        }

        return $this->proponer($anterior->clave, $anterior->valor, $anterior->alcance(), $motivo, $user, $anterior->local_id, revierteA: $anterior->id);
    }

    /**
     * Exporta los valores aprobados (global y empresa activa) en JSON firmado.
     * No hay secretos en los parámetros: los secretos viven en el vault.
     */
    public function exportar(): array
    {
        $datos = [];
        foreach (array_keys(Catalogo::parametros()) as $clave) {
            foreach (['global', 'empresa'] as $alcance) {
                if ($alcance === 'empresa' && ! $this->tenancy->activa()) {
                    continue;
                }
                $v = $this->valorEnAlcance($clave, $alcance);
                if ($v !== null) {
                    $datos[] = ['clave' => $clave, 'alcance' => $alcance, 'valor' => $v];
                }
            }
        }
        $payload = ['generado' => now()->toIso8601String(), 'ambiente' => app()->environment(), 'parametros' => $datos];

        return $payload + ['firma' => $this->firmar($payload)];
    }

    /**
     * Importa un JSON exportado. Cada valor entra como propuesta normal (pasa por
     * validación de piso y por cuatro ojos si el mantenedor es sensible).
     *
     * @return int cantidad de cambios creados
     */
    public function importar(array $json, User $user, string $motivo): int
    {
        $firma = $json['firma'] ?? '';
        unset($json['firma']);
        if (! hash_equals($this->firmar($json), (string) $firma)) {
            throw ValidationException::withMessages(['archivo' => 'La firma del archivo no es válida.']);
        }

        $n = 0;
        foreach ($json['parametros'] ?? [] as $p) {
            if (! isset(Catalogo::parametros()[$p['clave']])) {
                continue;
            }
            if ($this->valorEnAlcance($p['clave'], $p['alcance']) === $p['valor']) {
                continue;
            }
            $this->proponer($p['clave'], $p['valor'], $p['alcance'], $motivo, $user);
            $n++;
        }

        return $n;
    }

    /** Normaliza y valida un valor contra su definición. Lanza ValidationException. */
    public function normalizar(array $def, mixed $valor): mixed
    {
        $err = fn (string $m) => throw ValidationException::withMessages(['valor' => $m]);
        $min = $def['min'] ?? null;
        $max = $def['max'] ?? null;

        switch ($def['tipo']) {
            case 'entero':
                if (! is_numeric($valor) || (int) $valor != $valor) {
                    $err('Debe ser un número entero.');
                }
                $valor = (int) $valor;
                if ($min !== null && $valor < $min) {
                    $err("El mínimo permitido es {$min}.");
                }
                if ($max !== null && $valor > $max) {
                    $err("El máximo permitido es {$max}.");
                }

                return $valor;

            case 'porcentaje':
                if (! is_numeric($valor)) {
                    $err('Debe ser un porcentaje.');
                }
                $valor = round((float) $valor, 2);
                if ($valor < ($min ?? 0) || $valor > ($max ?? 100)) {
                    $err('Fuera del rango permitido ('.($min ?? 0).'–'.($max ?? 100).' %).');
                }

                return $valor == (int) $valor ? (int) $valor : $valor;

            case 'decimal':
                if (! is_numeric($valor)) {
                    $err('Debe ser un número.');
                }
                $valor = round((float) $valor, 4);
                if ($min !== null && $valor < $min) {
                    $err("El mínimo permitido es {$min}.");
                }
                if ($max !== null && $valor > $max) {
                    $err("El máximo permitido es {$max}.");
                }

                return $valor == (int) $valor ? (int) $valor : $valor;

            case 'booleano':
                $valor = filter_var($valor, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($valor === null) {
                    $err('Debe ser sí o no.');
                }
                $siempre = $def['siempre_true'] ?? null;
                if ($valor === false && ($siempre === 'siempre' || ($siempre === 'produccion' && app()->isProduction()))) {
                    $err('Este control no se puede desactivar (piso de seguridad).');
                }

                return $valor;

            case 'texto':
                $valor = trim((string) $valor);
                if (mb_strlen($valor) > 500) {
                    $err('Máximo 500 caracteres.');
                }

                return $valor;

            case 'lista':
                $valor = $this->lista($valor);
                if ($min !== null && count($valor) < $min) {
                    $err("Debe haber al menos {$min} elemento(s).");
                }

                return $valor;

            case 'opcion':
                if (! in_array($valor, $def['opciones'], true)) {
                    $err('Opción no válida.');
                }

                return $valor;

            case 'multiple':
                $valor = $this->lista($valor);
                if ($fuera = array_diff($valor, $def['opciones'])) {
                    $err('Opciones no válidas: '.implode(', ', $fuera).'.');
                }
                if ($faltan = array_diff($def['obligatorias'] ?? [], $valor)) {
                    $err('No se pueden quitar (piso de seguridad): '.implode(', ', $faltan).'.');
                }

                return array_values(array_intersect($def['opciones'], $valor));

            case 'escalones':
                $valor = array_map(function ($v) use ($err) {
                    if (! is_numeric($v) || (int) $v != $v) {
                        $err('Cada escalón debe ser un número entero.');
                    }

                    return (int) $v;
                }, $this->lista($valor));
                if ($valor === []) {
                    $err('Debe haber al menos un escalón.');
                }
                foreach ($valor as $i => $v) {
                    if (($min !== null && $v < $min) || ($max !== null && $v > $max)) {
                        $err("Cada escalón debe estar entre {$min} y {$max}.");
                    }
                    if ($i > 0 && $v <= $valor[$i - 1]) {
                        $err('Los escalones deben ir de menor a mayor.');
                    }
                }

                return $valor;
        }

        $err('Tipo de parámetro desconocido.');
    }

    private function aplicarPiso(array $def, mixed $valor): mixed
    {
        try {
            return $this->normalizar($def, $valor);
        } catch (ValidationException) {
            // Un valor guardado que ya no cumple el piso (p. ej. el piso subió) cae al inicial.
            return $def['inicial'];
        }
    }

    private function validarNoMasLaxoQueGlobal(string $clave, array $def, mixed $valor): void
    {
        $estricto = $def['estricto'] ?? null;
        if (! $estricto || ! in_array($def['tipo'], ['entero', 'decimal', 'porcentaje'], true)) {
            return;
        }
        $global = $this->valorEnAlcance($clave, 'global') ?? $def['inicial'];
        if (($estricto === 'mayor' && $valor < $global) || ($estricto === 'menor' && $valor > $global)) {
            throw ValidationException::withMessages(['valor' => "Una empresa o local solo puede ser igual o más estricto que el valor global ({$global})."]);
        }
    }

    private function exigirPropuesto(ParametroCambio $cambio): void
    {
        if ($cambio->estado !== 'propuesto') {
            throw ValidationException::withMessages(['cambio' => 'Este cambio ya fue resuelto.']);
        }
    }

    private function ids(string $alcance, ?int $localId): array
    {
        return match ($alcance) {
            'global' => [null, null],
            'empresa' => [$this->tenancy->id() ?? throw ValidationException::withMessages(['alcance' => 'No hay empresa activa.']), null],
            'local' => [$this->tenancy->id(), $localId ?? throw ValidationException::withMessages(['local_id' => 'Falta el local.'])],
            default => throw ValidationException::withMessages(['alcance' => 'Alcance no válido.']),
        };
    }

    private function valorEnAlcanceIds(string $clave, ?int $empresaId, ?int $localId): mixed
    {
        return $this->valorEnAlcance($clave, $localId ? 'local' : ($empresaId ? 'empresa' : 'global'), $localId);
    }

    private function lista(mixed $valor): array
    {
        if (is_string($valor)) {
            $valor = preg_split('/[\n,]+/', $valor) ?: [];
        }
        if (! is_array($valor)) {
            throw ValidationException::withMessages(['valor' => 'Debe ser una lista.']);
        }

        return array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), $valor), fn ($v) => $v !== '')));
    }

    private function firmar(array $payload): string
    {
        $clave = config('app.config_export_key') ?: config('app.key');

        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $clave);
    }

    private function version(): int
    {
        return (int) Cache::get('parametros:version', 1);
    }

    public function invalidar(): void
    {
        Cache::forever('parametros:version', $this->version() + 1);
    }
}
