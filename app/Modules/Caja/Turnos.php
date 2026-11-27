<?php

namespace App\Modules\Caja;

use App\Models\User;
use App\Modules\Caja\Models\Caja;
use App\Modules\Caja\Models\TurnoCaja;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Documentos;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turnos de caja: apertura con fondo, cobros del turno y cierre con cuadratura
 * por medio de pago (efectivo, débito y crédito). Una diferencia siempre queda
 * en la bitácora; si supera la tolerancia, otra persona debe aprobarla.
 */
class Turnos
{
    public function __construct(private Parametros $parametros, private Auditoria $auditoria) {}

    public function abierto(User $user): ?TurnoCaja
    {
        return TurnoCaja::with('caja.local:id,nombre')->where('usuario_id', $user->id)->where('estado', 'abierto')->first();
    }

    public function abrir(Caja $caja, int $montoApertura, User $user): TurnoCaja
    {
        if (! Caja::whereKey($caja->id)->value('activo')) {
            throw ValidationException::withMessages(['caja_id' => 'Esa caja está desactivada.']);
        }
        if ($montoApertura < 0) {
            throw ValidationException::withMessages(['monto_apertura' => 'El fondo inicial no puede ser negativo.']);
        }
        if ($this->abierto($user)) {
            throw ValidationException::withMessages(['caja_id' => 'Ya tienes un turno abierto: ciérralo antes de abrir otro.']);
        }
        if (TurnoCaja::where('caja_id', $caja->id)->where('estado', 'abierto')->exists()) {
            throw ValidationException::withMessages(['caja_id' => "{$caja->nombre} ya tiene un turno abierto por otra persona."]);
        }

        try {
            $turno = DB::transaction(fn () => TurnoCaja::create([
                'caja_id' => $caja->id, 'usuario_id' => $user->id, 'estado' => 'abierto',
                'monto_apertura' => $montoApertura, 'abierto_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Dos aperturas simultáneas: el índice único decide.
            throw ValidationException::withMessages(['caja_id' => 'La caja o tu usuario ya tienen un turno abierto.']);
        }
        $this->auditoria->registrar('caja.apertura', $turno, null, ['caja' => $caja->nombre, 'fondo' => $montoApertura]);

        return $turno;
    }

    /**
     * Lo que debería haber en caja según los pagos del turno.
     *
     * @return array{esperado: array<string,int>, otros: array<string,int>, ventas: int, documentos: int}
     */
    public function resumen(TurnoCaja $turno): array
    {
        $porMedio = $turno->pagos()->selectRaw('metodo, sum(monto) as total')->groupBy('metodo')->pluck('total', 'metodo')->map(fn ($v) => (int) $v)->all();

        $esperado = [];
        foreach (Documentos::MEDIOS_PRESENCIALES as $m) {
            $esperado[$m] = ($porMedio[$m] ?? 0) + ($m === 'efectivo' ? $turno->monto_apertura : 0);
        }

        return [
            'esperado' => $esperado,
            'otros' => array_diff_key($porMedio, array_flip(Documentos::MEDIOS_PRESENCIALES)),
            'ventas' => array_sum($porMedio),
            'documentos' => (int) $turno->pagos()->distinct('documento_id')->count('documento_id'),
        ];
    }

    /**
     * Cierra el turno con lo contado por medio. El esperado se calcula aquí,
     * con el turno bloqueado, así ningún pago entra durante el cierre.
     *
     * @param  array<string,int>  $declarado
     */
    public function cerrar(TurnoCaja $turno, array $declarado, User $user, ?string $observacion = null): TurnoCaja
    {
        return DB::transaction(function () use ($turno, $declarado, $user, $observacion) {
            /** @var TurnoCaja $t */
            $t = TurnoCaja::whereKey($turno->id)->lockForUpdate()->firstOrFail();
            if (! $t->abierto()) {
                throw ValidationException::withMessages(['turno' => 'El turno ya está cerrado.']);
            }

            $esperado = $this->resumen($t)['esperado'];
            $contado = [];
            foreach (array_keys($esperado) as $m) {
                $v = $declarado[$m] ?? null;
                if ($v === null || $v === '' || ! is_numeric($v) || (int) $v < 0) {
                    throw ValidationException::withMessages(["declarado.{$m}" => 'Indica el monto contado (0 si no hay).']);
                }
                $contado[$m] = (int) $v;
            }

            $diferencias = array_map(fn ($m) => $contado[$m] - $esperado[$m], array_combine(array_keys($esperado), array_keys($esperado)));
            $tolerancia = (int) $this->parametros->valor('caja.tolerancia', $t->caja->local_id);
            $hayDiferencia = (bool) array_filter($diferencias);
            $sobreTolerancia = (bool) array_filter($diferencias, fn ($d) => abs($d) > $tolerancia);

            if ($hayDiferencia && trim((string) $observacion) === '') {
                throw ValidationException::withMessages(['observacion' => 'Hay diferencia en la cuadratura: explica qué pasó.']);
            }

            $t->forceFill([
                'estado' => 'cerrado',
                'cerrado_at' => now(),
                'esperado' => $esperado,
                'declarado' => $contado,
                'diferencia' => array_sum($diferencias),
                'requiere_aprobacion' => $sobreTolerancia,
                'observacion' => $observacion ? mb_substr(trim($observacion), 0, 300) : null,
            ])->save();

            $this->auditoria->registrar('caja.cierre', $t, null, ['esperado' => $esperado, 'declarado' => $contado, 'cerrado_por' => $user->id]);
            if ($hayDiferencia) {
                // Mínimo auditado no desactivable: todo cierre con diferencia.
                $this->auditoria->registrar('caja.cierre_con_diferencia', $t, null, ['diferencias' => $diferencias, 'tolerancia' => $tolerancia], $observacion);
            }
            if ($sobreTolerancia) {
                app(\App\Modules\Operacion\Alertas::class)->emitir('diferencia_caja', "Cierre de caja con diferencia sobre la tolerancia ({$t->caja->nombre})", [
                    'caja' => $t->caja->nombre, 'diferencia' => array_sum($diferencias), 'tolerancia' => $tolerancia,
                ], "turno:{$t->id}");
            }

            return $t;
        });
    }

    public function aprobarDiferencia(TurnoCaja $turno, User $user, ?string $comentario = null): TurnoCaja
    {
        if (! $turno->pendienteDeAprobacion()) {
            throw ValidationException::withMessages(['turno' => 'Este cierre no tiene una diferencia pendiente de aprobación.']);
        }
        if ($turno->usuario_id === $user->id) {
            throw ValidationException::withMessages(['turno' => 'Nadie aprueba la diferencia de su propio turno.']);
        }
        $turno->forceFill(['aprobado_por' => $user->id, 'aprobado_at' => now()])->save();
        $this->auditoria->registrar('caja.diferencia_aprobada', $turno, null, ['diferencia' => $turno->diferencia], $comentario);

        return $turno;
    }
}
