<?php

namespace App\Modules\Inventario;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Inventario\Escuchas\MoverStockAlEmitir;
use App\Modules\Inventario\Models\Devolucion;
use App\Modules\Inventario\Models\DevolucionLinea;
use App\Modules\Facturacion\Calculo\Calculadora;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Devoluciones de venta vinculadas al documento original. Al aprobarse (por
 * otra persona, salvo bajo el umbral) se genera y emite la nota de crédito:
 * anulación (código 1) si se devuelve todo, o "corrige montos" (código 3) con
 * las líneas devueltas. Al emitirse la NC el stock reingresa al costo con que salió.
 */
class Devoluciones
{
    public function __construct(private Documentos $documentos, private Parametros $parametros, private Auditoria $auditoria) {}

    /**
     * @param  list<array{documento_linea_id:int, cantidad:string|int|float, reingresa?:bool}>  $lineas
     */
    public function solicitar(Documento $doc, array $lineas, string $motivo, string $detalle, User $user): Devolucion
    {
        if (! in_array($doc->tipo_dte, MoverStockAlEmitir::VENTAS, true) || ! in_array($doc->estado, ['emitido', 'pagado'], true)) {
            throw ValidationException::withMessages(['documento' => 'Solo se devuelve una factura o boleta emitida y vigente.']);
        }
        if (! array_key_exists($motivo, Devolucion::MOTIVOS)) {
            throw ValidationException::withMessages(['motivo' => 'Motivo no válido.']);
        }
        if (trim($detalle) === '') {
            throw ValidationException::withMessages(['detalle' => 'Describe qué pasó.']);
        }

        return DB::transaction(function () use ($doc, $lineas, $motivo, $detalle, $user) {
            // Serializa las devoluciones de un mismo documento: así no se devuelve dos veces lo mismo.
            Documento::whereKey($doc->id)->lockForUpdate()->first();
            $originales = $doc->lineas()->get()->keyBy('id');
            $devuelto = $this->devuelto($doc);
            $elegidas = [];
            $montoLineas = BigDecimal::zero();
            foreach ($lineas as $i => $l) {
                $cant = BigDecimal::of((string) ($l['cantidad'] ?? 0));
                if ($cant->isZero()) {
                    continue;
                }
                $orig = $originales[$l['documento_linea_id']] ?? throw ValidationException::withMessages(["lineas.{$i}" => 'La línea no pertenece al documento.']);
                $disponible = BigDecimal::of((string) $orig->cantidad)->minus($devuelto[$orig->id] ?? 0);
                if ($cant->isNegative() || $cant->isGreaterThan($disponible)) {
                    throw ValidationException::withMessages(["lineas.{$i}" => "{$orig->descripcion}: se pueden devolver hasta ".Inventario::cifra($disponible).'.']);
                }
                $elegidas[] = ['linea' => $orig, 'cantidad' => $cant, 'reingresa' => (bool) ($l['reingresa'] ?? true)];
                $montoLineas = $montoLineas->plus(BigDecimal::of($orig->monto_item)->multipliedBy($cant)->dividedBy((string) $orig->cantidad, 6, RoundingMode::HALF_UP));
            }
            if (! $elegidas) {
                throw ValidationException::withMessages(['lineas' => 'Indica qué se devuelve y cuánto.']);
            }

            // Estimación proporcional al total del documento (con descuento global e IVA); la NC la calcula exacta.
            $sumaItems = max(1, (int) $originales->sum('monto_item'));
            $estimado = min($doc->saldoAcreditable(), Calculadora::red($montoLineas->multipliedBy($doc->monto_total)->dividedBy($sumaItems, 6, RoundingMode::HALF_UP)));
            if ($estimado <= 0) {
                throw ValidationException::withMessages(['documento' => 'El documento ya no tiene saldo para acreditar.']);
            }

            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['devoluciones:'.app(Tenancy::class)->id()]);
            $dev = Devolucion::create([
                'numero' => (int) Devolucion::max('numero') + 1, 'documento_id' => $doc->id, 'local_id' => $doc->local_id,
                'motivo' => $motivo, 'detalle' => mb_substr(trim($detalle), 0, 300), 'estado' => 'solicitada', 'monto_estimado' => $estimado, 'solicitado_por' => $user->id,
            ]);
            foreach ($elegidas as $e) {
                $dev->lineas()->create(['documento_linea_id' => $e['linea']->id, 'producto_id' => $e['linea']->producto_id, 'cantidad' => (string) $e['cantidad'], 'reingresa' => $e['reingresa']]);
            }

            if ($estimado <= (int) $this->parametros->valor('inventario.devolucion_aprobacion_monto')) {
                $this->resolver($dev, $user, null);
            }

            return $dev->fresh('lineas');
        });
    }

    public function aprobar(Devolucion $devolucion, User $user): Devolucion
    {
        return DB::transaction(function () use ($devolucion, $user) {
            $dev = Devolucion::whereKey($devolucion->id)->lockForUpdate()->firstOrFail();
            if ($dev->estado !== 'solicitada') {
                throw ValidationException::withMessages(['devolucion' => 'Esta devolución ya está resuelta.']);
            }
            if ($dev->solicitado_por === $user->id) {
                throw ValidationException::withMessages(['devolucion' => 'Nadie aprueba su propia devolución: debe aprobarla otra persona.']);
            }

            return $this->resolver($dev, $user, $user->id);
        });
    }

    public function rechazar(Devolucion $devolucion, User $user, string $motivo): Devolucion
    {
        if ($devolucion->estado !== 'solicitada') {
            throw ValidationException::withMessages(['devolucion' => 'Esta devolución ya está resuelta.']);
        }
        if ($devolucion->solicitado_por === $user->id) {
            throw ValidationException::withMessages(['devolucion' => 'Nadie rechaza su propia devolución: debe revisarla otra persona.']);
        }
        $devolucion->forceFill(['estado' => 'rechazada', 'aprobado_por' => $user->id, 'motivo_rechazo' => mb_substr(trim($motivo), 0, 200)])->save();

        return $devolucion;
    }

    /** Cantidad ya devuelta (o en trámite) por línea del documento. @return array<int, string> */
    public function devuelto(Documento $doc): array
    {
        return DevolucionLinea::query()->join('devoluciones as d', 'd.id', '=', 'devolucion_lineas.devolucion_id')
            ->where('d.documento_id', $doc->id)->whereIn('d.estado', ['solicitada', 'aprobada'])
            ->groupBy('devolucion_lineas.documento_linea_id')->selectRaw('devolucion_lineas.documento_linea_id as id, sum(devolucion_lineas.cantidad) as cantidad')
            ->pluck('cantidad', 'id')->map(fn ($v) => (string) $v)->all();
    }

    /** Genera la NC, la enlaza (para que la emisión reingrese el stock), la emite y cierra la devolución. */
    private function resolver(Devolucion $dev, User $user, ?int $aprobador): Devolucion
    {
        $doc = Documento::with('lineas')->findOrFail($dev->documento_id);
        $dev->load('lineas');
        $porLinea = $dev->lineas->keyBy('documento_linea_id');
        $razon = 'Devolución N° '.$dev->numero.': '.Devolucion::MOTIVOS[$dev->motivo];

        // ¿Se devuelve todo lo vendido y no hay otras notas? Entonces anula (código 1).
        $previas = $this->devuelto($doc);
        $total = $doc->saldoAcreditable() === $doc->monto_total && $doc->lineas->every(
            fn ($l) => BigDecimal::of($previas[$l->id] ?? '0')->isEqualTo((string) $l->cantidad)
        ) && $doc->lineas->every(fn ($l) => $porLinea->has($l->id) && BigDecimal::of((string) $porLinea[$l->id]->cantidad)->isEqualTo((string) $l->cantidad));

        if ($total) {
            $nc = $this->documentos->prepararNota($doc, 61, 1, $razon, $user);
        } else {
            $lineas = $doc->lineas->filter(fn ($l) => $porLinea->has($l->id))->map(function ($l) use ($porLinea) {
                $cant = BigDecimal::of((string) $porLinea[$l->id]->cantidad);
                $proporcion = $cant->dividedBy((string) $l->cantidad, 6, RoundingMode::HALF_UP);
                $pct = BigDecimal::of((string) $l->descuento_pct);

                return [
                    'producto_id' => $l->producto_id, 'codigo' => $l->codigo, 'descripcion' => $l->descripcion, 'cantidad' => (string) $cant, 'unidad' => $l->unidad,
                    'precio_unitario' => (string) $l->precio_unitario, 'exento' => $l->exento, 'precio_manual' => true,
                    'descuento_pct' => (string) $pct,
                    // Descuento por monto fijo: proporcional a lo devuelto.
                    'descuento_monto' => $pct->isZero() && $l->descuento_monto > 0 ? Calculadora::red($proporcion->multipliedBy($l->descuento_monto)) : null,
                ];
            })->values()->all();
            $nc = $this->documentos->prepararNota($doc, 61, 3, $razon, $user, $lineas, (string) $doc->descuento_global_pct);
        }

        $dev->forceFill(['nota_credito_id' => $nc->id])->save();
        $nc = $this->documentos->emitir($nc, $user);
        $dev->forceFill(['estado' => 'aprobada', 'aprobado_por' => $aprobador])->save();
        $this->auditoria->registrar('devolucion.aprobar', $dev, null, ['numero' => $dev->numero, 'documento' => $doc->nombre(), 'nota_credito' => $nc->nombre(), 'monto' => $nc->monto_total]);

        return $dev;
    }
}
