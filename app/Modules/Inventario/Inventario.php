<?php

namespace App\Modules\Inventario;

use App\Models\User;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Inventario\Models\MovimientoStock;
use App\Modules\Inventario\Models\Stock;
use App\Modules\Ventas\Models\OrdenVenta;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock y kardex. Todo cambio de existencias pasa por mover(): inserta un
 * movimiento y el trigger de la base actualiza el stock con la fila bloqueada,
 * fija el saldo y recalcula el costo promedio ponderado.
 *
 * Disponible = stock físico − lo reservado por órdenes de venta vigentes.
 */
class Inventario
{
    public function __construct(private Parametros $parametros) {}

    /**
     * @param  string  $cantidad  con signo: positiva entra, negativa sale
     * @param  array{0:string,1:int}|null  $origen
     */
    public function mover(int $productoId, int $localId, string $tipo, string $cantidad, User $user, ?string $costoUnitario = null, ?array $origen = null, ?string $referencia = null): MovimientoStock
    {
        $mov = MovimientoStock::create([
            'producto_id' => $productoId, 'local_id' => $localId, 'tipo' => $tipo, 'cantidad' => $cantidad,
            'costo_unitario' => $costoUnitario, 'origen_tipo' => $origen[0] ?? null, 'origen_id' => $origen[1] ?? null,
            'referencia' => $referencia ? mb_substr($referencia, 0, 120) : null, 'usuario_id' => $user->id,
        ]);

        return $mov->fresh();
    }

    public function fisico(int $productoId, int $localId): BigDecimal
    {
        return BigDecimal::of((string) (Stock::where('producto_id', $productoId)->where('local_id', $localId)->value('cantidad') ?? '0'));
    }

    /** Reservado por órdenes de venta confirmadas y vigentes en el local. */
    public function reservado(int $productoId, int $localId, ?int $excluirOrden = null): BigDecimal
    {
        $v = DB::table('orden_venta_lineas as l')->join('ordenes_venta as o', 'o.id', '=', 'l.orden_venta_id')
            ->where('l.producto_id', $productoId)->where('o.local_id', $localId)->where('o.estado', 'confirmada')
            ->where('o.reserva_hasta', '>=', now()->toDateString())
            ->when($excluirOrden, fn ($q) => $q->where('o.id', '<>', $excluirOrden))
            ->sum('l.cantidad');

        return BigDecimal::of((string) ($v ?: '0'));
    }

    public function disponible(int $productoId, int $localId, ?int $excluirOrden = null): BigDecimal
    {
        return $this->fisico($productoId, $localId)->minus($this->reservado($productoId, $localId, $excluirOrden));
    }

    /**
     * Verifica que alcance el disponible para todas las cantidades pedidas.
     * Con el parámetro "Permitir vender sin stock" encendido no se exige.
     *
     * @param  array<int, string|BigDecimal>  $pedido  producto_id => cantidad
     */
    public function exigirDisponible(array $pedido, int $localId, ?int $excluirOrden = null, bool $siempre = false): void
    {
        if (! $siempre && $this->parametros->valor('inventario.permitir_negativo', $localId)) {
            return;
        }
        // Bloquea las filas de stock involucradas: dos ventas simultáneas no se llevan la misma unidad.
        Stock::whereIn('producto_id', array_keys($pedido))->where('local_id', $localId)->lockForUpdate()->get();

        $faltan = [];
        foreach ($pedido as $productoId => $cantidad) {
            $disp = $this->disponible($productoId, $localId, $excluirOrden);
            if ($disp->isLessThan(BigDecimal::of((string) $cantidad))) {
                $p = Producto::withTrashed()->find($productoId);
                $faltan[] = "{$p?->nombre}: pides ".self::cifra($cantidad).', hay '.self::cifra($disp).' disponible';
            }
        }
        if ($faltan) {
            throw ValidationException::withMessages(['stock' => 'Stock insuficiente. '.implode('; ', $faltan).'.']);
        }
    }

    /**
     * Suma cantidades por producto de un conjunto de líneas con producto.
     *
     * @return array<int, BigDecimal>
     */
    public static function porProducto(iterable $lineas): array
    {
        $r = [];
        foreach ($lineas as $l) {
            $pid = is_array($l) ? ($l['producto_id'] ?? null) : $l->producto_id;
            if (! $pid) {
                continue;
            }
            $r[$pid] = ($r[$pid] ?? BigDecimal::zero())->plus(BigDecimal::of((string) (is_array($l) ? $l['cantidad'] : $l->cantidad)));
        }

        return $r;
    }

    /** Decimal sin ceros sobrantes, como texto para formularios ("12.5", "0"). */
    public static function dec(string|BigDecimal|null $v): string
    {
        $v = (string) ($v ?? '0');

        return str_contains($v, '.') ? (rtrim(rtrim($v, '0'), '.') ?: '0') : $v;
    }

    public static function cifra(string|BigDecimal $v): string
    {
        return rtrim(rtrim(number_format((float) (string) $v, 3, ',', '.'), '0'), ',');
    }
}
