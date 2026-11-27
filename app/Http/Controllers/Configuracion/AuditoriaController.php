<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Trazabilidad;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Configuración → Tracking de Actividades: visor de solo lectura de la trazabilidad. */
class AuditoriaController extends Controller
{
    public function index(Request $request)
    {
        $q = Trazabilidad::query()->with('user:id,name,email')->orderByDesc('id');

        // Los eventos globales (sin empresa: inicios de sesión, cambios de usuarios o de
        // configuración global) los ve completos solo un SuperAdmin. El resto ve solo los
        // de personas de su empresa, para no exponer a usuarios de otras empresas.
        if (! $request->user()->es_superadmin) {
            $empresa = app(\App\Modules\Core\Tenancy\Tenancy::class)->id();
            $q->where(fn ($w) => $w->where('empresa_id', $empresa)
                ->orWhere(fn ($g) => $g->whereNull('empresa_id')
                    ->whereIn('user_id', \App\Modules\Core\Models\UsuarioEmpresa::where('empresa_id', $empresa)->select('user_id'))));
        }

        if ($request->filled('accion')) {
            $q->where('accion', 'like', $request->string('accion')->value().'%');
        }
        if ($request->filled('usuario')) {
            $u = $request->string('usuario')->value();
            $q->whereHas('user', fn ($q) => $q->withTrashed()->where('name', 'ilike', "%{$u}%")->orWhere('email', 'ilike', "%{$u}%"));
        }
        if ($request->filled('desde')) {
            $q->where('created_at', '>=', $request->date('desde')->startOfDay());
        }
        if ($request->filled('hasta')) {
            $q->where('created_at', '<=', $request->date('hasta')->endOfDay());
        }
        if ($request->filled('entidad')) {
            $q->where('model_type', 'like', '%'.$request->string('entidad')->value());
        }

        $fila = fn (Trazabilidad $t) => [
            'id' => $t->id,
            'fecha' => $t->created_at->toIso8601String(),
            'usuario' => $t->user?->name,
            'accion' => $t->accion,
            'entidad' => $t->model_type ? class_basename($t->model_type) : null,
            'entidad_id' => $t->model_id,
            'antes' => $t->valor_anterior,
            'despues' => $t->valor_nuevo,
            'motivo' => $t->motivo,
            'ip' => $t->ip,
            'canal' => $t->canal,
        ];

        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('configuracion.tracking-de-actividades.exportar'), 403);

            return Csv::descargar('trazabilidad', ['ID', 'Fecha', 'Usuario', 'Acción', 'Entidad', 'ID entidad', 'Antes', 'Después', 'Motivo', 'IP', 'Canal'],
                $q->limit(50000)->cursor()->map($fila)->map(fn ($f) => [
                    $f['id'], $f['fecha'], $f['usuario'], $f['accion'], $f['entidad'], $f['entidad_id'],
                    json_encode($f['antes'], JSON_UNESCAPED_UNICODE), json_encode($f['despues'], JSON_UNESCAPED_UNICODE), $f['motivo'], $f['ip'], $f['canal'],
                ]));
        }

        return Inertia::render('Configuracion/Auditoria/Index', [
            'registros' => $q->paginate(50)->withQueryString()->through($fila),
            'filtros' => $request->only('accion', 'usuario', 'desde', 'hasta', 'entidad'),
            'acciones' => Trazabilidad::query()->select('accion')->distinct()->orderBy('accion')->pluck('accion'),
        ]);
    }
}
