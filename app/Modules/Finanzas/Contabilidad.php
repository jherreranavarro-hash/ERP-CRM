<?php

namespace App\Modules\Finanzas;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Finanzas\Models\Asiento;
use App\Modules\Finanzas\Models\CentroCosto;
use App\Modules\Finanzas\Models\CuentaContable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Motor de partida doble. Todo asiento cuadra (lo verifica también la base de
 * datos al cerrar la transacción) y es inmutable: se corrige con un reverso.
 * Los módulos operativos generan asientos automáticos idempotentes por origen.
 */
class Contabilidad
{
    /** Plan de cuentas inicial: [código, nombre, tipo, clave para asientos automáticos]. */
    public const PLAN = [
        ['1.1.01', 'Caja', 'activo', 'caja'],
        ['1.1.02', 'Banco', 'activo', 'banco'],
        ['1.1.03', 'Clientes', 'activo', 'clientes'],
        ['1.1.04', 'IVA crédito fiscal', 'activo', 'iva_credito'],
        ['1.1.05', 'Mercaderías', 'activo', 'inventario'],
        ['1.1.06', 'Préstamos al personal', 'activo', 'prestamos_personal'],
        ['1.1.07', 'Anticipos al personal', 'activo', 'anticipos_personal'],
        ['1.2.01', 'Activo fijo', 'activo', null],
        ['2.1.01', 'Proveedores', 'pasivo', 'proveedores'],
        ['2.1.02', 'IVA débito fiscal', 'pasivo', 'iva_debito'],
        ['2.1.03', 'Retención de honorarios por pagar', 'pasivo', 'retencion_honorarios'],
        ['2.1.04', 'Honorarios por pagar', 'pasivo', 'honorarios_por_pagar'],
        ['2.1.05', 'Remuneraciones por pagar', 'pasivo', 'remuneraciones_por_pagar'],
        ['2.1.06', 'Cotizaciones previsionales por pagar', 'pasivo', 'leyes_sociales_por_pagar'],
        ['2.1.07', 'Impuesto único por pagar', 'pasivo', 'impuesto_unico_por_pagar'],
        ['2.1.08', 'Finiquitos por pagar', 'pasivo', 'finiquitos_por_pagar'],
        ['3.1.01', 'Capital', 'patrimonio', 'capital'],
        ['3.1.02', 'Resultados acumulados', 'patrimonio', 'resultados_acumulados'],
        ['4.1.01', 'Ventas', 'ingreso', 'ventas'],
        ['4.1.02', 'Otros ingresos', 'ingreso', 'otros_ingresos'],
        ['5.1.01', 'Costo de ventas', 'gasto', 'costo_ventas'],
        ['5.1.02', 'Honorarios', 'gasto', 'honorarios'],
        ['5.1.03', 'Remuneraciones', 'gasto', 'remuneraciones'],
        ['5.1.06', 'Leyes sociales del empleador', 'gasto', 'leyes_sociales'],
        ['5.1.07', 'Indemnizaciones', 'gasto', 'indemnizaciones'],
        ['5.1.04', 'Gastos generales', 'gasto', 'gastos_generales'],
        ['5.1.05', 'Gastos bancarios', 'gasto', 'gastos_bancarios'],
    ];

    public function __construct(private Parametros $parametros, private Auditoria $auditoria, private Tenancy $tenancy) {}

    /** Crea el plan de cuentas inicial de la empresa si aún no tiene uno. */
    public function asegurarPlan(): void
    {
        // Crea el plan si no existe y agrega las cuentas automáticas que módulos nuevos necesitan.
        $claves = array_filter(array_column(self::PLAN, 3));
        if (CuentaContable::whereIn('clave', $claves)->count() === count($claves)) {
            return;
        }
        DB::transaction(function () use ($claves) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['plan_cuentas:'.$this->tenancy->id()]);
            $nuevo = ! CuentaContable::exists();
            $hay = CuentaContable::whereIn('clave', $claves)->pluck('clave')->all();
            $codigos = CuentaContable::pluck('codigo')->all();
            foreach (self::PLAN as [$codigo, $nombre, $tipo, $clave]) {
                if ($nuevo || ($clave && ! in_array($clave, $hay, true) && ! in_array($codigo, $codigos, true))) {
                    CuentaContable::create(compact('codigo', 'nombre', 'tipo', 'clave'));
                }
            }
        });
    }

    public function cuenta(string $clave): CuentaContable
    {
        $this->asegurarPlan();

        return CuentaContable::where('clave', $clave)->first()
            ?? throw ValidationException::withMessages(['cuenta' => "Falta la cuenta contable para «{$clave}» en el plan de cuentas."]);
    }

    /**
     * Registra un asiento (manual por defecto).
     *
     * @param  list<array{cuenta_id:int, debe?:int, haber?:int, centro_costo_id?:?int, glosa?:?string}>  $lineas
     */
    public function registrar(string $fecha, string $glosa, array $lineas, User $user, string $tipo = 'manual', ?array $origen = null, ?int $revierte = null): Asiento
    {
        $glosa = trim($glosa);
        if ($glosa === '') {
            throw ValidationException::withMessages(['glosa' => 'Describe el asiento.']);
        }
        $f = CarbonImmutable::parse($fecha);
        if ($tipo !== 'automatico') {
            $this->exigirPeriodoAbierto($f);
        }
        $lineas = array_values(array_filter($lineas, fn ($l) => (int) ($l['debe'] ?? 0) !== 0 || (int) ($l['haber'] ?? 0) !== 0));
        if (count($lineas) < 2) {
            throw ValidationException::withMessages(['lineas' => 'Un asiento tiene al menos dos líneas con monto.']);
        }
        $cuentas = CuentaContable::whereIn('id', array_column($lineas, 'cuenta_id'))->get()->keyBy('id');
        $centros = CentroCosto::whereIn('id', array_filter(array_column($lineas, 'centro_costo_id')))->where('activo', true)->pluck('id')->all();
        $debe = 0;
        $haber = 0;
        foreach ($lineas as $i => $l) {
            $d = (int) ($l['debe'] ?? 0);
            $h = (int) ($l['haber'] ?? 0);
            $c = $cuentas[$l['cuenta_id']] ?? null;
            if (! $c || (! $c->activa && $tipo === 'manual')) {
                throw ValidationException::withMessages(["lineas.{$i}.cuenta_id" => 'Línea '.($i + 1).': cuenta no válida o inactiva.']);
            }
            if ($d < 0 || $h < 0 || ($d > 0 && $h > 0)) {
                throw ValidationException::withMessages(["lineas.{$i}" => 'Línea '.($i + 1).': va al debe o al haber, con monto positivo.']);
            }
            if (! empty($l['centro_costo_id']) && ! in_array((int) $l['centro_costo_id'], $centros, true)) {
                throw ValidationException::withMessages(["lineas.{$i}.centro_costo_id" => 'Línea '.($i + 1).': centro de costo no válido.']);
            }
            $debe += $d;
            $haber += $h;
        }
        if ($debe !== $haber) {
            throw ValidationException::withMessages(['lineas' => 'El asiento no cuadra: debe $'.number_format($debe, 0, ',', '.').' y haber $'.number_format($haber, 0, ',', '.').'.']);
        }

        return DB::transaction(function () use ($f, $glosa, $lineas, $user, $tipo, $origen, $revierte, $debe) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['asientos:'.$this->tenancy->id()]);
            $a = Asiento::create([
                'numero' => (int) Asiento::max('numero') + 1, 'fecha' => $f->toDateString(), 'glosa' => mb_substr($glosa, 0, 200), 'tipo' => $tipo,
                'origen_tipo' => $origen[0] ?? null, 'origen_id' => $origen[1] ?? null, 'revierte_id' => $revierte, 'total' => $debe, 'creado_por' => $user->id,
            ]);
            foreach ($lineas as $l) {
                $a->lineas()->create([
                    'cuenta_id' => (int) $l['cuenta_id'], 'centro_costo_id' => $l['centro_costo_id'] ?? null, 'debe' => (int) ($l['debe'] ?? 0), 'haber' => (int) ($l['haber'] ?? 0),
                    'glosa' => isset($l['glosa']) ? mb_substr(trim((string) $l['glosa']), 0, 200) : null,
                ]);
            }
            if ($tipo === 'manual') {
                $this->auditoria->registrar('asiento.registrar', $a, null, ['numero' => $a->numero, 'total' => $a->total]);
            }

            return $a;
        });
    }

    /**
     * Asiento automático por origen, con cuentas por clave. Idempotente: si el origen ya tiene asiento, lo devuelve.
     *
     * @param  list<array{0:string, 1:int, 2:int}>  $lineas  [clave, debe, haber]
     */
    public function automatico(string $origenTipo, int $origenId, string $fecha, string $glosa, array $lineas, User $user, ?int $centroCostoId = null): ?Asiento
    {
        $existente = Asiento::where(['tipo' => 'automatico', 'origen_tipo' => $origenTipo, 'origen_id' => $origenId])->first();
        if ($existente) {
            return $existente;
        }
        // Compensa claves repetidas y descarta las que quedan en cero.
        $neto = [];
        foreach ($lineas as [$clave, $d, $h]) {
            $neto[$clave] = ($neto[$clave] ?? 0) + $d - $h;
        }
        $filas = [];
        foreach ($neto as $clave => $v) {
            if ($v !== 0) {
                $cuenta = $this->cuenta($clave);
                $gasto = in_array($cuenta->tipo, ['gasto', 'ingreso'], true);
                $filas[] = ['cuenta_id' => $cuenta->id, 'debe' => max($v, 0), 'haber' => max(-$v, 0), 'centro_costo_id' => $gasto ? $centroCostoId : null];
            }
        }
        if (count($filas) < 2) {
            return null;
        }

        return $this->registrar($fecha, $glosa, $filas, $user, 'automatico', [$origenTipo, $origenId]);
    }

    /** Revierte un asiento con otro de signo contrario, fechado hoy (o en la fecha indicada). */
    public function revertir(Asiento $asiento, User $user, string $motivo, ?string $fecha = null): Asiento
    {
        if ($asiento->tipo === 'reverso') {
            throw ValidationException::withMessages(['asiento' => 'Un reverso no se revierte: registra un asiento nuevo.']);
        }
        if (Asiento::where('revierte_id', $asiento->id)->exists()) {
            throw ValidationException::withMessages(['asiento' => 'Este asiento ya fue revertido.']);
        }
        if (trim($motivo) === '') {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo del reverso.']);
        }
        $lineas = $asiento->lineas()->get()->map(fn ($l) => ['cuenta_id' => $l->cuenta_id, 'centro_costo_id' => $l->centro_costo_id, 'debe' => $l->haber, 'haber' => $l->debe, 'glosa' => $l->glosa])->all();
        $r = $this->registrar($fecha ?? now()->toDateString(), "Reverso del asiento N° {$asiento->numero}: ".trim($motivo), $lineas, $user, 'reverso', [$asiento->origen_tipo, $asiento->origen_id], $asiento->id);
        $this->auditoria->registrar('asiento.revertir', $asiento, null, ['numero' => $asiento->numero, 'reverso' => $r->numero, 'motivo' => $motivo]);

        return $r;
    }

    /** Revierte el asiento automático de un origen, si existe y no está revertido. */
    public function revertirOrigen(string $origenTipo, int $origenId, User $user, string $motivo): ?Asiento
    {
        $a = Asiento::where(['tipo' => 'automatico', 'origen_tipo' => $origenTipo, 'origen_id' => $origenId])->first();
        if (! $a || Asiento::where('revierte_id', $a->id)->exists()) {
            return null;
        }

        return $this->revertir($a, $user, $motivo);
    }

    /**
     * Sumas de debe y haber por cuenta en un período (o hasta una fecha), opcionalmente por centro de costo.
     *
     * @return Collection<int, object{cuenta_id:int, codigo:string, nombre:string, tipo:string, debe:int, haber:int, saldo:int}>
     */
    public function saldos(?string $desde, string $hasta, ?int $centroCostoId = null): Collection
    {
        $this->asegurarPlan();
        $sumas = DB::table('asiento_lineas as l')->join('asientos as a', 'a.id', '=', 'l.asiento_id')
            ->where('l.empresa_id', $this->tenancy->id())
            ->when($desde, fn ($q) => $q->where('a.fecha', '>=', $desde))->where('a.fecha', '<=', $hasta)
            ->when($centroCostoId, fn ($q) => $q->where('l.centro_costo_id', $centroCostoId))
            ->groupBy('l.cuenta_id')->selectRaw('l.cuenta_id, sum(l.debe) as debe, sum(l.haber) as haber')->get()->keyBy('cuenta_id');

        return CuentaContable::orderBy('codigo')->get()->map(function (CuentaContable $c) use ($sumas) {
            $d = (int) ($sumas[$c->id]->debe ?? 0);
            $h = (int) ($sumas[$c->id]->haber ?? 0);

            return (object) ['cuenta_id' => $c->id, 'codigo' => $c->codigo, 'nombre' => $c->nombre, 'tipo' => $c->tipo, 'debe' => $d, 'haber' => $h,
                'saldo' => in_array($c->tipo, CuentaContable::DEUDORAS, true) ? $d - $h : $h - $d];
        });
    }

    private function exigirPeriodoAbierto(CarbonImmutable $fecha): void
    {
        $cierre = trim((string) $this->parametros->valor('contabilidad.cierre_hasta'));
        if ($cierre !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $cierre) && $fecha->toDateString() <= $cierre) {
            throw ValidationException::withMessages(['fecha' => 'El período está cerrado hasta el '.CarbonImmutable::parse($cierre)->format('d-m-Y').': usa una fecha posterior.']);
        }
    }
}
