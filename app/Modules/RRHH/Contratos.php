<?php

namespace App\Modules\RRHH;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\RRHH\Integraciones\FirmaElectronica;
use App\Modules\RRHH\Models\Contrato;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\PlantillaContrato;
use App\Support\Rut;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contratos de trabajo: se redactan desde una plantilla, se envían a firma
 * electrónica y quedan vigentes al firmarse (el anterior vigente se termina:
 * así la ficha guarda el historial de cargos y condiciones).
 */
class Contratos
{
    public function __construct(private Parametros $parametros, private Auditoria $auditoria, private FirmaElectronica $firma, private Tenancy $tenancy) {}

    public function guardar(Empleado $e, array $d, User $user, ?Contrato $c = null): Contrato
    {
        if ($c && $c->estado !== 'borrador') {
            throw ValidationException::withMessages(['contrato' => 'Solo se edita un contrato en borrador.']);
        }
        if (! array_key_exists($d['tipo'], Contrato::TIPOS)) {
            throw ValidationException::withMessages(['tipo' => 'Tipo de contrato no válido.']);
        }
        if ($d['tipo'] !== 'indefinido' && empty($d['fecha_termino'])) {
            throw ValidationException::withMessages(['fecha_termino' => 'Un contrato a plazo o por obra necesita fecha de término.']);
        }
        if ($d['tipo'] === 'indefinido') {
            $d['fecha_termino'] = null;
        }
        $max = (int) $this->parametros->valor('nomina.jornada_maxima');
        if ((int) $d['jornada_horas'] > $max) {
            throw ValidationException::withMessages(['jornada_horas' => "La jornada ordinaria no puede superar {$max} horas semanales."]);
        }
        // Sueldo mínimo proporcional a la jornada (jornada parcial: art. 44).
        $minimo = (int) ceil((int) $this->parametros->valor('nomina.imm') * (int) $d['jornada_horas'] / $max);
        if ((int) $d['sueldo_base'] < $minimo) {
            throw ValidationException::withMessages(['sueldo_base' => 'El sueldo base no puede ser menor al ingreso mínimo para esa jornada ($'.number_format($minimo, 0, ',', '.').').']);
        }
        $plantilla = ! empty($d['plantilla_id']) ? PlantillaContrato::where('activa', true)->findOrFail($d['plantilla_id']) : null;

        $c ??= new Contrato(['empleado_id' => $e->id, 'creado_por' => $user->id, 'estado' => 'borrador']);
        $c->fill(collect($d)->only(['tipo', 'cargo', 'jornada_horas', 'sueldo_base', 'gratificacion', 'colacion', 'movilizacion', 'comision_pct', 'fecha_inicio', 'fecha_termino', 'plantilla_id'])->all());
        $c->texto = $plantilla ? $this->redactar($plantilla, $e, $c) : ($d['texto'] ?? $c->texto);
        $c->save();

        return $c;
    }

    /** Reemplaza {{variable}} con los datos del contrato. */
    public function redactar(PlantillaContrato $p, Empleado $e, Contrato $c): string
    {
        $empresa = $this->tenancy->empresa();
        $fecha = fn ($f) => $f ? CarbonImmutable::parse($f)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') : 'indefinida';
        $vars = [
            'empresa' => $empresa?->razon_social, 'rut_empresa' => Rut::formatear($empresa?->rut), 'nombre' => $e->nombreCompleto(), 'rut' => Rut::formatear($e->rut),
            'domicilio' => trim(($e->direccion ?? '').', '.($e->comuna ?? ''), ', '), 'cargo' => $c->cargo, 'jornada' => $c->jornada_horas.' horas semanales',
            'sueldo' => '$'.number_format($c->sueldo_base, 0, ',', '.'), 'sueldo_palabras' => Palabras::pesos($c->sueldo_base),
            'colacion' => '$'.number_format($c->colacion, 0, ',', '.'), 'movilizacion' => '$'.number_format($c->movilizacion, 0, ',', '.'),
            'fecha_inicio' => $fecha($c->fecha_inicio), 'fecha_termino' => $fecha($c->fecha_termino), 'tipo' => mb_strtolower(Contrato::TIPOS[$c->tipo]),
            'local' => $e->local?->nombre ?? '', 'fecha' => $fecha(now()),
        ];

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? $m[0]), $p->cuerpo);
    }

    public function enviarAFirma(Contrato $contrato, User $user): Contrato
    {
        return DB::transaction(function () use ($contrato, $user) {
            $c = Contrato::with('empleado')->whereKey($contrato->id)->lockForUpdate()->firstOrFail();
            if ($c->estado !== 'borrador') {
                throw ValidationException::withMessages(['contrato' => 'El contrato ya fue enviado a firma.']);
            }
            if (trim((string) $c->texto) === '') {
                throw ValidationException::withMessages(['contrato' => 'El contrato no tiene texto: elige una plantilla.']);
            }
            $id = $this->firma->solicitar("Contrato de trabajo · {$c->empleado->nombreCompleto()}", $c->texto, [['rut' => $c->empleado->rut, 'email' => $c->empleado->email]]);
            $c->forceFill(['estado' => 'por_firmar', 'firma_id' => $id])->save();
            $this->auditoria->registrar('contrato.enviar_firma', $c, null, ['empleado' => $c->empleado->nombreCompleto(), 'firma_id' => $id]);

            return $c;
        });
    }

    /** Consulta el proveedor de firma; si ya está firmado, el contrato pasa a vigente y termina el anterior. */
    public function sincronizarFirma(Contrato $contrato, User $user): Contrato
    {
        return DB::transaction(function () use ($contrato, $user) {
            $c = Contrato::with('empleado')->whereKey($contrato->id)->lockForUpdate()->firstOrFail();
            if ($c->estado !== 'por_firmar') {
                return $c;
            }
            $estado = $this->firma->estado((string) $c->firma_id);
            if ($estado === 'rechazado') {
                $c->forceFill(['estado' => 'borrador', 'firma_id' => null])->save();
                throw ValidationException::withMessages(['contrato' => 'La persona rechazó la firma: el contrato volvió a borrador.']);
            }
            if ($estado !== 'firmado') {
                return $c;
            }
            Contrato::where('empleado_id', $c->empleado_id)->where('estado', 'vigente')->whereKeyNot($c->id)
                ->update(['estado' => 'terminado', 'fecha_termino' => DB::raw("LEAST(COALESCE(fecha_termino, DATE '9999-12-31'), DATE '".CarbonImmutable::parse($c->fecha_inicio)->subDay()->toDateString()."')")]);
            $c->forceFill(['estado' => 'vigente', 'firmado_at' => now()])->save();
            $c->empleado->forceFill(['cargo' => $c->cargo])->save();
            $this->auditoria->registrar('contrato.firmado', $c, null, ['empleado' => $c->empleado->nombreCompleto()]);

            return $c;
        });
    }

    /** Contratos a plazo u obra que vencen dentro del aviso configurado. */
    public function porVencer(): \Illuminate\Support\Collection
    {
        $dias = (int) $this->parametros->valor('rrhh.dias_aviso_contrato');

        return Contrato::with('empleado:id,nombres,apellidos,rut')->where('estado', 'vigente')->whereNotNull('fecha_termino')
            ->where('fecha_termino', '<=', now()->addDays($dias)->toDateString())->orderBy('fecha_termino')->get();
    }
}
