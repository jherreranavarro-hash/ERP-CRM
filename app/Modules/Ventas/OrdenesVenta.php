<?php

namespace App\Modules\Ventas;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Inventario\Inventario;
use App\Modules\Ventas\Models\Cotizacion;
use App\Modules\Ventas\Models\OrdenVenta;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orden de venta: nace de una cotización aceptada, valida stock disponible y
 * cupo de crédito antes de confirmar, y reserva el stock en su local hasta
 * facturarse (la reserva se libera al emitir el documento), anularse o vencer.
 */
class OrdenesVenta
{
    public function __construct(
        private Inventario $inventario,
        private Documentos $documentos,
        private HaciaDocumento $hacia,
        private Parametros $parametros,
        private Auditoria $auditoria,
    ) {}

    public function desdeCotizacion(Cotizacion $cotizacion, User $user, ?int $localId = null): OrdenVenta
    {
        return DB::transaction(function () use ($cotizacion, $user, $localId) {
            /** @var Cotizacion $cot */
            $cot = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $cot->load('lineas');
            if ($cot->estado === 'enviada' && $cot->vencida()) {
                throw ValidationException::withMessages(['cotizacion' => 'La cotización venció: renuévala antes de confirmar la orden.']);
            }
            if (! in_array($cot->estado, ['enviada', 'aceptada'], true)) {
                throw ValidationException::withMessages(['cotizacion' => 'Solo una cotización enviada o aceptada pasa a orden de venta.']);
            }
            if (! $cot->cliente_id) {
                throw ValidationException::withMessages(['cotizacion' => 'Registra al cliente antes de confirmar la orden (la cotización es para un contacto sin ficha).']);
            }
            $local = $localId ?? $cot->local_id ?? Local::where('activo', true)->orderBy('es_bodega')->orderBy('id')->value('id')
                ?? throw ValidationException::withMessages(['local_id' => 'Indica desde qué local se despacha.']);

            // Stock disponible (descontando otras reservas) y cupo, antes de confirmar.
            $this->inventario->exigirDisponible(Inventario::porProducto($cot->lineas), $local);
            $cliente = Cliente::findOrFail($cot->cliente_id);
            if ($cot->forma_pago === 'credito') {
                $this->exigirCupo($cliente, $cot->monto_total);
            }

            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['ordenes_venta:'.app(\App\Modules\Core\Tenancy\Tenancy::class)->id()]);
            $ov = OrdenVenta::create([
                'numero' => (int) OrdenVenta::max('numero') + 1,
                'local_id' => $local, 'cliente_id' => $cliente->id, 'cotizacion_id' => $cot->id, 'forma_pago' => $cot->forma_pago,
                'estado' => 'confirmada', 'fecha' => now()->toDateString(),
                'reserva_hasta' => now()->addDays((int) $this->parametros->valor('inventario.reserva_dias'))->toDateString(),
                'descuento_global_pct' => (string) $cot->descuento_global_pct, 'descuento_global_monto' => $cot->descuento_global_monto,
                'tasa_iva' => (string) $cot->tasa_iva, 'monto_neto' => $cot->monto_neto, 'monto_exento' => $cot->monto_exento,
                'monto_iva' => $cot->monto_iva, 'monto_total' => $cot->monto_total,
                'observaciones' => $cot->observaciones, 'creado_por' => $user->id, 'aprobado_por' => $cot->aprobado_por,
            ]);
            foreach ($cot->lineas as $l) {
                $ov->lineas()->create($l->only(['orden', 'producto_id', 'codigo', 'descripcion', 'cantidad', 'unidad', 'precio_unitario', 'precio_lista', 'descuento_pct', 'descuento_monto', 'monto_item', 'exento']));
            }

            $cot->forceFill(['estado' => 'convertida', 'orden_venta_id' => $ov->id])->save();
            $this->auditoria->registrar('orden_venta.confirmar', $ov, null, ['numero' => $ov->numero, 'cotizacion' => $cot->numero, 'total' => $ov->monto_total]);

            return $ov->load('lineas');
        });
    }

    /** Crea el borrador de factura o boleta. La reserva sigue hasta que el documento se emite. */
    public function facturar(OrdenVenta $orden, int $tipo, User $user): Documento
    {
        if (! in_array($tipo, [33, 39], true)) {
            throw ValidationException::withMessages(['tipo_dte' => 'Una orden de venta se factura con factura o boleta.']);
        }

        return DB::transaction(function () use ($orden, $tipo, $user) {
            $ov = OrdenVenta::whereKey($orden->id)->lockForUpdate()->firstOrFail();
            $ov->load('lineas');
            if ($ov->estado !== 'confirmada') {
                throw ValidationException::withMessages(['orden' => 'Solo se factura una orden confirmada.']);
            }
            if ($ov->documento_id && Documento::whereKey($ov->documento_id)->exists()) {
                throw ValidationException::withMessages(['orden' => 'La orden ya tiene un documento en preparación.']);
            }
            if (! $ov->reservando()) {
                // Reserva vencida: se vuelve a verificar que el stock alcance.
                $this->inventario->exigirDisponible(Inventario::porProducto($ov->lineas), $ov->local_id, $ov->id);
            }

            $doc = $this->documentos->guardar([
                'tipo_dte' => $tipo,
                'cliente_id' => $ov->cliente_id,
                'local_id' => $ov->local_id,
                'forma_pago' => $ov->forma_pago,
                'descuento_global_pct' => (string) $ov->descuento_global_pct,
                'observaciones' => trim("Orden de venta N° {$ov->numero}. ".($ov->observaciones ?? '')),
                'cotizacion_id' => $ov->cotizacion_id,
                'orden_venta_id' => $ov->id,
                'lineas' => $this->hacia->lineas($ov->lineas, $tipo, (string) $this->parametros->valor('tributario.tasa_iva')),
            ], $user);
            $this->hacia->heredarAprobacion($doc, $ov->aprobado_por, "Orden de venta N° {$ov->numero}");

            $ov->forceFill(['documento_id' => $doc->id])->save();

            return $doc;
        });
    }

    public function anular(OrdenVenta $ov, User $user, string $motivo): OrdenVenta
    {
        if ($ov->estado !== 'confirmada') {
            throw ValidationException::withMessages(['orden' => 'Solo se anula una orden confirmada.']);
        }
        if ($ov->documento_id && Documento::whereKey($ov->documento_id)->whereNotIn('estado', ['borrador', 'por_aprobar'])->exists()) {
            throw ValidationException::withMessages(['orden' => 'La orden ya se facturó: anula el documento con nota de crédito.']);
        }

        return DB::transaction(function () use ($ov, $user, $motivo) {
            $borrador = $ov->documento_id;
            $ov->forceFill(['estado' => 'anulada', 'documento_id' => null, 'motivo_anulacion' => mb_substr(trim($motivo), 0, 200)])->save();
            if ($borrador) {
                Documento::whereKey($borrador)->whereIn('estado', ['borrador', 'por_aprobar'])->delete();
            }
            $this->auditoria->registrar('orden_venta.anular', $ov, null, ['numero' => $ov->numero], $motivo);

            return $ov;
        });
    }

    /** Deuda vigente + órdenes a crédito pendientes + esta, contra el cupo del cliente. */
    private function exigirCupo(Cliente $cliente, int $monto): void
    {
        if (! $cliente->tiene_credito) {
            throw ValidationException::withMessages(['forma_pago' => 'Venta a crédito solo para clientes con cuenta corriente.']);
        }
        $pendiente = (int) OrdenVenta::where('cliente_id', $cliente->id)->where('forma_pago', 'credito')->where('estado', 'confirmada')->sum('monto_total');
        $comprometido = $cliente->deuda() + $pendiente + $monto;
        if ($comprometido > $cliente->cupo_credito) {
            throw ValidationException::withMessages(['forma_pago' => 'Cupo de crédito insuficiente: deuda y órdenes pendientes $'.number_format($cliente->deuda() + $pendiente, 0, ',', '.')
                .' + esta orden $'.number_format($monto, 0, ',', '.').' > cupo $'.number_format($cliente->cupo_credito, 0, ',', '.').'.']);
        }
    }
}
