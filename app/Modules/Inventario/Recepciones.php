<?php

namespace App\Modules\Inventario;

use App\Models\User;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\Inventario\Models\Recepcion;
use App\Support\Rut;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Recepción de mercadería: borrador editable → confirmada (entradas de kardex, inmutable). */
class Recepciones
{
    public function __construct(private Inventario $inventario, private Auditoria $auditoria) {}

    /**
     * @param  array{local_id:int, proveedor_nombre:string, proveedor_rut?:?string, documento_proveedor?:?string, observaciones?:?string,
     *               lineas: list<array{producto_id:int, cantidad:string|float, costo_unitario:string|float}>}  $d
     */
    public function guardar(array $d, User $user, ?Recepcion $rec = null): Recepcion
    {
        if ($rec && $rec->estado !== 'borrador') {
            throw ValidationException::withMessages(['recepcion' => 'Una recepción confirmada no se edita: corrige con un ajuste.']);
        }
        if (! empty($d['proveedor_rut']) && ! Rut::valido($d['proveedor_rut'])) {
            throw ValidationException::withMessages(['proveedor_rut' => 'El RUT del proveedor no es válido.']);
        }
        $lineas = array_values($d['lineas'] ?? []);
        if (! $lineas) {
            throw ValidationException::withMessages(['lineas' => 'Agrega al menos un producto.']);
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

        return DB::transaction(function () use ($d, $user, $rec, $lineas, $total) {
            if (! $rec) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['recepciones:'.app(\App\Modules\Core\Tenancy\Tenancy::class)->id()]);
                $rec = new Recepcion(['numero' => (int) Recepcion::max('numero') + 1, 'creado_por' => $user->id, 'estado' => 'borrador']);
            }
            $rec->fill([
                'local_id' => $d['local_id'], 'proveedor_nombre' => trim($d['proveedor_nombre']), 'proveedor_rut' => $d['proveedor_rut'] ?? null,
                'documento_proveedor' => $d['documento_proveedor'] ?? null, 'observaciones' => $d['observaciones'] ?? null,
                'fecha' => now()->toDateString(), 'costo_total' => Calculadora::red((string) $total),
            ])->save();
            $rec->lineas()->delete();
            foreach ($lineas as $l) {
                $rec->lineas()->create(['producto_id' => $l['producto_id'], 'cantidad' => (string) $l['cantidad'], 'costo_unitario' => (string) $l['costo_unitario']]);
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
            foreach ($rec->lineas as $l) {
                $this->inventario->mover($l->producto_id, $rec->local_id, 'recepcion', (string) $l->cantidad, $user, (string) $l->costo_unitario, ['recepcion', $rec->id],
                    "Recepción N° {$rec->numero} · {$rec->proveedor_nombre}".($rec->documento_proveedor ? " · doc. {$rec->documento_proveedor}" : ''));
                // Último costo de compra en la ficha del producto.
                Producto::whereKey($l->producto_id)->update(['costo' => (string) $l->costo_unitario]);
            }
            $rec->forceFill(['estado' => 'confirmada', 'confirmado_por' => $user->id, 'confirmado_at' => now()])->save();
            $this->auditoria->registrar('recepcion.confirmar', $rec, null, ['numero' => $rec->numero, 'costo_total' => $rec->costo_total]);

            return $rec;
        });
    }
}
