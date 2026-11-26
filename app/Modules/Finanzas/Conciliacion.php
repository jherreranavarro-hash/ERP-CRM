<?php

namespace App\Modules\Finanzas;

use App\Models\User;
use App\Modules\Compras\Models\PagoProveedor;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Models\Pago;
use App\Modules\Finanzas\Models\Cartola;
use App\Modules\Finanzas\Models\CuentaBancaria;
use App\Modules\Finanzas\Models\CuentaContable;
use App\Modules\Finanzas\Models\MovimientoBancario;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Conciliación bancaria: importa cartolas en el formato habitual de los bancos
 * chilenos (CSV con fecha, descripción, N° de documento, cargos y abonos, o un
 * solo monto con signo) y cruza cada movimiento con los cobros y pagos
 * registrados por monto exacto, fecha cercana y, si hay empate, la glosa.
 */
class Conciliacion
{
    public const MAX_FILAS = 5000;

    /** Medios de cobro que pasan por el banco (el efectivo no). */
    public const COBROS_BANCARIOS = ['transferencia', 'cheque', 'debito', 'credito'];

    public function __construct(private Parametros $parametros, private Auditoria $auditoria, private Contabilidad $contabilidad) {}

    /** Importa una cartola y concilia automáticamente lo que calza. */
    public function importar(CuentaBancaria $cuenta, string $contenido, string $archivo, User $user): Cartola
    {
        $filas = $this->leer($contenido);
        if (! $filas) {
            throw ValidationException::withMessages(['archivo' => 'La cartola no tiene movimientos reconocibles. Debe traer columnas de fecha, descripción y cargo/abono (o monto).']);
        }

        return DB::transaction(function () use ($cuenta, $filas, $archivo, $user) {
            $cartola = Cartola::create(['cuenta_bancaria_id' => $cuenta->id, 'archivo' => mb_substr($archivo, 0, 150), 'importado_por' => $user->id,
                'desde' => min(array_column($filas, 'fecha')), 'hasta' => max(array_column($filas, 'fecha'))]);
            $nuevos = 0;
            $duplicados = 0;
            $vistas = [];
            foreach ($filas as $f) {
                // Dos movimientos idénticos el mismo día son legítimos: se distinguen por su orden en la cartola.
                $base = implode('|', [$f['fecha'], mb_strtolower($f['glosa']), $f['documento'], $f['monto']]);
                $vistas[$base] = ($vistas[$base] ?? 0) + 1;
                $huella = hash('sha256', $base.'|'.$vistas[$base]);
                $creado = MovimientoBancario::firstOrCreate(['cuenta_bancaria_id' => $cuenta->id, 'huella' => $huella],
                    ['cartola_id' => $cartola->id, 'fecha' => $f['fecha'], 'glosa' => $f['glosa'], 'documento' => $f['documento'], 'monto' => $f['monto']]);
                $creado->wasRecentlyCreated ? $nuevos++ : $duplicados++;
            }
            $conciliados = $this->conciliarAutomatico($cuenta, $user, $cartola->id);
            $cartola->forceFill(['movimientos' => $nuevos, 'duplicados' => $duplicados, 'conciliados' => $conciliados])->save();
            $this->auditoria->registrar('cartola.importar', $cartola, null, ['cuenta' => $cuenta->nombre, 'nuevos' => $nuevos, 'duplicados' => $duplicados, 'conciliados' => $conciliados]);

            return $cartola;
        });
    }

    /** Cruza los movimientos pendientes con cobros y pagos sin conciliar. Devuelve cuántos concilió. */
    public function conciliarAutomatico(CuentaBancaria $cuenta, User $user, ?int $cartolaId = null): int
    {
        $n = 0;
        $pendientes = MovimientoBancario::where('cuenta_bancaria_id', $cuenta->id)->where('estado', 'pendiente')
            ->when($cartolaId, fn ($q) => $q->where('cartola_id', $cartolaId))->orderBy('fecha')->orderBy('id')->get();
        foreach ($pendientes as $m) {
            $c = $this->candidatos($m);
            $elegido = $c->count() === 1 ? $c->first() : $c->first(fn ($x) => $x->referencia && $this->mencionado($x->referencia, $m));
            if ($elegido) {
                $this->enlazar($m, $elegido, $user, 'automatica');
                $n++;
            }
        }

        return $n;
    }

    /**
     * Cobros (abonos) o pagos a proveedor (cargos) del mismo monto, dentro de la tolerancia de días, aún sin conciliar.
     *
     * @return Collection<int, Pago|PagoProveedor>
     */
    public function candidatos(MovimientoBancario $m, bool $sinTolerancia = false): Collection
    {
        $dias = $sinTolerancia ? 60 : (int) $this->parametros->valor('conciliacion.dias_tolerancia');
        $desde = $m->fecha->copy()->subDays($dias)->toDateString();
        $hasta = $m->fecha->copy()->addDays($dias)->toDateString();
        $cerca = fn ($x) => abs($x->fecha->diffInDays($m->fecha, false));

        if ($m->monto > 0) {
            return Pago::with('documento:id,tipo_dte,folio,receptor_razon_social')->whereIn('metodo', self::COBROS_BANCARIOS)->where('monto', $m->monto)
                ->whereBetween('fecha', [$desde, $hasta])
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('movimientos_bancarios')->whereColumn('movimientos_bancarios.pago_id', 'pagos.id'))
                ->get()->sortBy($cerca)->values();
        }

        return PagoProveedor::with('factura:id,tipo_dte,folio,proveedor_id', 'factura.proveedor:id,razon_social')->where('estado', 'aplicado')->where('metodo', '<>', 'efectivo')
            ->where('monto', -$m->monto)->whereBetween('fecha', [$desde, $hasta])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('movimientos_bancarios')->whereColumn('movimientos_bancarios.pago_proveedor_id', 'pagos_proveedor.id'))
            ->get()->sortBy($cerca)->values();
    }

    /** Conciliación manual con un cobro o un pago a proveedor elegido. */
    public function conciliar(MovimientoBancario $movimiento, string $tipo, int $id, User $user): MovimientoBancario
    {
        return DB::transaction(function () use ($movimiento, $tipo, $id, $user) {
            $m = $this->pendiente($movimiento);
            $destino = match ($tipo) {
                'pago' => Pago::find($id),
                'pago_proveedor' => PagoProveedor::where('estado', 'aplicado')->find($id),
                default => null,
            } ?? throw ValidationException::withMessages(['conciliacion' => 'No se encontró el cobro o pago a conciliar.']);
            if (($tipo === 'pago') !== ($m->monto > 0) || abs($m->monto) !== $destino->monto) {
                throw ValidationException::withMessages(['conciliacion' => 'El monto o el sentido (abono/cargo) no coincide con el movimiento.']);
            }

            return $this->enlazar($m, $destino, $user, 'manual');
        });
    }

    /** Contabiliza un movimiento sin contraparte registrada (comisiones, intereses, aportes) contra la cuenta indicada. */
    public function contabilizar(MovimientoBancario $movimiento, int $cuentaId, string $glosa, User $user, ?int $centroCostoId = null): MovimientoBancario
    {
        return DB::transaction(function () use ($movimiento, $cuentaId, $glosa, $user, $centroCostoId) {
            $m = $this->pendiente($movimiento);
            $m->loadMissing('cuentaBancaria');
            $banco = $m->cuentaBancaria->cuenta_contable_id;
            if ($cuentaId === $banco || ! CuentaContable::whereKey($cuentaId)->exists()) {
                throw ValidationException::withMessages(['cuenta_id' => 'Elige la cuenta de contrapartida (no la del banco).']);
            }
            $v = abs($m->monto);
            $asiento = $this->contabilidad->registrar($m->fecha->toDateString(), trim($glosa) ?: $m->glosa, [
                ['cuenta_id' => $banco, 'debe' => $m->monto > 0 ? $v : 0, 'haber' => $m->monto < 0 ? $v : 0],
                ['cuenta_id' => $cuentaId, 'debe' => $m->monto < 0 ? $v : 0, 'haber' => $m->monto > 0 ? $v : 0, 'centro_costo_id' => $centroCostoId],
            ], $user);
            $m->forceFill(['estado' => 'conciliado', 'asiento_id' => $asiento->id, 'forma' => 'manual', 'conciliado_por' => $user->id, 'conciliado_at' => now()])->save();

            return $m;
        });
    }

    public function ignorar(MovimientoBancario $movimiento, User $user): MovimientoBancario
    {
        return DB::transaction(function () use ($movimiento, $user) {
            $m = $this->pendiente($movimiento);
            $m->forceFill(['estado' => 'ignorado', 'conciliado_por' => $user->id, 'conciliado_at' => now()])->save();

            return $m;
        });
    }

    /** Deshace una conciliación con cobro o pago (las contabilizadas se corrigen con un reverso del asiento). */
    public function deshacer(MovimientoBancario $movimiento, User $user): MovimientoBancario
    {
        return DB::transaction(function () use ($movimiento, $user) {
            $m = MovimientoBancario::whereKey($movimiento->id)->lockForUpdate()->firstOrFail();
            if ($m->estado === 'pendiente') {
                return $m;
            }
            if ($m->asiento_id) {
                $this->contabilidad->revertir($m->asiento()->first(), $user, 'Conciliación deshecha');
            }
            if ($m->pago_id) {
                Pago::whereKey($m->pago_id)->update(['conciliado' => false]);
            }
            $m->forceFill(['estado' => 'pendiente', 'pago_id' => null, 'pago_proveedor_id' => null, 'asiento_id' => null, 'forma' => null, 'conciliado_por' => null, 'conciliado_at' => null])->save();
            $this->auditoria->registrar('conciliacion.deshacer', $m, null, ['movimiento' => $m->id, 'monto' => $m->monto]);

            return $m;
        });
    }

    /**
     * Lee el CSV de la cartola: detecta separador, encabezados, fechas dd/mm/aaaa y montos con puntos de miles.
     *
     * @return list<array{fecha:string, glosa:string, documento:?string, monto:int}>
     */
    public function leer(string $contenido): array
    {
        $contenido = str_starts_with($contenido, "\xEF\xBB\xBF") ? substr($contenido, 3) : $contenido;
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $lineas = preg_split('/\r\n|\r|\n/', trim($contenido));
        $sep = collect([';', ',', "\t"])->sortByDesc(fn ($s) => substr_count($lineas[0] ?? '', $s))->first();

        $cols = null;
        $filas = [];
        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $celdas = array_map(fn ($c) => trim((string) $c), str_getcsv($linea, $sep));
            if ($cols === null) {
                // Las cartolas traen líneas de título antes del encabezado: se busca la fila con "fecha".
                $cols = $this->encabezado($celdas);
                continue;
            }
            $fecha = $this->fecha($celdas[$cols['fecha']] ?? '');
            if (! $fecha) {
                continue;   // totales, saldos o pies de página
            }
            $monto = isset($cols['monto'])
                ? $this->monto($celdas[$cols['monto']] ?? '')
                : $this->monto($celdas[$cols['abono']] ?? '') - abs($this->monto($celdas[$cols['cargo']] ?? ''));
            if ($monto === 0) {
                continue;
            }
            $filas[] = ['fecha' => $fecha, 'glosa' => mb_substr($celdas[$cols['glosa']] ?? '', 0, 200) ?: 'Sin descripción',
                'documento' => isset($cols['documento']) && ($celdas[$cols['documento']] ?? '') !== '' ? mb_substr($celdas[$cols['documento']], 0, 40) : null, 'monto' => $monto];
            if (count($filas) > self::MAX_FILAS) {
                throw ValidationException::withMessages(['archivo' => 'Máximo '.self::MAX_FILAS.' movimientos por cartola.']);
            }
        }

        return $filas;
    }

    private function encabezado(array $celdas): ?array
    {
        $cols = [];
        foreach ($celdas as $i => $c) {
            $c = mb_strtolower($c);
            $c = strtr($c, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', '°' => '', 'º' => '']);
            match (true) {
                ! isset($cols['fecha']) && str_starts_with($c, 'fecha') => $cols['fecha'] = $i,
                ! isset($cols['glosa']) && (str_contains($c, 'descrip') || str_contains($c, 'glosa') || str_contains($c, 'detalle') || str_contains($c, 'concepto')) => $cols['glosa'] = $i,
                ! isset($cols['documento']) && (str_contains($c, 'documento') || str_contains($c, 'n doc') || $c === 'n' || str_contains($c, 'operacion')) => $cols['documento'] = $i,
                ! isset($cols['cargo']) && (str_contains($c, 'cargo') || str_contains($c, 'giro') || str_contains($c, 'debito')) => $cols['cargo'] = $i,
                ! isset($cols['abono']) && (str_contains($c, 'abono') || str_contains($c, 'deposito') || str_contains($c, 'credito')) => $cols['abono'] = $i,
                ! isset($cols['monto']) && (str_contains($c, 'monto') || str_contains($c, 'importe')) => $cols['monto'] = $i,
                default => null,
            };
        }
        $ok = isset($cols['fecha'], $cols['glosa']) && (isset($cols['monto']) || isset($cols['cargo'], $cols['abono']));

        return $ok ? $cols : null;
    }

    private function fecha(string $v): ?string
    {
        $v = trim($v);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd-m-y', 'd.m.Y'] as $f) {
            $d = \DateTimeImmutable::createFromFormat('!'.$f, $v);
            if ($d && $d->format($f) === $v) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** "$ 1.234.567", "-1.234", "1234,00" → pesos enteros. */
    private function monto(string $v): int
    {
        $v = trim(str_replace(['$', ' ', "\u{00A0}"], '', $v));
        if ($v === '' || $v === '-') {
            return 0;
        }
        $neg = str_starts_with($v, '-') || (str_starts_with($v, '(') && str_ends_with($v, ')'));
        $v = trim($v, '-()');
        $v = preg_replace('/,\d{1,2}$/', '', $v);        // decimales con coma
        $v = preg_replace('/[^\d]/', '', $v);

        return $v === '' ? 0 : ($neg ? -1 : 1) * (int) $v;
    }

    private function pendiente(MovimientoBancario $movimiento): MovimientoBancario
    {
        $m = MovimientoBancario::whereKey($movimiento->id)->lockForUpdate()->firstOrFail();
        if ($m->estado !== 'pendiente') {
            throw ValidationException::withMessages(['conciliacion' => 'El movimiento ya está conciliado o ignorado.']);
        }

        return $m;
    }

    private function enlazar(MovimientoBancario $m, Pago|PagoProveedor $x, User $user, string $forma): MovimientoBancario
    {
        $m->forceFill([
            'estado' => 'conciliado', 'pago_id' => $x instanceof Pago ? $x->id : null, 'pago_proveedor_id' => $x instanceof PagoProveedor ? $x->id : null,
            'forma' => $forma, 'conciliado_por' => $user->id, 'conciliado_at' => now(),
        ])->save();
        if ($x instanceof Pago) {
            $x->forceFill(['conciliado' => true])->save();
        }

        return $m;
    }

    private function mencionado(string $referencia, MovimientoBancario $m): bool
    {
        $r = mb_strtolower(trim($referencia));

        return $r !== '' && (str_contains(mb_strtolower($m->glosa), $r) || ($m->documento && str_contains(mb_strtolower($m->documento), $r)));
    }
}
