<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Models\Categoria;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\RRHH\Models\Comision;
use App\Modules\RRHH\Models\Empleado;
use App\Modules\RRHH\Models\Nomina;
use App\Modules\RRHH\Models\ReglaComision;
use App\Support\Exportacion\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** RRHH → Comisiones de Venta: calculadas al emitir cada documento; se aprueban por período antes de la nómina. */
class ComisionesController extends Controller
{
    public function index(Request $request)
    {
        $periodo = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('periodo')) ? $request->input('periodo') : now()->format('Y-m');
        $q = Comision::with('empleado:id,nombres,apellidos', 'documento:id,tipo_dte,folio,receptor_razon_social,fecha_emision')->where('periodo', $periodo)->orderByDesc('id');
        if ($request->boolean('exportar')) {
            abort_unless($request->user()->tienePermiso('rrhh.comisiones-de-venta.exportar'), 403);

            return Csv::descargar("comisiones-{$periodo}", ['Vendedor', 'Documento', 'Fecha', 'Base neta', 'Tasa', 'Comisión', 'Estado'], $q->get()->map(fn ($c) => [
                $c->empleado->nombreCompleto(), $c->documento?->nombre(), $c->documento?->fecha_emision?->toDateString(), $c->base, (float) $c->tasa, $c->monto, Comision::ESTADOS[$c->estado]]));
        }
        $todas = (clone $q)->get();

        return Inertia::render('RRHH/Comisiones', [
            'periodo' => $periodo,
            'porVendedor' => $todas->groupBy('empleado_id')->map(fn ($g) => ['empleado' => $g->first()->empleado->nombreCompleto(), 'documentos' => $g->count(),
                'base' => (int) $g->sum('base'), 'monto' => (int) $g->sum('monto'), 'pendientes' => $g->where('estado', 'pendiente')->count()])->values(),
            'comisiones' => $q->paginate(50)->withQueryString()->through(fn (Comision $c) => ['id' => $c->id, 'empleado' => $c->empleado->nombreCompleto(), 'documento' => $c->documento?->nombre(),
                'documento_id' => $c->documento_id, 'cliente' => $c->documento?->receptor_razon_social, 'base' => $c->base, 'tasa' => (float) $c->tasa, 'monto' => $c->monto, 'estado' => $c->estado]),
            'totales' => ['monto' => (int) $todas->sum('monto'), 'pendientes' => $todas->where('estado', 'pendiente')->count()],
            'nominaCerrada' => Nomina::where('periodo', $periodo)->where('estado', '<>', 'borrador')->exists(),
            'reglas' => ReglaComision::with('categoria:id,nombre', 'empleado:id,nombres,apellidos')->get()->map(fn ($r) => ['id' => $r->id, 'categoria' => $r->categoria?->nombre,
                'empleado' => $r->empleado?->nombreCompleto(), 'tasa' => (float) $r->tasa])->sortBy('categoria')->values(),
            'categorias' => Categoria::orderBy('nombre')->get(['id', 'nombre']),
            'vendedores' => Empleado::where('estado', 'activo')->whereNotNull('user_id')->orderBy('apellidos')->get(['id', 'nombres', 'apellidos'])->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->nombreCompleto()]),
        ]);
    }

    public function aprobar(Request $request, Auditoria $auditoria)
    {
        $d = $request->validate(['periodo' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        if (Nomina::where('periodo', $d['periodo'])->where('estado', '<>', 'borrador')->exists()) {
            throw ValidationException::withMessages(['periodo' => 'La nómina de ese mes ya está aprobada.']);
        }
        $n = Comision::where('periodo', $d['periodo'])->where('estado', 'pendiente')->update(['estado' => 'aprobada', 'aprobado_por' => $request->user()->id]);
        $auditoria->registrar('comisiones.aprobar', null, null, ['periodo' => $d['periodo'], 'cantidad' => $n]);

        return back()->with('exito', "{$n} comisiones aprobadas: entran a la nómina de {$d['periodo']} (regenérala si ya estaba calculada).");
    }

    public function guardarRegla(Request $request)
    {
        $d = $request->validate([
            'categoria_id' => ['required', 'integer', 'exists:categorias,id'],
            'empleado_id' => ['nullable', 'integer', 'exists:empleados,id'],
            'tasa' => ['required', 'numeric', 'min:0', 'max:30'],
        ]);
        ReglaComision::updateOrCreate(['categoria_id' => $d['categoria_id'], 'empleado_id' => $d['empleado_id'] ?? null], ['tasa' => $d['tasa']]);

        return back()->with('exito', 'Regla de comisión guardada: aplica a las ventas que se emitan desde ahora.');
    }

    public function eliminarRegla(ReglaComision $regla)
    {
        $regla->delete();

        return back()->with('exito', 'Regla eliminada.');
    }
}
