<?php

namespace App\Modules\Inventario;

use App\Models\User;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\OrdenCompraLinea;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\Inventario\Models\Recepcion;
use App\Support\Rut;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recepción de mercadería: borrador editable → confirmada (entradas de kardex,
 * inmutable). Puede venir contra una orden de compra: entonces cada línea es
 * una línea de la OC, no se recibe más de lo pendiente (más la tolerancia) y
 * al confirmar se descuenta de lo pendiente de la orden.
 */
class Recepciones
{
    public function __construct(private Inventario $inventario, private Auditoria $auditoria, private Parametros $parametros) {}

    /**
     * @param  array{local_id:int, proveedor_nombre:string, proveedor_rut?:?string, documento_proveedor?:?string, observaciones?:?string,
     *               orden_compra_id?:?int, lineas: list<array{producto_id:int, orden_compra_linea_id?:?int, cantidad:string|float, costo_unitario:string|float}>}  $d
     */
    public function guardar(array $d, User $user, ?Recepcion $rec = null): Recepcion
    {
        if ($rec && $rec->estado !== 'borrador') {
            throw ValidationException::withMessages(['recepcion' => 'Una recepción confirmada no se edita: corrige con un ajuste.']);
        }
        if (! empty($d['proveedor_rut']) && ! Rut::valido($d['proveedor_rut'])) {
            throw ValidationException::withMessages(['proveedor_rut' => 'El RUT del proveedor no es válido.']);
        }
        $lineas = array_values(array_filter($d['lineas'] ?? [], fn ($l) => ! isset($l['orden_compra_linea_id']) || BigDecimal::of((string) $l['cantidad'])->isPositive()));
        if (! $lineas) {
            throw ValidationException::withMessages(['lineas' => 'Agrega al menos un producto.']);
        }
        $oc = null;
        if (! empty($d['orden_compra_id'])) {
            $oc = OrdenCompra::with('proveedor', 'lineas.producto:id,nombre')->find($d['orden_compra_id']);
            if (! $oc || ! in_array($oc->estado, OrdenCompra::RECIBIBLE, true)) {
                throw ValidationException::withMessages(['orden_compra_id' => 'La orden de compra no está aprobada o ya se recibió completa.']);
            }
            $d['local_id'] = $oc->local_id;
            $d['proveedor_nombre'] = $oc->proveedor->razon_social;
            $d['proveedor_rut'] = $oc->proveedor->rut;
            $this->validarContraOrden($oc, $lineas);
        }
        $productos = Producto::whereIn('id', array_column($lineas, 'producto_id'))->pluck('id')->all();
        $total = BigDecimal::zero();
        foreach ($lineas as $i => $l) {
            if (! in_array((int) $l['producto_id'], $productos, true)) {
                throw ValidationException::withMessages(["lineas.{$i}.producto_id" => 'Línea '.($i + 1).': producto no encontrado.']);
            }
            if (BigDecimal::of((string) $l['cantidad'])->isLessThanOrEqualTo(0) || BigDecimal::of((string) $l['costo_unitario'])->isNegative()) {
                throw ValidationException::withMessages(["lineas.{$i}" => 'Línea '.($i + 1).': cantidad mayor que cero y costo no negativo.']);
            }
            $total = $total->plus(BigDecimal::of((string) $l['cantidad'])->multipliedBy((string) $l['costo_unitario']));
        }

        return DB::transaction(function () use ($d, $user, $rec, $lineas, $total, $oc) {
            if (! $rec) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['recepciones:'.app(\App\Modules\Core\Tenancy\Tenancy::class)->id()]);
                $rec = new Recepcion(['numero' => (int) Recepcion::max('numero') + 1, 'creado_por' => $user->id, 'estado' => 'borrador']);
            }
            $rec->fill([
                'local_id' => $d['local_id'], 'orden_compra_id' => $oc?->id, 'proveedor_id' => $oc?->proveedor_id ?? ($d['proveedor_id'] ?? null), 'proveedor_nombre' => trim($d['proveedor_nombre']), 'proveedor_rut' => $d['proveedor_rut'] ?? null,
                'documento_proveedor' => $d['documento_proveedor'] ?? null, 'observaciones' => $d['observaciones'] ?? null,
                'fecha' => now()->toDateString(), 'costo_total' => Calculadora::red((string) $total),
            ])->save();
            $rec->lineas()->delete();
            foreach ($lineas as $l) {
                $rec->lineas()->create(['producto_id' => $l['producto_id'], 'orden_compra_linea_id' => $oc ? (int) $l['orden_compra_linea_id'] : null, 'cantidad' => (string) $l['cantidad'], 'costo_unitario' => (string) $l['costo_unitario']]);
            }

            return $rec->load('lineas');
        });
    }

    /** Confirma: cada línea entra al kardex con su costo, que recalcula el costo promedio. */
    public function confirmar(Recepcion $recepcion, User $user): Recepcion
    {
        return DB::transaction(function () use ($recepcion, $user) {
            $rec = Recepcion::whereKey($recepcion->id)->lockForUpdate()->firstOrFail();
            if ($rec->estado !== 'borrador') {
                throw ValidationException::withMessages(['recepcion' => 'La recepción ya está confirmada.']);
            }
            $oc = $rec->orden_compra_id ? OrdenCompra::whereKey($rec->orden_compra_id)->lockForUpdate()->first() : null;
            if ($oc) {
                if (! in_array($oc->estado, OrdenCompra::RECIBIBLE, true)) {
                    throw ValidationException::withMessages(['recepcion' => "La orden de compra N° {$oc->numero} ya no recibe mercadería."]);
                }
                // Revalida con la orden bloqueada: otra recepción pudo confirmarse entretanto.
                $this->validarContraOrden($oc->load('lineas.producto:id,nombre'), $rec->lineas->map(fn ($l) => $l->only('producto_id', 'orden_compra_linea_id', 'cantidad'))->all());
            }
            foreach ($rec->lineas as $l) {
                $this->inventario->mover($l->producto_id, $rec->local_id, 'recepcion', (string) $l->cantidad, $user, (string) $l->costo_unitario, ['recepcion', $rec->id],
                    "Recepción N° {$rec->numero} · {$rec->proveedor_nombre}".($rec->documento_proveedor ? " · doc. {$rec->documento_proveedor}" : ''));
                // Último costo de compra en la ficha del producto (queda en el historial como recepción).
                DB::select("select set_config('app.origen_precio', 'recepcion', true)");
                Producto::whereKey($l->producto_id)->update(['costo' => (string) $l->costo_unitario]);
            }
            if ($oc) {
                foreach ($rec->lineas as $l) {
                    OrdenCompraLinea::whereKey($l->orden_compra_linea_id)->increment('cantidad_recibida', (string) $l->cantidad);
                }
                $completa = $oc->lineas()->get()->every(fn (OrdenCompraLinea $l) => ! $l->pendiente()->isPositive());
                $oc->forceFill(['estado' => $completa ? 'recibida' : 'parcial'])->save();
            }
            $rec->forceFill(['estado' => 'confirmada', 'confirmado_por' => $user->id, 'confirmado_at' => now()])->save();
            $this->auditoria->registrar('recepcion.confirmar', $rec, null, ['numero' => $rec->numero, 'costo_total' => $rec->costo_total]);

            return $rec;
        });
    }

    /** Cada línea debe ser de la orden y no superar lo pendiente más la tolerancia. */
    private function validarContraOrden(OrdenCompra $oc, array $lineas): void
    {
        $tol = BigDecimal::one()->plus(BigDecimal::of((string) $this->parametros->valor('compras.recepcion_tolerancia_pct', $oc->local_id))->dividedBy(100, 6));
        $porLinea = [];
        foreach ($lineas as $i => $l) {
            $ol = $oc->lineas->firstWhere('id', (int) ($l['orden_compra_linea_id'] ?? 0));
            if (! $ol || $ol->producto_id !== (int) $l['producto_id']) {
                throw ValidationException::withMessages(["lineas.{$i}" => 'Línea '.($i + 1).": no corresponde a la orden de compra N° {$oc->numero}."]);
            }
            $porLinea[$ol->id] = ($porLinea[$ol->id] ?? BigDecimal::zero())->plus((string) $l['cantidad']);
        }
        foreach ($porLinea as $id => $cant) {
            $ol = $oc->lineas->firstWhere('id', $id);
            $maximo = BigDecimal::of((string) $ol->cantidad)->multipliedBy($tol)->minus((string) $ol->cantidad_recibida);
            if ($cant->isGreaterThan($maximo)) {
                throw ValidationException::withMessages(['lineas' => ($ol->producto?->nombre ?? 'Producto').': se pueden recibir hasta '.Inventario::cifra($maximo->isNegative() ? '0' : $maximo).' según la orden.']);
            }
        }
    }
}
