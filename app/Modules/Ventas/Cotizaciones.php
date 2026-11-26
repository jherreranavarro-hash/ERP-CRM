<?php

namespace App\Modules\Ventas;

use App\Models\User;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Ventas\Models\Cotizacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Cotizaciones: borrador → (aprobación) → enviada → aceptada → convertida en
 * documento, o rechazada. Usa las mismas reglas de líneas, totales y
 * aprobaciones que los documentos tributarios; los montos son netos.
 */
class Cotizaciones
{
    public function __construct(
        private Documentos $documentos,
        private Calculadora $calc,
        private Parametros $parametros,
        private Auditoria $auditoria,
    ) {}

    /**
     * @param  array{cliente_id?:?int, contacto_nombre?:?string, contacto_email?:?string, contacto_telefono?:?string, local_id?:?int,
     *               forma_pago?:string, descuento_global_pct?:mixed, valida_hasta?:?string, observaciones?:?string, lineas:list<array>}  $datos
     */
    public function guardar(array $datos, User $user, ?Cotizacion $cot = null): Cotizacion
    {
        if ($cot && ! $cot->editable()) {
            throw ValidationException::withMessages(['cotizacion' => 'Una cotización aceptada, rechazada o convertida ya no se edita.']);
        }

        $cliente = ! empty($datos['cliente_id']) ? Cliente::findOrFail($datos['cliente_id']) : null;
        $contacto = trim((string) ($datos['contacto_nombre'] ?? ''));
        if (! $cliente && $contacto === '') {
            throw ValidationException::withMessages(['cliente_id' => 'Elige un cliente o escribe el nombre del contacto.']);
        }

        $formaPago = $datos['forma_pago'] ?? 'contado';
        if ($formaPago === 'credito' && ! $cliente?->tiene_credito) {
            throw ValidationException::withMessages(['forma_pago' => 'Crédito solo para clientes con cuenta corriente.']);
        }

        [$lineas, $totales, $descGlobal] = $this->calcularLineas($datos, $cliente, estricto: true);
        $motivos = $this->documentos->motivosDeAprobacion($lineas, $descGlobal, $formaPago, $cliente, $totales['monto_total'], null);

        $hoy = now()->startOfDay();
        $validaHasta = ! empty($datos['valida_hasta'])
            ? \Illuminate\Support\Carbon::parse($datos['valida_hasta'])->startOfDay()
            : ($cot?->valida_hasta ?? $hoy->copy()->addDays((int) $this->parametros->valor('cotizaciones.vigencia_dias')));
        if ($validaHasta->lt($hoy)) {
            throw ValidationException::withMessages(['valida_hasta' => 'La vigencia no puede ser anterior a hoy.']);
        }

        return DB::transaction(function () use ($cot, $datos, $user, $cliente, $contacto, $formaPago, $lineas, $totales, $descGlobal, $motivos, $hoy, $validaHasta) {
            if (! $cot) {
                $cot = new Cotizacion(['creado_por' => $user->id, 'numero' => $this->siguienteNumero()]);
            }
            $cot->fill([
                'cliente_id' => $cliente?->id,
                'contacto_nombre' => $contacto ?: null,
                'contacto_email' => $datos['contacto_email'] ?? null,
                'contacto_telefono' => $datos['contacto_telefono'] ?? null,
                'local_id' => $datos['local_id'] ?? $user->membresiaActual()?->local_id,
                'fecha' => $hoy->toDateString(),
                'valida_hasta' => $validaHasta->toDateString(),
                'forma_pago' => $formaPago,
                'descuento_global_pct' => $descGlobal,
                'tasa_iva' => (string) $this->parametros->valor('tributario.tasa_iva'),
                'observaciones' => $datos['observaciones'] ?? null,
                'estado' => $motivos ? 'por_aprobar' : 'borrador',
                'aprobacion_motivos' => $motivos ?: null,
                'aprobado_por' => null,   // cualquier edición invalida una aprobación previa
            ] + array_diff_key($totales, ['afecto' => 1]));
            $cot->save();

            $cot->lineas()->delete();
            foreach ($lineas as $i => $l) {
                $cot->lineas()->create(['orden' => $i + 1] + array_diff_key($l, ['bruto' => 1]));
            }

            return $cot->load('lineas');
        });
    }

    /** Vista previa de totales (formulario). */
    public function calcular(array $datos): array
    {
        $cliente = ! empty($datos['cliente_id']) ? Cliente::find($datos['cliente_id']) : null;
        [$lineas, $totales, $descGlobal] = $this->calcularLineas($datos, $cliente, estricto: false);

        return [
            'lineas' => array_map(fn ($l) => ['precio_unitario' => $l['precio_unitario'], 'precio_lista' => $l['precio_lista'], 'monto_item' => $l['monto_item'], 'exento' => $l['exento']], $lineas),
            'totales' => $totales,
            'motivos' => $this->documentos->motivosDeAprobacion($lineas, $descGlobal, $datos['forma_pago'] ?? 'contado', $cliente, $totales['monto_total'], null),
        ];
    }

    public function aprobar(Cotizacion $cot, User $user, ?string $comentario = null): Cotizacion
    {
        if ($cot->estado !== 'por_aprobar') {
            throw ValidationException::withMessages(['cotizacion' => 'Esta cotización no tiene aprobaciones pendientes.']);
        }
        if ($cot->creado_por === $user->id) {
            throw ValidationException::withMessages(['cotizacion' => 'Nadie aprueba su propia cotización: debe aprobarla otra persona.']);
        }
        $cot->forceFill(['estado' => 'borrador', 'aprobado_por' => $user->id])->save();
        $this->auditoria->registrar('cotizacion.aprobar', $cot, null, ['motivos' => $cot->aprobacion_motivos], $comentario);

        return $cot;
    }

    public function enviar(Cotizacion $cot, User $user): Cotizacion
    {
        $this->exigirVigente($cot);
        if ($cot->estado === 'por_aprobar') {
            throw ValidationException::withMessages(['cotizacion' => 'Falta la aprobación: '.implode('; ', $cot->aprobacion_motivos ?? [])]);
        }
        if ($cot->estado !== 'borrador') {
            throw ValidationException::withMessages(['cotizacion' => 'Solo se envía una cotización en borrador.']);
        }
        $cot->forceFill(['estado' => 'enviada'])->save();

        return $cot;
    }

    public function aceptar(Cotizacion $cot, User $user): Cotizacion
    {
        $this->exigirVigente($cot);
        if ($cot->estado !== 'enviada') {
            throw ValidationException::withMessages(['cotizacion' => 'Solo se acepta una cotización enviada.']);
        }
        $cot->forceFill(['estado' => 'aceptada'])->save();

        return $cot;
    }

    public function rechazar(Cotizacion $cot, User $user, string $motivo): Cotizacion
    {
        if (! in_array($cot->estado, ['borrador', 'por_aprobar', 'enviada', 'aceptada'], true)) {
            throw ValidationException::withMessages(['cotizacion' => 'Esta cotización ya está cerrada.']);
        }
        $cot->forceFill(['estado' => 'rechazada', 'motivo_rechazo' => mb_substr(trim($motivo), 0, 200)])->save();

        return $cot;
    }

    /**
     * Convierte una cotización aceptada (o enviada y vigente) en un borrador de
     * factura (33) o boleta (39) con los mismos precios y descuentos. Si la
     * cotización fue aprobada, la aprobación de descuentos se hereda; un cupo
     * de crédito excedido se vuelve a aprobar, porque la deuda pudo cambiar.
     */
    public function convertir(Cotizacion $cotizacion, int $tipo, User $user): Documento
    {
        if (! in_array($tipo, [33, 39], true)) {
            throw ValidationException::withMessages(['tipo_dte' => 'Una cotización se convierte en factura o boleta.']);
        }

        return DB::transaction(function () use ($cotizacion, $tipo, $user) {
            /** @var Cotizacion $cot */
            $cot = Cotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $cot->load('lineas');
            if ($cot->estado === 'enviada') {
                $this->exigirVigente($cot);
            } elseif ($cot->estado !== 'aceptada') {
                throw ValidationException::withMessages(['cotizacion' => 'Solo se convierte una cotización enviada o aceptada.']);
            }

            $tasa = (string) $this->parametros->valor('tributario.tasa_iva');
            $lineas = $cot->lineas->map(fn ($l) => [
                'producto_id' => $l->producto_id,
                'codigo' => $l->codigo,
                'descripcion' => $l->descripcion,
                'cantidad' => (string) $l->cantidad,
                'unidad' => $l->unidad,
                // En boleta el precio va con IVA incluido.
                'precio_unitario' => $tipo === 39 && ! $l->exento ? (string) $this->calc->precioConIva((string) $l->precio_unitario, $tasa) : (string) $l->precio_unitario,
                'precio_manual' => true,   // el precio cotizado se respeta aunque la lista haya cambiado
                'descuento_pct' => (string) $l->descuento_pct,
                'descuento_monto' => (float) $l->descuento_pct > 0 ? null : ($l->descuento_monto ?: null),
                'exento' => $l->exento,
            ])->all();

            $doc = $this->documentos->guardar([
                'tipo_dte' => $tipo,
                'cliente_id' => $cot->cliente_id,
                'local_id' => $cot->local_id,
                'forma_pago' => $cot->forma_pago,
                'descuento_global_pct' => (string) $cot->descuento_global_pct,
                'observaciones' => trim("Cotización N° {$cot->numero}. ".($cot->observaciones ?? '')),
                'cotizacion_id' => $cot->id,
                'lineas' => $lineas,
            ], $user);

            $motivosCupo = array_filter($doc->aprobacion_motivos ?? [], fn ($m) => str_starts_with($m, 'Cupo'));
            if ($doc->estado === 'por_aprobar' && $cot->aprobado_por && ! $motivosCupo) {
                $doc->forceFill(['estado' => 'borrador', 'aprobado_por' => $cot->aprobado_por])->save();
                $this->auditoria->registrar('documento.aprobacion_heredada', $doc, null, ['cotizacion' => $cot->numero, 'motivos' => $doc->aprobacion_motivos]);
            }

            $cot->forceFill(['estado' => 'convertida', 'documento_id' => $doc->id])->save();
            $this->auditoria->registrar('cotizacion.convertir', $cot, null, ['documento_id' => $doc->id, 'tipo_dte' => $tipo]);

            return $doc;
        });
    }

    /* ───────────────────────── Internos ───────────────────────── */

    /** @return array{0: list<array>, 1: array, 2: string} */
    private function calcularLineas(array $datos, ?Cliente $cliente, bool $estricto): array
    {
        $tasa = (string) $this->parametros->valor('tributario.tasa_iva');
        $lineas = $this->documentos->armarLineas(33, $datos['lineas'] ?? [], $cliente, $tasa, estricto: $estricto);
        $descGlobal = (string) ($datos['descuento_global_pct'] ?? 0) ?: '0';

        try {
            $totales = $this->calc->documento(33, $lineas, $descGlobal, $tasa);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lineas' => $e->getMessage()]);
        }

        return [$lineas, $totales, $descGlobal];
    }

    private function exigirVigente(Cotizacion $cot): void
    {
        if ($cot->vencida()) {
            throw ValidationException::withMessages(['cotizacion' => 'La cotización venció el '.$cot->valida_hasta->format('d-m-Y').': edítala para renovar la vigencia.']);
        }
    }

    private function siguienteNumero(): int
    {
        $empresa = app(\App\Modules\Core\Tenancy\Tenancy::class)->id();
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["cotizaciones:{$empresa}"]);

        return (int) Cotizacion::max('numero') + 1;
    }
}
