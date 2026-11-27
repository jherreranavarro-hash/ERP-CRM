<?php

namespace App\Modules\Operacion;

use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Models\FolioRango;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Operacion\Models\Alerta;
use App\Modules\Operacion\Models\Respaldo;
use Illuminate\Support\Facades\DB;

/**
 * Revisión periódica que alimenta "Notificaciones y alertas": uso anómalo de API,
 * folios por agotarse, integraciones caídas y respaldos o pruebas de restauración
 * atrasados. Corre cada hora (operacion:vigilar).
 */
class Vigilancia
{
    /** Bajo este número de llamadas en la hora no se considera anomalía (ruido). */
    public const MINIMO_API_HORA = 30;

    public const ERRORES_API_HORA = 20;

    public const DIAS_PRUEBA = ['semanal' => 7, 'mensual' => 31, 'trimestral' => 92];

    public function __construct(private Parametros $parametros, private Tenancy $tenancy, private Alertas $alertas) {}

    /** @return list<Alerta> */
    public function revisar(): array
    {
        $emitidas = [...$this->respaldos(), ...$this->api()];
        foreach (Empresa::where('activo', true)->orderBy('id')->get() as $empresa) {
            $this->tenancy->como($empresa, function () use (&$emitidas) {
                array_push($emitidas, ...$this->folios(), ...$this->integraciones());
            });
        }

        return array_values(array_filter($emitidas));
    }

    /**
     * Por token: llamadas de la última hora contra el promedio por hora de los 7 días
     * anteriores × factor; y ráfagas de respuestas 401/403/429.
     *
     * @return list<?Alerta>
     */
    public function api(): array
    {
        $factor = (int) $this->parametros->valor('api.factor_anomalia');
        $hora = now()->subHour();
        $semana = now()->subDays(7)->subHour();

        $filas = DB::table('api_registros')
            ->whereNotNull('token_id')
            ->where('created_at', '>=', $semana)
            ->groupBy('token_id', 'empresa_id')
            ->selectRaw('token_id, empresa_id,
                count(*) filter (where created_at >= ?) as ultima_hora,
                count(*) filter (where created_at < ?) as previas,
                count(*) filter (where created_at >= ? and estado_http in (401, 403, 429)) as rechazos', [$hora, $hora, $hora])
            ->get();

        $out = [];
        foreach ($filas as $f) {
            $promedio = $f->previas / 168;
            $empresa = Empresa::find($f->empresa_id);
            $emitir = fn (string $codigo, string $titulo, array $detalle) => $empresa
                ? $this->tenancy->como($empresa, fn () => $this->alertas->emitir($codigo, $titulo, $detalle, "token:{$f->token_id}"))
                : $this->alertas->emitir($codigo, $titulo, $detalle, "token:{$f->token_id}", global: true);

            if ($f->ultima_hora >= self::MINIMO_API_HORA && $f->ultima_hora > $factor * max($promedio, 1)) {
                $out[] = $emitir('api_anomalia', "Uso anómalo del token de API #{$f->token_id}", [
                    'llamadas_ultima_hora' => (int) $f->ultima_hora,
                    'promedio_por_hora' => round($promedio, 1),
                    'factor' => $factor,
                ]);
            }
            if ($f->rechazos >= self::ERRORES_API_HORA) {
                $out[] = $emitir('api_errores', "Respuestas rechazadas repetidas para el token #{$f->token_id}", [
                    'rechazos_ultima_hora' => (int) $f->rechazos,
                ]);
            }
        }

        return $out;
    }

    /** @return list<?Alerta> */
    public function folios(): array
    {
        $restantesMin = (int) $this->parametros->valor('folios.aviso_restantes');
        $pct = (float) $this->parametros->valor('folios.aviso_porcentaje');

        // Por tipo de DTE: lo que queda en todos los rangos activos.
        return FolioRango::where('activo', true)->get()->groupBy('tipo_dte')
            ->map(function ($rangos, $tipo) use ($restantesMin, $pct) {
                $restantes = $rangos->sum(fn (FolioRango $r) => $r->restantes());
                $total = $rangos->sum(fn (FolioRango $r) => $r->hasta - $r->desde + 1);
                if ($restantes >= $restantesMin && $restantes >= $total * $pct / 100) {
                    return null;
                }

                return $this->alertas->emitir('folios_por_agotarse', "Quedan {$restantes} folios del tipo {$tipo}", [
                    'tipo_dte' => $tipo, 'restantes' => $restantes, 'aviso_desde' => $restantesMin,
                ], "dte:{$tipo}");
            })->values()->all();
    }

    /** @return list<?Alerta> */
    public function integraciones(): array
    {
        return Integracion::where('activa', true)->where('salud', 'caida')->get()
            ->map(fn (Integracion $i) => $this->alertas->emitir('integracion_caida', 'Integración caída: '.(Integracion::TIPOS[$i->tipo]['nombre'] ?? $i->tipo), [
                'integracion' => $i->tipo, 'desde' => $i->salud_at?->timezone('America/Santiago')->format('d-m-Y H:i'),
            ], $i->tipo, ventanaHoras: 6))
            ->all();
    }

    /** @return list<?Alerta> */
    public function respaldos(): array
    {
        $out = [];
        $estado = $this->estadoRespaldos();

        if ($estado['respaldo_atrasado']) {
            $out[] = $this->alertas->emitir('respaldo_vencido', 'No hay un respaldo exitoso dentro de la frecuencia configurada', [
                'ultimo_ok' => $estado['ultimo_respaldo']?->created_at?->timezone('America/Santiago')->format('d-m-Y H:i') ?? 'nunca',
                'frecuencia_horas' => $estado['frecuencia_horas'],
            ], global: true, ventanaHoras: 12);
        }
        if ($estado['prueba_atrasada']) {
            $out[] = $this->alertas->emitir('restauracion_vencida', 'La prueba de restauración está atrasada', [
                'ultima_ok' => $estado['ultima_prueba']?->created_at?->timezone('America/Santiago')->format('d-m-Y') ?? 'nunca',
                'calendario' => $estado['calendario'],
            ], global: true, ventanaHoras: 72);
        }

        return $out;
    }

    /** Estado para el panel y para las alertas. */
    public function estadoRespaldos(): array
    {
        $frecuencia = (int) $this->parametros->valor('respaldos.frecuencia_horas');
        $calendario = (string) $this->parametros->valor('respaldos.prueba_restauracion');
        $ultimo = Respaldo::where('tipo', 'respaldo')->where('resultado', 'ok')->latest('id')->first();
        $prueba = Respaldo::where('tipo', 'restauracion')->where('resultado', 'ok')->latest('id')->first();

        return [
            'frecuencia_horas' => $frecuencia,
            'calendario' => $calendario,
            'ultimo_respaldo' => $ultimo,
            'ultima_prueba' => $prueba,
            // 2 h de holgura para que el respaldo del día alcance a terminar.
            'respaldo_atrasado' => ! $ultimo || $ultimo->created_at->lt(now()->subHours($frecuencia + 2)),
            'prueba_atrasada' => ! $prueba || $prueba->created_at->lt(now()->subDays(self::DIAS_PRUEBA[$calendario] ?? 31)),
        ];
    }
}
