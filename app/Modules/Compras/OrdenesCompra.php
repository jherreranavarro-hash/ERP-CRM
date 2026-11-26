<?php

namespace App\Modules\Compras;

use App\Models\User;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Compras\Models\OrdenCompra;
use App\Modules\Compras\Models\Proveedor;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Calculo\Calculadora;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Órdenes de compra: borrador → (enviar) aprobada o por_aprobar → aprobada →
 * parcial/recibida (por las recepciones) → cerrada. Requiere aprobación de
 * otra persona cuando supera el monto, y comparar cotizaciones desde cierto
 * neto; elegir una que no es la más barata también pide aprobación.
 */
class OrdenesCompra
{
    public const MAX_LINEAS = 200;

    public function __construct(private Parametros $parametros, private Auditoria $auditoria) {}

    /**
     * @param  array{proveedor_id:int, local_id:int, fecha_entrega?:?string, observaciones?:?string,
     *               lineas: list<array{producto_id:int, cantidad:string|float, costo_unitario:string|float}>,
     *               cotizaciones?: list<array{proveedor_id?:?int, proveedor_nombre:string, monto_neto:int, plazo_entrega_dias?:?int, observacion?:?string, elegida?:bool}>}  $d
     */
    public function guardar(array $d, User $user, ?OrdenCompra $oc = null): OrdenCompra
    {
        if ($oc && $oc->estado !== 'borrador') {
            throw ValidationException::withMessages(['orden' => 'Solo se edita una orden en borrador.']);
        }
        $proveedor = Proveedor::where('activo', true)->find($d['proveedor_id']) ?? throw ValidationException::withMessages(['proveedor_id' => 'Proveedor no encontrado o inactivo.']);
        $lineas = array_values($d['lineas'] ?? []);
        if (! $lineas) {
            throw ValidationException::withMessages(['lineas' => 'Agrega al menos un producto.']);
        }
        if (count($lineas) > self::MAX_LINEAS) {
            throw ValidationException::withMessages(['lineas' => 'Máximo '.self::MAX_LINEAS.' líneas por orden.']);
        }
        $productos = Producto::whereIn('id', array_column($lineas, 'producto_id'))->pluck('id')->all();
        $neto = 0;
        $filas = [];
        foreach ($lineas as $i => $l) {
            if (! in_array((int) $l['producto_id'], $productos, true)) {
                throw ValidationException::withMessages(["lineas.{$i}.producto_id" => 'Línea '.($i + 1).': producto no encontrado.']);
            }
            $cant = BigDecimal::of((string) $l['cantidad']);
            $costo = BigDecimal::of((string) $l['costo_unitario']);
            if ($cant->isLessThanOrEqualTo(0) || $costo->isNegative()) {
                throw ValidationException::withMessages(["lineas.{$i}" => 'Línea '.($i + 1).': cantidad mayor que cero y costo no negativo.']);
            }
            $monto = Calculadora::red($cant->multipliedBy($costo));
            $neto += $monto;
            $filas[] = ['producto_id' => (int) $l['producto_id'], 'cantidad' => (string) $cant, 'costo_unitario' => (string) $costo, 'monto' => $monto];
        }

        $cotizaciones = array_values(array_filter($d['cotizaciones'] ?? [], fn ($c) => trim((string) ($c['proveedor_nombre'] ?? '')) !== ''));
        if (count(array_filter($cotizaciones, fn ($c) => ! empty($c['elegida']))) > 1) {
            throw ValidationException::withMessages(['cotizaciones' => 'Elige una sola cotización.']);
        }
        foreach ($cotizaciones as $i => $c) {
            if ((int) ($c['monto_neto'] ?? 0) <= 0) {
                throw ValidationException::withMessages(["cotizaciones.{$i}.monto_neto" => 'Cotización '.($i + 1).': indica el monto neto.']);
            }
        }

        $iva = Calculadora::red(BigDecimal::of($neto)->multipliedBy((string) $this->parametros->valor('tributario.tasa_iva'))->dividedBy(100, 6));

        return DB::transaction(function () use ($d, $user, $oc, $proveedor, $filas, $cotizaciones, $neto, $iva) {
            if (! $oc) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['ordenes_compra:'.app(Tenancy::class)->id()]);
                $oc = new OrdenCompra(['numero' => (int) OrdenCompra::max('numero') + 1, 'creado_por' => $user->id, 'estado' => 'borrador', 'fecha' => now()->toDateString()]);
            }
            $oc->fill([
                'proveedor_id' => $proveedor->id, 'local_id' => $d['local_id'], 'fecha_entrega' => $d['fecha_entrega'] ?? null,
                'observaciones' => $d['observaciones'] ?? null, 'monto_neto' => $neto, 'monto_iva' => $iva, 'monto_total' => $neto + $iva,
            ])->save();
            $oc->lineas()->delete();
            $oc->cotizaciones()->delete();
            foreach ($filas as $f) {
                $oc->lineas()->create($f);
            }
            foreach ($cotizaciones as $c) {
                $oc->cotizaciones()->create([
                    'proveedor_id' => $c['proveedor_id'] ?? null, 'proveedor_nombre' => mb_substr(trim($c['proveedor_nombre']), 0, 100), 'monto_neto' => (int) $c['monto_neto'],
                    'plazo_entrega_dias' => $c['plazo_entrega_dias'] ?? null, 'observacion' => isset($c['observacion']) ? mb_substr(trim($c['observacion']), 0, 200) : null, 'elegida' => ! empty($c['elegida']),
                ]);
            }

            return $oc->load('lineas', 'cotizaciones');
        });
    }

    /** Envía el borrador: queda aprobada si no hay motivos, o espera la aprobación de otra persona. */
    public function enviar(OrdenCompra $orden, User $user): OrdenCompra
    {
        return DB::transaction(function () use ($orden, $user) {
            $oc = OrdenCompra::whereKey($orden->id)->lockForUpdate()->firstOrFail();
            if ($oc->estado !== 'borrador') {
                throw ValidationException::withMessages(['orden' => 'La orden ya fue enviada.']);
            }
            $oc->load('cotizaciones');
            $motivos = [];
            $limite = (int) $this->parametros->valor('compras.oc_aprobacion_monto', $oc->local_id);
            if ($oc->monto_neto > $limite) {
                $motivos[] = 'Neto sobre $'.number_format($limite, 0, ',', '.').' sin aprobación.';
            }
            if ($oc->monto_neto >= (int) $this->parametros->valor('compras.cotizaciones_desde', $oc->local_id)) {
                $minimas = (int) $this->parametros->valor('compras.cotizaciones_minimas', $oc->local_id);
                if ($oc->cotizaciones->count() < $minimas) {
                    throw ValidationException::withMessages(['cotizaciones' => "Por su monto, esta compra requiere comparar al menos {$minimas} cotizaciones."]);
                }
            }
            if ($oc->cotizaciones->isNotEmpty()) {
                $elegida = $oc->cotizaciones->firstWhere('elegida', true) ?? throw ValidationException::withMessages(['cotizaciones' => 'Marca la cotización elegida.']);
                $minimo = $oc->cotizaciones->min('monto_neto');
                if ($elegida->monto_neto > $minimo) {
                    $motivos[] = "Se elige la cotización de {$elegida->proveedor_nombre}, que no es la más barata.";
                }
            }

            $oc->forceFill($motivos
                ? ['estado' => 'por_aprobar', 'motivos' => $motivos]
                : ['estado' => 'aprobada', 'motivos' => null, 'aprobado_at' => now()])->save();
            $this->auditoria->registrar('orden_compra.enviar', $oc, null, ['numero' => $oc->numero, 'estado' => $oc->estado, 'neto' => $oc->monto_neto]);

            return $oc;
        });
    }

    public function aprobar(OrdenCompra $orden, User $user): OrdenCompra
    {
        return DB::transaction(function () use ($orden, $user) {
            $oc = $this->porAprobar($orden, $user, 'aprueba');
            $oc->forceFill(['estado' => 'aprobada', 'aprobado_por' => $user->id, 'aprobado_at' => now()])->save();
            $this->auditoria->registrar('orden_compra.aprobar', $oc, null, ['numero' => $oc->numero, 'motivos' => $oc->motivos]);

            return $oc;
        });
    }

    public function rechazar(OrdenCompra $orden, User $user, string $motivo): OrdenCompra
    {
        return DB::transaction(function () use ($orden, $user, $motivo) {
            $oc = $this->porAprobar($orden, $user, 'rechaza');
            $oc->forceFill(['estado' => 'rechazada', 'aprobado_por' => $user->id, 'motivo_rechazo' => mb_substr(trim($motivo), 0, 200)])->save();

            return $oc;
        });
    }

    /** Anula una orden sin recepciones (las ya recibidas se cierran). */
    public function anular(OrdenCompra $orden, User $user, string $motivo): OrdenCompra
    {
        return DB::transaction(function () use ($orden, $user, $motivo) {
            $oc = OrdenCompra::whereKey($orden->id)->lockForUpdate()->firstOrFail();
            if (! in_array($oc->estado, ['borrador', 'por_aprobar', 'aprobada'], true) || $oc->recepciones()->exists()) {
                throw ValidationException::withMessages(['orden' => 'Solo se anula una orden sin recepciones; si ya llegó algo, ciérrala.']);
            }
            $oc->forceFill(['estado' => 'anulada', 'motivo_rechazo' => mb_substr(trim($motivo), 0, 200)])->save();
            $this->auditoria->registrar('orden_compra.anular', $oc, null, ['numero' => $oc->numero, 'motivo' => $motivo]);

            return $oc;
        });
    }

    /** Cierra una orden recibida en parte: lo pendiente ya no se espera. */
    public function cerrar(OrdenCompra $orden, User $user): OrdenCompra
    {
        return DB::transaction(function () use ($orden, $user) {
            $oc = OrdenCompra::whereKey($orden->id)->lockForUpdate()->firstOrFail();
            if (! in_array($oc->estado, ['parcial', 'recibida'], true)) {
                throw ValidationException::withMessages(['orden' => 'Solo se cierra una orden que ya tiene recepciones.']);
            }
            if ($oc->recepciones()->where('estado', 'borrador')->exists()) {
                throw ValidationException::withMessages(['orden' => 'Hay una recepción en borrador de esta orden: confírmala o elimínala antes de cerrar.']);
            }
            $oc->forceFill(['estado' => 'cerrada'])->save();
            $this->auditoria->registrar('orden_compra.cerrar', $oc, null, ['numero' => $oc->numero]);

            return $oc;
        });
    }

    private function porAprobar(OrdenCompra $orden, User $user, string $verbo): OrdenCompra
    {
        $oc = OrdenCompra::whereKey($orden->id)->lockForUpdate()->firstOrFail();
        if ($oc->estado !== 'por_aprobar') {
            throw ValidationException::withMessages(['orden' => 'La orden no está esperando aprobación.']);
        }
        if ($oc->creado_por === $user->id) {
            throw ValidationException::withMessages(['orden' => "Nadie {$verbo} su propia orden de compra: debe revisarla otra persona."]);
        }

        return $oc;
    }
}
