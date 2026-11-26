<?php

namespace App\Modules\RRHH;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Finanzas\Contabilidad;
use App\Modules\RRHH\Models\Anticipo;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Nomina;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Anticipos de sueldo (quincena): con tope configurable, se contabilizan al pagarse y se descuentan en la nómina del mes. */
class Anticipos
{
    public function __construct(private Parametros $parametros, private Contabilidad $contabilidad, private Auditoria $auditoria) {}

    public function registrar(Empleado $e, array $d, User $user): Anticipo
    {
        $e->loadMissing('contratoVigente');
        $c = $e->contratoVigente ?? throw ValidationException::withMessages(['empleado_id' => 'La persona no tiene un contrato vigente.']);
        $periodo = substr((string) $d['fecha'], 0, 7);
        $monto = (int) $d['monto'];

        return DB::transaction(function () use ($e, $c, $d, $periodo, $monto, $user) {
            Empleado::whereKey($e->id)->lockForUpdate()->first();
            if (Nomina::where('periodo', $periodo)->where('estado', '<>', 'borrador')->exists()) {
                throw ValidationException::withMessages(['fecha' => 'La nómina de ese mes ya está aprobada.']);
            }
            $tope = (int) floor($c->sueldo_base * (float) $this->parametros->valor('nomina.anticipo_max_pct') / 100);
            $previos = (int) Anticipo::where('empleado_id', $e->id)->where('periodo', $periodo)->where('estado', '<>', 'anulado')->sum('monto');
            if ($monto <= 0 || $monto + $previos > $tope) {
                throw ValidationException::withMessages(['monto' => 'Supera el anticipo máximo del mes ($'.number_format($tope, 0, ',', '.').($previos ? ', ya hay $'.number_format($previos, 0, ',', '.') : '').').']);
            }
            $a = Anticipo::create(['empleado_id' => $e->id, 'periodo' => $periodo, 'fecha' => $d['fecha'], 'monto' => $monto, 'estado' => 'registrado', 'registrado_por' => $user->id]);
            $this->contabilidad->automatico('anticipo', $a->id, $d['fecha'], "Anticipo de sueldo {$periodo} · {$e->nombreCompleto()}", [
                ['anticipos_personal', $monto, 0], ['banco', 0, $monto],
            ], $user);
            $this->auditoria->registrar('anticipo.registrar', $a, null, ['empleado' => $e->nombreCompleto(), 'monto' => $monto]);

            return $a;
        });
    }

    public function anular(Anticipo $anticipo, User $user): Anticipo
    {
        return DB::transaction(function () use ($anticipo, $user) {
            $a = Anticipo::whereKey($anticipo->id)->lockForUpdate()->firstOrFail();
            if ($a->estado !== 'registrado') {
                throw ValidationException::withMessages(['anticipo' => 'Solo se anula un anticipo aún no descontado.']);
            }
            $a->forceFill(['estado' => 'anulado'])->save();
            $this->contabilidad->revertirOrigen('anticipo', $a->id, $user, 'Anticipo anulado');
            $this->auditoria->registrar('anticipo.anular', $a);

            return $a;
        });
    }
}
