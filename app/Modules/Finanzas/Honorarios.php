<?php

namespace App\Modules\Finanzas;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Facturacion\Calculo\Calculadora;
use App\Modules\Finanzas\Models\Honorario;
use App\Support\Rut;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Boletas de honorarios recibidas: retención según la tasa vigente, asiento al registrar y al pagar. */
class Honorarios
{
    public function __construct(private Parametros $parametros, private AsientosAutomaticos $asientos, private Contabilidad $contabilidad, private Auditoria $auditoria) {}

    /** @param array{rut:string, nombre:string, folio:int, fecha:string, descripcion:string, bruto:int, centro_costo_id?:?int} $d */
    public function registrar(array $d, User $user): Honorario
    {
        $rut = Rut::normalizar($d['rut']);
        if (! $rut || ! Rut::valido($rut)) {
            throw ValidationException::withMessages(['rut' => 'El RUT del prestador no es válido.']);
        }
        if (Honorario::where(['rut' => $rut, 'folio' => (int) $d['folio']])->exists()) {
            throw ValidationException::withMessages(['folio' => 'Esta boleta ya está registrada.']);
        }
        $bruto = (int) $d['bruto'];
        if ($bruto <= 0) {
            throw ValidationException::withMessages(['bruto' => 'El monto bruto debe ser mayor que cero.']);
        }
        $tasa = (string) $this->parametros->valor('tributario.retencion_honorarios_pct');
        $retencion = Calculadora::red(BigDecimal::of($bruto)->multipliedBy($tasa)->dividedBy(100, 6));

        return DB::transaction(function () use ($d, $user, $rut, $bruto, $tasa, $retencion) {
            $h = Honorario::create([
                'rut' => $rut, 'nombre' => mb_substr(trim($d['nombre']), 0, 100), 'folio' => (int) $d['folio'], 'fecha' => $d['fecha'],
                'descripcion' => mb_substr(trim($d['descripcion']), 0, 200), 'bruto' => $bruto, 'tasa_retencion' => $tasa, 'retencion' => $retencion,
                'liquido' => $bruto - $retencion, 'estado' => 'por_pagar', 'centro_costo_id' => $d['centro_costo_id'] ?? null, 'registrado_por' => $user->id,
            ]);
            $this->asientos->honorario($h, $user);

            return $h;
        });
    }

    public function pagar(Honorario $honorario, string $fecha, User $user): Honorario
    {
        return DB::transaction(function () use ($honorario, $fecha, $user) {
            $h = Honorario::whereKey($honorario->id)->lockForUpdate()->firstOrFail();
            if ($h->estado !== 'por_pagar') {
                throw ValidationException::withMessages(['honorario' => 'La boleta no está por pagar.']);
            }
            if ($fecha < $h->fecha->toDateString()) {
                throw ValidationException::withMessages(['fecha_pago' => 'El pago no puede ser anterior a la boleta.']);
            }
            $h->forceFill(['estado' => 'pagada', 'fecha_pago' => $fecha])->save();
            $this->asientos->pagoHonorario($h, $user);

            return $h;
        });
    }

    /** Anula una boleta por pagar (por ejemplo, anulada por el prestador en el SII): revierte su asiento. */
    public function anular(Honorario $honorario, string $motivo, User $user): Honorario
    {
        return DB::transaction(function () use ($honorario, $motivo, $user) {
            $h = Honorario::whereKey($honorario->id)->lockForUpdate()->firstOrFail();
            if ($h->estado !== 'por_pagar') {
                throw ValidationException::withMessages(['honorario' => 'Solo se anula una boleta por pagar.']);
            }
            $h->forceFill(['estado' => 'anulada'])->save();
            $this->contabilidad->revertirOrigen('honorario', $h->id, $user, 'Boleta anulada: '.$motivo);
            $this->auditoria->registrar('honorario.anular', $h, null, ['folio' => $h->folio, 'motivo' => $motivo]);

            return $h;
        });
    }
}
