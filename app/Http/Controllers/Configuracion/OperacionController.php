<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Operacion\Alertas;
use App\Modules\Operacion\Models\Alerta;
use App\Modules\Operacion\Models\Respaldo;
use App\Modules\Operacion\Models\RetencionEjecucion;
use App\Modules\Operacion\Models\SolicitudPrivilegio;
use App\Modules\Operacion\Vigilancia;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Configuración → Operación y continuidad: bandeja de alertas, panel de respaldos
 * con el resultado de la última prueba de restauración y bitácora de retención.
 */
class OperacionController extends Controller
{
    public function index(Request $request, Vigilancia $vigilancia, Tenancy $tenancy)
    {
        $estado = $vigilancia->estadoRespaldos();
        $fecha = fn ($d) => $d?->toIso8601String();
        $verAtendidas = $request->boolean('atendidas');

        // Alertas de la empresa activa y de plataforma (RLS ya filtra las de otras empresas).
        $alertas = Alerta::query()
            ->when(! $verAtendidas, fn ($q) => $q->whereNull('atendida_at'))
            ->latest('id')->limit(100)->get()
            ->map(fn (Alerta $a) => [
                'id' => $a->id, 'tipo' => $a->tipo, 'codigo' => $a->codigo,
                'nombre' => Alertas::CODIGOS[$a->codigo] ?? $a->codigo,
                'titulo' => $a->titulo, 'detalle' => $a->detalle ?? [],
                'plataforma' => $a->empresa_id === null, 'notificada' => (bool) $a->notificada_a,
                'fecha' => $fecha($a->created_at), 'atendida' => $fecha($a->atendida_at),
            ]);

        return Inertia::render('Configuracion/Operacion', [
            'alertas' => $alertas,
            'verAtendidas' => $verAtendidas,
            'respaldos' => [
                'frecuencia_horas' => $estado['frecuencia_horas'],
                'calendario' => $estado['calendario'],
                'respaldo_atrasado' => $estado['respaldo_atrasado'],
                'prueba_atrasada' => $estado['prueba_atrasada'],
                'ultimo' => $estado['ultimo_respaldo'] ? $this->respaldo($estado['ultimo_respaldo']) : null,
                'ultima_prueba' => $estado['ultima_prueba'] ? $this->respaldo($estado['ultima_prueba']) : null,
                'historial' => Respaldo::latest('id')->limit(15)->get()->map(fn ($r) => $this->respaldo($r)),
            ],
            'retencion' => RetencionEjecucion::latest('id')->limit(20)->get()->map(fn (RetencionEjecucion $r) => [
                'id' => $r->id, 'categoria' => $r->categoria, 'accion' => $r->accion, 'registros' => $r->registros,
                'limite' => $r->limite->toDateString(), 'plataforma' => $r->empresa_id === null, 'fecha' => $fecha($r->created_at),
            ]),
            'solicitudesPendientes' => SolicitudPrivilegio::where('estado', 'pendiente')->count(),
        ]);
    }

    public function atender(Request $request, Alerta $alerta, Alertas $alertas)
    {
        $alertas->atender($alerta, $request->user());

        return back()->with('exito', 'Alerta marcada como atendida.');
    }

    private function respaldo(Respaldo $r): array
    {
        return [
            'id' => $r->id, 'tipo' => $r->tipo, 'resultado' => $r->resultado, 'archivo' => $r->archivo ? basename($r->archivo) : null,
            'bytes' => $r->bytes, 'duracion_s' => $r->duracion_s, 'detalle' => $r->detalle, 'servidor' => $r->servidor,
            'fecha' => $r->created_at->toIso8601String(),
        ];
    }
}
