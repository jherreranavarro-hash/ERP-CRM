<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Descuentos;
use App\Modules\Comercial\ListasPrecios;
use App\Modules\Comercial\Models\Categoria;
use App\Modules\Comercial\Models\NivelDescuento;
use App\Modules\Comercial\Models\ReglaDescuento;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Rol;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Configuración → Descuentos: reglas automáticas y escalones de aprobación por rol. */
class DescuentosController extends Controller
{
    public function __construct(private Auditoria $auditoria) {}

    public function index(Descuentos $descuentos, ListasPrecios $listas)
    {
        $hoy = now()->startOfDay();

        return Inertia::render('Configuracion/Descuentos', [
            'maximo' => (string) $descuentos->maximo(),
            'reglas' => ReglaDescuento::with('categoria:id,nombre')->orderByDesc('activa')->orderBy('nombre')->get()->map(fn (ReglaDescuento $r) => [
                'id' => $r->id, 'nombre' => $r->nombre, 'categoria_id' => $r->categoria_id, 'categoria' => $r->categoria?->nombre,
                'lista_precio' => $r->lista_precio, 'cantidad_minima' => rtrim(rtrim((string) $r->cantidad_minima, '0'), '.'),
                'descuento_pct' => rtrim(rtrim((string) $r->descuento_pct, '0'), '.'), 'activa' => $r->activa,
                'vigente_desde' => $r->vigente_desde?->toDateString(), 'vigente_hasta' => $r->vigente_hasta?->toDateString(),
                'vigente' => $r->activa && (! $r->vigente_desde || $r->vigente_desde->lte($hoy)) && (! $r->vigente_hasta || $r->vigente_hasta->gte($hoy)),
            ]),
            'niveles' => $descuentos->niveles()->map(fn ($n) => ['id' => $n->id, 'hasta_pct' => rtrim(rtrim((string) $n->hasta_pct, '0'), '.'), 'rol_id' => $n->rol_id, 'rol' => $n->rol?->nombre]),
            'categorias' => Categoria::orderBy('nombre')->get(['id', 'nombre']),
            'listas' => $listas->todas()->values()->map->only(['codigo', 'nombre']),
            'roles' => Rol::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function guardarRegla(Request $request, ?ReglaDescuento $regla = null)
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'categoria_id' => ['nullable', 'integer', Rule::exists('categorias', 'id')],
            'lista_precio' => ['nullable', Rule::in(app(ListasPrecios::class)->codigos())],
            'cantidad_minima' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'descuento_pct' => ['required', 'numeric', 'gt:0', 'max:90'],
            'vigente_desde' => ['nullable', 'date'],
            'vigente_hasta' => ['nullable', 'date', 'after_or_equal:vigente_desde'],
            'activa' => ['boolean'],
        ]);
        $antes = $regla?->only(array_keys($d));
        if ($regla) {
            $regla->update($d);
        } else {
            $regla = ReglaDescuento::create($d + ['creado_por' => $request->user()->id]);
        }
        $this->auditoria->registrar($antes ? 'descuento.regla_editar' : 'descuento.regla_crear', $regla, $antes, $d);

        return back()->with('exito', 'Regla guardada.');
    }

    public function eliminarRegla(ReglaDescuento $regla)
    {
        $this->auditoria->registrar('descuento.regla_eliminar', $regla, $regla->only(['nombre', 'descuento_pct']), null);
        $regla->delete();

        return back()->with('exito', 'Regla eliminada.');
    }

    public function guardarNivel(Request $request, Descuentos $descuentos)
    {
        $d = $request->validate([
            'hasta_pct' => ['required', 'numeric', 'gt:0', 'max:100'],
            'rol_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('activo', true)],
        ]);
        if ($descuentos->maximo()->isGreaterThanOrEqualTo((string) $d['hasta_pct'])) {
            throw ValidationException::withMessages(['hasta_pct' => 'El escalón debe superar el máximo sin aprobación ('.$descuentos->maximo().' %).']);
        }
        $nivel = NivelDescuento::updateOrCreate(['hasta_pct' => $d['hasta_pct']], ['rol_id' => $d['rol_id']]);
        $this->auditoria->registrar('descuento.nivel', $nivel, null, $d);

        return back()->with('exito', 'Escalón guardado.');
    }

    public function eliminarNivel(NivelDescuento $nivel)
    {
        $this->auditoria->registrar('descuento.nivel_eliminar', $nivel, $nivel->only(['hasta_pct', 'rol_id']), null);
        $nivel->delete();

        return back()->with('exito', 'Escalón eliminado.');
    }
}
