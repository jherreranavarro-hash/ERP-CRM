<?php

namespace App\Modules\Facturacion;

use App\Models\User;
use App\Modules\Caja\Models\TurnoCaja;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\Facturacion\Eventos\DocumentoEmitido;
use App\Modules\Facturacion\Jobs\EnviarDocumentoSii;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\DocumentoReferencia;
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Facturacion\Models\Pago;
use App\Support\Rut;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Ciclo de vida de los documentos tributarios: borrador → (aprobación) →
 * emitido → pagado, con NC/ND. Todo cálculo ocurre aquí, en el backend.
 */
class Documentos
{
    /** Medios que se cobran en el mesón y entran a la cuadratura del turno. */
    public const MEDIOS_PRESENCIALES = ['efectivo', 'debito', 'credito'];

    public function __construct(
        private Calculadora $calc,
        private Parametros $parametros,
        private Auditoria $auditoria,
        private Tenancy $tenancy,
    ) {}

    /* ───────────────────────── Borradores ───────────────────────── */

    /**
     * Crea o reemplaza un borrador. Las líneas se recalculan siempre desde
     * cantidad y precio; el precio de lista lo decide el backend.
     *
     * @param  array{tipo_dte:int, cliente_id?:?int, local_id?:?int, forma_pago?:string, descuento_global_pct?:string|int|float, observaciones?:?string,
     *               lineas:list<array>, referencias?:list<array>}  $datos
     */
    public function guardar(array $datos, User $user, ?Documento $doc = null): Documento
    {
        $this->exigirModuloActivo();
        if ($doc && ! $doc->esBorrador()) {
            throw ValidationException::withMessages(['documento' => 'Solo se edita un documento en borrador.']);
        }

        $tipo = (int) $datos['tipo_dte'];
        $this->exigirTipoHabilitado($tipo);
        $cliente = ! empty($datos['cliente_id']) ? Cliente::findOrFail($datos['cliente_id']) : null;
        $tasa = (string) $this->parametros->valor('tributario.tasa_iva');
        $ivaIncluido = $this->ivaIncluido($tipo, $datos['referencias'] ?? []);
        $lineas = $this->armarLineas($tipo, $datos['lineas'] ?? [], $cliente, $tasa, ivaIncluido: $ivaIncluido);
        $descGlobal = (string) ($datos['descuento_global_pct'] ?? 0);

        try {
            $totales = $this->calc->documento($tipo, $lineas, $descGlobal, $tasa, $ivaIncluido);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lineas' => $e->getMessage()]);
        }

        $formaPago = $datos['forma_pago'] ?? 'contado';
        // Una NC solo rebaja montos: no pasa por las aprobaciones comerciales.
        $motivos = $tipo === 61 ? [] : $this->motivosDeAprobacion($lineas, $descGlobal, $formaPago, $cliente, $totales['monto_total'], $doc);

        return DB::transaction(function () use ($doc, $datos, $user, $tipo, $cliente, $tasa, $lineas, $descGlobal, $totales, $formaPago, $motivos, $ivaIncluido) {
            $doc ??= new Documento(['creado_por' => $user->id, 'cotizacion_id' => $datos['cotizacion_id'] ?? null, 'orden_venta_id' => $datos['orden_venta_id'] ?? null]);
            $doc->fill([
                'tipo_dte' => $tipo,
                'cliente_id' => $cliente?->id,
                // Sin local explícito: el del usuario, o el primer local de venta de la empresa (el stock vive en un local).
                'local_id' => $datos['local_id'] ?? $doc->local_id ?? $user->membresiaActual()?->local_id ?? Local::where('activo', true)->orderBy('es_bodega')->orderBy('id')->value('id'),
                'forma_pago' => $formaPago,
                'fecha_emision' => now()->toDateString(),
                'fecha_vencimiento' => null,
                'descuento_global_pct' => $descGlobal,
                'tasa_iva' => $tasa,
                'iva_incluido' => $ivaIncluido,
                'observaciones' => $datos['observaciones'] ?? null,
                'estado' => $motivos ? 'por_aprobar' : 'borrador',
                'aprobacion_motivos' => $motivos ?: null,
                'aprobado_por' => null,          // cualquier edición invalida una aprobación previa
            ] + array_diff_key($totales, ['afecto' => 1]) + ['saldo' => 0]);
            $doc->save();

            $doc->lineas()->delete();
            foreach ($lineas as $i => $l) {
                $doc->lineas()->create(['orden' => $i + 1] + array_diff_key($l, ['bruto' => 1]));
            }

            if ($doc->esNota()) {
                $doc->referencias()->delete();
                foreach ($datos['referencias'] ?? [] as $r) {
                    $referido = Documento::whereNotNull('folio')->findOrFail($r['documento_referido_id']);
                    $doc->referencias()->create([
                        'documento_referido_id' => $referido->id,
                        'tipo_dte_ref' => $referido->tipo_dte,
                        'folio_ref' => $referido->folio,
                        'fecha_ref' => $referido->fecha_emision,
                        'codigo' => $r['codigo'] ?? null,
                        'razon' => trim((string) ($r['razon'] ?? '')),
                    ]);
                }
            }

            return $doc->load('lineas', 'referencias');
        });
    }

    /** Vista previa de totales sin guardar (para el formulario). */
    public function calcular(array $datos): array
    {
        $tipo = (int) $datos['tipo_dte'];
        $cliente = ! empty($datos['cliente_id']) ? Cliente::find($datos['cliente_id']) : null;
        $tasa = (string) $this->parametros->valor('tributario.tasa_iva');
        $ivaIncluido = $this->ivaIncluido($tipo, $datos['referencias'] ?? []);
        $lineas = $this->armarLineas($tipo, $datos['lineas'] ?? [], $cliente, $tasa, estricto: false, ivaIncluido: $ivaIncluido);

        try {
            $totales = $this->calc->documento($tipo, $lineas, (string) ($datos['descuento_global_pct'] ?? 0), $tasa, $ivaIncluido);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lineas' => $e->getMessage()]);
        }

        return [
            'lineas' => array_map(fn ($l) => ['precio_unitario' => $l['precio_unitario'], 'precio_lista' => $l['precio_lista'], 'monto_item' => $l['monto_item'], 'exento' => $l['exento']], $lineas),
            'totales' => $totales,
            'motivos' => $this->motivosDeAprobacion($lineas, (string) ($datos['descuento_global_pct'] ?? 0), $datos['forma_pago'] ?? 'contado', $cliente, $totales['monto_total'], null),
        ];
    }

    /** Prepara una NC (61) o ND (56) en borrador sobre un documento emitido. */
    public function prepararNota(Documento $referido, int $tipo, int $codigo, string $razon, User $user, ?array $lineas = null, ?string $descuentoGlobal = null): Documento
    {
        if (! in_array($tipo, [56, 61], true)) {
            throw new InvalidArgumentException('Solo notas de crédito o débito.');
        }
        if ($referido->esBorrador() || $referido->esNota()) {
            throw ValidationException::withMessages(['documento' => 'La nota se emite sobre una factura o boleta ya emitida.']);
        }

        $acreditable = $referido->saldoAcreditable();
        if ($tipo === 61 && $codigo === 1 && $acreditable !== $referido->monto_total) {
            throw ValidationException::withMessages(['codigo' => 'El documento ya tiene notas de crédito: para el resto usa "Corrige montos" por hasta $'.number_format($acreditable, 0, ',', '.').'.']);
        }
        $lineas ??= match ($codigo) {
            // Anulación: copia exacta de las líneas, así el total es el mismo al peso.
            1 => $referido->lineas->map(fn ($l) => [
                'producto_id' => $l->producto_id, 'codigo' => $l->codigo, 'descripcion' => $l->descripcion, 'cantidad' => (string) $l->cantidad, 'unidad' => $l->unidad,
                'precio_unitario' => (string) $l->precio_unitario, 'descuento_pct' => (string) $l->descuento_pct, 'descuento_monto' => $l->descuento_monto,
                'exento' => $l->exento, 'precio_manual' => true,
            ])->all(),
            // Corrige texto: monto cero.
            2 => [['descripcion' => mb_substr($razon, 0, 80), 'cantidad' => '1', 'precio_unitario' => '0', 'exento' => false, 'precio_manual' => true]],
            default => throw ValidationException::withMessages(['lineas' => 'Indica las líneas a corregir.']),
        };

        return $this->guardar([
            'tipo_dte' => $tipo,
            'cliente_id' => $referido->cliente_id,
            'local_id' => $referido->local_id,
            'forma_pago' => 'contado',
            'descuento_global_pct' => $descuentoGlobal ?? ($codigo === 1 ? (string) $referido->descuento_global_pct : 0),
            'lineas' => $lineas,
            'referencias' => [['documento_referido_id' => $referido->id, 'codigo' => $codigo, 'razon' => $razon]],
        ], $user);
    }

    /* ───────────────────────── Aprobación ───────────────────────── */

    public function aprobar(Documento $doc, User $user, ?string $comentario = null): Documento
    {
        if ($doc->estado !== 'por_aprobar') {
            throw ValidationException::withMessages(['documento' => 'Este documento no tiene aprobaciones pendientes.']);
        }
        if ($doc->creado_por === $user->id) {
            throw ValidationException::withMessages(['documento' => 'Nadie aprueba su propio documento: debe aprobarlo otra persona.']);
        }

        $doc->forceFill(['estado' => 'borrador', 'aprobado_por' => $user->id])->save();
        $this->auditoria->registrar('documento.aprobar', $doc, null, ['motivos' => $doc->aprobacion_motivos], $comentario);

        return $doc;
    }

    /* ───────────────────────── Emisión ───────────────────────── */

    /**
     * Emite: valida, recalcula desde las líneas guardadas con la fecha de hoy y
     * asigna folio en la misma transacción (FOR UPDATE). Si algo falla, el
     * rollback devuelve el folio. El envío al SII va por cola tras el commit.
     */
    public function emitir(Documento $documento, User $user): Documento
    {
        $this->exigirModuloActivo();

        $doc = DB::transaction(function () use ($documento, $user) {
            /** @var Documento $doc */
            $doc = Documento::whereKey($documento->id)->lockForUpdate()->firstOrFail();
            $doc->load('lineas', 'referencias.referido');

            if ($doc->estado === 'por_aprobar') {
                throw ValidationException::withMessages(['documento' => 'Falta la aprobación: '.implode('; ', $doc->aprobacion_motivos ?? [])]);
            }
            if ($doc->estado !== 'borrador') {
                throw ValidationException::withMessages(['documento' => 'El documento ya fue emitido.']);
            }
            $this->exigirTipoHabilitado($doc->tipo_dte);

            $cliente = $doc->cliente_id ? Cliente::findOrFail($doc->cliente_id) : null;
            $this->validarReceptor($doc, $cliente);
            $this->validarLineasGuardadas($doc);

            $hoy = now()->startOfDay();
            $tasa = (string) $this->parametros->valor('tributario.tasa_iva');
            $totales = $this->calc->documento($doc->tipo_dte, $doc->lineas->map(fn ($l) => ['monto_item' => $l->monto_item, 'exento' => $l->exento])->all(), (string) $doc->descuento_global_pct, $tasa, $doc->iva_incluido);

            $codigoNota = $doc->referencias->first()?->codigo;
            $permiteCero = $doc->tipo_dte === 52 || ($doc->tipo_dte === 61 && $codigoNota === 2);
            if ($totales['monto_total'] <= 0 && ! $permiteCero) {
                throw ValidationException::withMessages(['documento' => 'El total debe ser mayor que cero.']);
            }

            $vencimiento = null;
            if ($doc->forma_pago === 'credito') {
                if (! $cliente?->tiene_credito) {
                    throw ValidationException::withMessages(['forma_pago' => 'Venta a crédito solo para clientes con cuenta corriente.']);
                }
                $vencimiento = $hoy->copy()->addDays(max(1, $cliente->plazo_dias));
                if ($this->parametros->valor('aprobaciones.cupo_excedido') && ! $doc->aprobado_por
                    && $cliente->deuda() + $totales['monto_total'] > $cliente->cupo_credito) {
                    throw ValidationException::withMessages(['forma_pago' => 'El cliente excede su cupo de crédito: requiere aprobación.']);
                }
            }

            $referido = null;
            if ($doc->esNota()) {
                $ref = $doc->referencias->first();
                if (! $ref || trim($ref->razon) === '' || ! $ref->codigo) {
                    throw ValidationException::withMessages(['referencias' => 'La nota requiere documento de referencia, código y razón.']);
                }
                $referido = Documento::whereKey($ref->documento_referido_id)->lockForUpdate()->firstOrFail();
                if ($doc->tipo_dte === 61) {
                    $acreditable = $referido->saldoAcreditable();
                    if ($totales['monto_total'] > $acreditable) {
                        throw ValidationException::withMessages(['documento' => 'La nota de crédito ($'.number_format($totales['monto_total'], 0, ',', '.').') supera el saldo acreditable ($'.number_format($acreditable, 0, ',', '.').').']);
                    }
                    if ($ref->codigo === 1 && ($acreditable !== $referido->monto_total || $totales['monto_total'] !== $acreditable)) {
                        throw ValidationException::withMessages(['documento' => 'Una anulación es por el total disponible ($'.number_format($acreditable, 0, ',', '.').').']);
                    }
                }
            }

            $folio = $this->tomarFolio($doc->tipo_dte);

            $doc->forceFill([
                'folio' => $folio,
                'estado' => 'emitido',
                'fecha_emision' => $hoy->toDateString(),
                'fecha_vencimiento' => $vencimiento?->toDateString(),
                'tasa_iva' => $tasa,
                'receptor_rut' => $cliente?->rut,
                'receptor_razon_social' => $cliente?->razon_social,
                'receptor_giro' => $cliente?->giro,
                'receptor_direccion' => $cliente?->direccion,
                'receptor_comuna' => $cliente?->comuna,
                'saldo' => in_array($doc->tipo_dte, [52, 61], true) ? 0 : $totales['monto_total'],
                'estado_sii' => 'pendiente',
                'emitido_por' => $user->id,
                'emitido_at' => now(),
            ] + array_diff_key($totales, ['afecto' => 1]))->save();

            if ($referido && $doc->tipo_dte === 61) {
                $this->aplicarNotaCredito($doc, $referido, $doc->referencias->first()->codigo);
            }

            // Otros módulos reaccionan aquí (Inventario descuenta stock); un error revierte la emisión.
            event(new DocumentoEmitido($doc, $user));

            $this->auditoria->registrar('documento.emitir', $doc, null, ['tipo_dte' => $doc->tipo_dte, 'folio' => $folio, 'total' => $doc->monto_total]);

            return $doc;
        });

        EnviarDocumentoSii::dispatch($doc->empresa_id, $doc->id);

        return $doc->fresh(['lineas', 'referencias']);
    }

    /* ───────────────────────── Cobro ───────────────────────── */

    /**
     * Cobra un documento. Los pagos presenciales (efectivo y tarjetas) exigen un
     * turno de caja abierto del usuario y quedan en él para la cuadratura; el
     * resto (transferencia, cheque) se asocia al turno si hay uno abierto.
     */
    public function registrarPago(Documento $documento, int $monto, string $metodo, User $user, ?string $referencia = null): Pago
    {
        $medios = $this->parametros->valor('caja.medios', $documento->local_id);
        if (! in_array($metodo, $medios, true)) {
            throw ValidationException::withMessages(['metodo' => 'Medio de pago no habilitado.']);
        }
        if ($metodo === 'efectivo' && $monto > (int) $this->parametros->valor('caja.efectivo_maximo', $documento->local_id)) {
            throw ValidationException::withMessages(['monto' => 'Supera el monto máximo en efectivo por venta.']);
        }

        return DB::transaction(function () use ($documento, $monto, $metodo, $user, $referencia) {
            // Bloquea el turno: un cierre concurrente espera a este pago, o este pago ve el turno ya cerrado.
            $turno = TurnoCaja::where('usuario_id', $user->id)->where('estado', 'abierto')->lockForUpdate()->first();
            if (! $turno && in_array($metodo, self::MEDIOS_PRESENCIALES, true) && $this->parametros->valor('caja.cierre_por_turno', $documento->local_id)) {
                throw ValidationException::withMessages(['metodo' => 'Abre tu turno de caja antes de cobrar en efectivo o con tarjeta.']);
            }

            $doc = Documento::whereKey($documento->id)->lockForUpdate()->firstOrFail();
            if ($doc->estado !== 'emitido') {
                throw ValidationException::withMessages(['documento' => 'Solo se cobran documentos emitidos con saldo.']);
            }
            if ($monto <= 0 || $monto > $doc->saldo) {
                throw ValidationException::withMessages(['monto' => 'El monto debe estar entre $1 y el saldo ($'.number_format($doc->saldo, 0, ',', '.').').']);
            }

            $pago = Pago::create(['documento_id' => $doc->id, 'turno_id' => $turno?->id, 'monto' => $monto, 'metodo' => $metodo, 'fecha' => now()->toDateString(), 'referencia' => $referencia, 'registrado_por' => $user->id]);
            $saldo = $doc->saldo - $monto;
            $doc->forceFill(['saldo' => $saldo, 'estado' => $saldo === 0 ? 'pagado' : 'emitido'])->save();

            return $pago;
        });
    }

    /* ───────────────────────── Internos ───────────────────────── */

    private function tomarFolio(int $tipo): int
    {
        $rango = FolioRango::query()
            ->where('tipo_dte', $tipo)->where('activo', true)
            ->whereColumn('siguiente', '<=', 'hasta')
            ->where(fn ($q) => $q->whereNull('vence')->orWhere('vence', '>=', now()->toDateString()))
            ->orderBy('desde')
            ->lockForUpdate()
            ->first();

        if (! $rango) {
            throw ValidationException::withMessages(['documento' => 'No quedan folios disponibles para '.Documento::TIPOS[$tipo].'. Carga un nuevo rango (CAF).']);
        }

        $folio = $rango->siguiente;
        $rango->forceFill(['siguiente' => $folio + 1])->saveQuietly();

        $restantes = $rango->hasta - $folio;
        if ($restantes <= (int) $this->parametros->valor('folios.aviso_restantes')) {
            $this->auditoria->registrar('folios.quedan_pocos', $rango, null, ['tipo_dte' => $tipo, 'restantes' => $restantes]);
        }

        return $folio;
    }

    private function aplicarNotaCredito(Documento $nc, Documento $referido, int $codigo): void
    {
        $nuevoSaldo = $referido->saldo - $nc->monto_total;
        if ($nuevoSaldo < 0) {
            // Saldo a favor del cliente: el documento queda en 0 y el excedente va a la bitácora.
            $this->auditoria->registrar('nota_credito.saldo_a_favor', $referido, null, ['monto' => -$nuevoSaldo, 'nota_credito' => $nc->folio, 'cliente_id' => $referido->cliente_id]);
            $nuevoSaldo = 0;
        }

        $estado = $codigo === 1 ? 'anulado' : ($referido->estado === 'emitido' && $nuevoSaldo === 0 ? 'pagado' : $referido->estado);
        $referido->forceFill(['saldo' => $nuevoSaldo, 'estado' => $estado])->save();
    }

    /**
     * @return list<array{producto_id:?int, codigo:?string, descripcion:string, cantidad:string, unidad:string, precio_unitario:string, precio_lista:?string,
     *                    descuento_pct:string, descuento_monto:int, monto_item:int, bruto:int, exento:bool}>
     */
    public function armarLineas(int $tipo, array $entrada, ?Cliente $cliente, string $tasa, bool $estricto = true, bool $ivaIncluido = false): array
    {
        $max = (int) $this->parametros->valor('tributario.max_lineas');
        $largo = (int) $this->parametros->valor('tributario.largo_descripcion');
        if ($estricto && (count($entrada) < 1 || count($entrada) > $max)) {
            throw ValidationException::withMessages(['lineas' => "El documento debe tener entre 1 y {$max} líneas."]);
        }

        $productos = Producto::whereIn('id', array_filter(array_column($entrada, 'producto_id')))->get()->keyBy('id');
        $lista = $cliente?->lista_precio ?? 'meson';
        $conIva = $ivaIncluido;
        $exentoDoc = in_array($tipo, Calculadora::EXENTOS, true);

        $salida = [];
        foreach (array_values($entrada) as $i => $l) {
            $n = $i + 1;
            $producto = ! empty($l['producto_id']) ? ($productos[$l['producto_id']] ?? throw ValidationException::withMessages(["lineas.{$i}.producto_id" => "Línea {$n}: producto no encontrado."])) : null;

            $precioLista = null;
            if ($producto) {
                $neto = $producto->precioNetoPara($lista);
                $precioLista = $conIva && ! $producto->exento ? (string) $this->calc->precioConIva($neto, $tasa) : $this->normalizarDecimal($neto);
            }
            $manual = isset($l['precio_unitario']) && $l['precio_unitario'] !== '' && $l['precio_unitario'] !== null;
            if (! $manual && $precioLista === null) {
                if (! $estricto) {
                    continue;
                }
                throw ValidationException::withMessages(["lineas.{$i}.precio_unitario" => "Línea {$n}: indica el precio."]);
            }
            $precio = $manual ? $this->normalizarDecimal((string) $l['precio_unitario']) : $precioLista;

            $descripcion = trim((string) ($l['descripcion'] ?? $producto?->nombre ?? ''));
            if ($estricto && ($descripcion === '' || mb_strlen($descripcion) > $largo)) {
                throw ValidationException::withMessages(["lineas.{$i}.descripcion" => "Línea {$n}: la descripción debe tener entre 1 y {$largo} caracteres."]);
            }

            $pct = $this->normalizarDecimal((string) ($l['descuento_pct'] ?? '0') ?: '0');
            $descMonto = isset($l['descuento_monto']) && $l['descuento_monto'] !== '' && $l['descuento_monto'] !== null ? (int) $l['descuento_monto'] : null;

            try {
                $calc = $this->calc->linea((string) ($l['cantidad'] ?? '0'), $precio, $pct, $descMonto);
            } catch (InvalidArgumentException $e) {
                if (! $estricto) {
                    continue;
                }
                throw ValidationException::withMessages(["lineas.{$i}" => "Línea {$n}: ".$e->getMessage()]);
            }

            $salida[] = [
                'producto_id' => $producto?->id,
                'codigo' => $producto?->sku ?? ($l['codigo'] ?? null),
                'descripcion' => mb_substr($descripcion, 0, $largo),
                'cantidad' => $this->normalizarDecimal((string) $l['cantidad']),
                'unidad' => $producto?->unidad ?? ($l['unidad'] ?? 'UN'),
                'precio_unitario' => $precio,
                'precio_lista' => $manual && empty($l['precio_manual']) ? $precioLista : ($manual ? null : $precioLista),
                'descuento_pct' => $pct,
                'descuento_monto' => $calc['descuento'],
                'monto_item' => $calc['monto_item'],
                'bruto' => $calc['bruto'],
                'exento' => $exentoDoc || ($producto?->exento ?? (bool) ($l['exento'] ?? false)),
            ];
        }

        return $salida;
    }

    /** @return list<string> */
    public function motivosDeAprobacion(array $lineas, string $descGlobal, string $formaPago, ?Cliente $cliente, int $total, ?Documento $doc): array
    {
        $motivos = [];
        $max = BigDecimal::of((string) $this->parametros->valor('aprobaciones.descuento_max_pct'));

        foreach ($lineas as $i => $l) {
            $pctEfectivo = $l['bruto'] > 0 ? BigDecimal::of($l['descuento_monto'])->multipliedBy(100)->dividedBy($l['bruto'], 2, \Brick\Math\RoundingMode::HALF_UP) : BigDecimal::zero();
            if ($pctEfectivo->isGreaterThan($max)) {
                $motivos[] = 'Línea '.($i + 1).": descuento de {$pctEfectivo} % sobre el máximo de {$max} %";
            }
            if ($this->parametros->valor('aprobaciones.precio_bajo_lista') && $l['precio_lista'] !== null
                && BigDecimal::of($l['precio_unitario'])->isLessThan(BigDecimal::of($l['precio_lista']))) {
                $motivos[] = 'Línea '.($i + 1).": precio manual bajo la lista ({$l['precio_unitario']} < {$l['precio_lista']})";
            }
        }
        if (BigDecimal::of($descGlobal)->isGreaterThan($max)) {
            $motivos[] = "Descuento global de {$descGlobal} % sobre el máximo de {$max} %";
        }
        if ($formaPago === 'credito' && $cliente?->tiene_credito && $this->parametros->valor('aprobaciones.cupo_excedido')) {
            $deuda = $cliente->deuda();
            if ($deuda + $total > $cliente->cupo_credito) {
                $motivos[] = 'Cupo de crédito excedido: deuda $'.number_format($deuda, 0, ',', '.').' + este documento $'.number_format($total, 0, ',', '.').' > cupo $'.number_format($cliente->cupo_credito, 0, ',', '.');
            }
        }

        return $motivos;
    }

    private function validarReceptor(Documento $doc, ?Cliente $cliente): void
    {
        $generico = Rut::normalizar((string) $this->parametros->valor('tributario.rut_generico'));

        // NC/ND sobre una boleta: el receptor puede ser consumidor final, igual que la boleta.
        $sobreBoleta = $doc->esNota() && in_array($doc->referencias->first()?->tipo_dte_ref, [39, 41], true);

        if (in_array($doc->tipo_dte, Documento::CON_RECEPTOR, true) && ! $sobreBoleta) {
            if (! $cliente) {
                throw ValidationException::withMessages(['cliente_id' => 'Este documento requiere un cliente receptor.']);
            }
            if (Rut::normalizar($cliente->rut) === $generico) {
                throw ValidationException::withMessages(['cliente_id' => 'No se puede emitir a nombre del RUT genérico.']);
            }
            if (! $cliente->receptorCompleto()) {
                throw ValidationException::withMessages(['cliente_id' => 'Completa los datos del receptor: RUT, razón social, giro, dirección y comuna.']);
            }
        }
        if ($cliente && ! Rut::valido($cliente->rut)) {
            throw ValidationException::withMessages(['cliente_id' => 'El RUT del cliente no es válido.']);
        }
    }

    private function validarLineasGuardadas(Documento $doc): void
    {
        $max = (int) $this->parametros->valor('tributario.max_lineas');
        $n = $doc->lineas->count();
        if ($n < 1 || $n > $max) {
            throw ValidationException::withMessages(['lineas' => "El documento debe tener entre 1 y {$max} líneas."]);
        }
    }

    private function exigirTipoHabilitado(int $tipo): void
    {
        if (! in_array((string) $tipo, $this->parametros->valor('tributario.tipos_dte'), true)) {
            throw ValidationException::withMessages(['tipo_dte' => 'Tipo de documento no habilitado para esta empresa.']);
        }
    }

    private function exigirModuloActivo(): void
    {
        if (! $this->parametros->valor('modulos.facturacion')) {
            throw ValidationException::withMessages(['documento' => 'La facturación electrónica no está activa en esta empresa.']);
        }
    }

    private function normalizarDecimal(string $v): string
    {
        $v = str_replace(',', '.', trim($v));
        if (! is_numeric($v)) {
            throw ValidationException::withMessages(['lineas' => "Valor numérico inválido: {$v}"]);
        }

        return (string) BigDecimal::of($v)->toScale(6, \Brick\Math\RoundingMode::HALF_UP)->stripTrailingZeros();
    }

    /** Boleta, o NC/ND que refiere a una boleta: montos con IVA incluido. */
    private function ivaIncluido(int $tipo, array $referencias): bool
    {
        if (in_array($tipo, Calculadora::CON_IVA_INCLUIDO, true)) {
            return true;
        }
        if (in_array($tipo, [56, 61], true) && ($id = $referencias[0]['documento_referido_id'] ?? null)) {
            return (bool) Documento::whereKey($id)->value('iva_incluido');
        }

        return false;
    }
}
