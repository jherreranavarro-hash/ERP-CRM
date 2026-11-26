<?php

namespace App\Modules\Inventario;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\Inventario\Models\Merma;
use App\Modules\Inventario\Models\Stock;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mermas: se valorizan al costo promedio. Hasta el umbral bajan stock al
 * registrarse; sobre el umbral quedan por aprobar y las aprueba otra persona.
 */
class Mermas
{
    public function __construct(private Inventario $inventario, private Parametros $parametros, private Auditoria $auditoria) {}

    public function registrar(int $localId, int $productoId, string $cantidad, string $motivo, string $detalle, User $user): Merma
    {
        if (! array_key_exists($motivo, Merma::MOTIVOS)) {
            throw ValidationException::withMessages(['motivo' => 'Motivo no válido.']);
        }
        if (trim($detalle) === '') {
            throw ValidationException::withMessages(['detalle' => 'Describe qué pasó.']);
        }
        $cant = BigDecimal::of($cantidad);
        if ($cant->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['cantidad' => 'La cantidad debe ser mayor que cero.']);
        }

        return DB::transaction(function () use ($localId, $productoId, $cant, $motivo, $detalle, $user) {
            // Una merma es física: nunca deja el stock bajo cero, aunque se permita vender sin stock.
            $this->inventario->exigirDisponible([$productoId => $cant], $localId, siempre: true);
            $costo = (string) (Stock::where('producto_id', $productoId)->where('local_id', $localId)->value('costo_promedio') ?? '0');
            $total = Calculadora::red((string) $cant->multipliedBy($costo));
            $umbral = (int) $this->parametros->valor('inventario.merma_aprobacion_monto');

            $merma = Merma::create([
                'local_id' => $localId, 'producto_id' => $productoId, 'cantidad' => (string) $cant, 'motivo' => $motivo, 'detalle' => trim($detalle),
                'costo_unitario' => $costo, 'costo_total' => $total, 'estado' => $total > $umbral ? 'por_aprobar' : 'registrada', 'responsable_id' => $user->id,
            ]);
            if ($merma->estado === 'registrada') {
                $this->aplicar($merma, $user);
            }

            return $merma;
        });
    }

    public function aprobar(Merma $merma, User $user): Merma
    {
        return DB::transaction(function () use ($merma, $user) {
            $m = Merma::whereKey($merma->id)->lockForUpdate()->firstOrFail();
            if ($m->estado !== 'por_aprobar') {
                throw ValidationException::withMessages(['merma' => 'Esta merma no está pendiente de aprobación.']);
            }
            if ($m->responsable_id === $user->id) {
                throw ValidationException::withMessages(['merma' => 'Nadie aprueba su propia merma.']);
            }
            $this->inventario->exigirDisponible([$m->producto_id => (string) $m->cantidad], $m->local_id, siempre: true);
            $m->forceFill(['estado' => 'registrada', 'aprobado_por' => $user->id, 'aprobado_at' => now()])->save();
            $this->aplicar($m, $user);

            return $m;
        });
    }

    public function rechazar(Merma $merma, User $user, string $motivo): Merma
    {
        if ($merma->estado !== 'por_aprobar') {
            throw ValidationException::withMessages(['merma' => 'Esta merma no está pendiente de aprobación.']);
        }
        if ($merma->responsable_id === $user->id) {
            throw ValidationException::withMessages(['merma' => 'Nadie rechaza su propia merma: debe revisarla otra persona.']);
        }
        $merma->forceFill(['estado' => 'rechazada', 'aprobado_por' => $user->id, 'aprobado_at' => now(), 'motivo_rechazo' => mb_substr($motivo, 0, 200)])->save();

        return $merma;
    }

    private function aplicar(Merma $m, User $user): void
    {
        $this->inventario->mover($m->producto_id, $m->local_id, 'merma', '-'.$m->cantidad, $user, origen: ['merma', $m->id], referencia: Merma::MOTIVOS[$m->motivo].': '.$m->detalle);
        $this->auditoria->registrar('merma.registrar', $m, null, ['producto_id' => $m->producto_id, 'cantidad' => (string) $m->cantidad, 'costo_total' => $m->costo_total, 'responsable' => $m->responsable_id]);
    }
}
