<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Comercial\Metas;
use App\Modules\Comercial\Models\Meta;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Models\UsuarioEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Configuración → MetaAdmin: metas comerciales por mes para la empresa, un local o un vendedor. */
class MetasController extends Controller
{
    public function __construct(private Auditoria $auditoria) {}

    public function index(Request $request, Metas $metas)
    {
        $periodo = $this->periodo($request->string('periodo')->value());
        $lista = Meta::with('local:id,nombre', 'vendedor:id,name')->whereDate('periodo', $periodo)->orderBy('indicador')->orderByRaw("array_position(array['empresa','local','vendedor']::varchar[], alcance)")->orderBy('id')->get();
        $avance = $metas->avance($lista, $periodo);

        return Inertia::render('Configuracion/Metas', [
            'periodo' => $periodo->format('Y-m'),
            'metas' => $lista->map(fn (Meta $m) => [
                'id' => $m->id, 'indicador' => $m->indicador, 'alcance' => $m->alcance, 'local_id' => $m->local_id, 'user_id' => $m->user_id,
                'quien' => match ($m->alcance) { 'local' => $m->local?->nombre, 'vendedor' => $m->vendedor?->name, default => 'Toda la empresa' },
                'objetivo' => $m->objetivo,
            ] + $avance[$m->id]),
            'indicadores' => Meta::INDICADORES,
            'alcances' => Meta::ALCANCES,
            'locales' => Local::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'vendedores' => User::whereIn('id', UsuarioEmpresa::where('activo', true)->select('user_id'))->orderBy('name')->get(['id', 'name']),
            'avanceMes' => $this->avanceMes($periodo),
            'hayAnterior' => Meta::whereDate('periodo', $periodo->copy()->subMonth())->exists(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $this->validar($request);
        $this->exigirUnica($d);
        $m = Meta::create($d + ['creado_por' => $request->user()->id]);
        $this->auditoria->registrar('meta.crear', $m, null, $d);

        return back()->with('exito', 'Meta creada.');
    }

    public function update(Request $request, Meta $meta)
    {
        $d = $request->validate(['objetivo' => ['required', 'integer', 'min:1', 'max:999999999999']]);
        $antes = ['objetivo' => $meta->objetivo];
        $meta->update($d);
        $this->auditoria->registrar('meta.editar', $meta, $antes, $d);

        return back()->with('exito', 'Meta actualizada.');
    }

    public function destroy(Meta $meta)
    {
        $this->auditoria->registrar('meta.eliminar', $meta, $meta->only(['periodo', 'indicador', 'alcance', 'local_id', 'user_id', 'objetivo']), null);
        $meta->delete();

        return back()->with('exito', 'Meta eliminada.');
    }

    /** Copia las metas del mes anterior que aún no existen en este mes. */
    public function copiar(Request $request)
    {
        $periodo = $this->periodo($request->string('periodo')->value());
        $n = 0;
        foreach (Meta::whereDate('periodo', $periodo->copy()->subMonth())->get() as $m) {
            $d = ['periodo' => $periodo->toDateString()] + $m->only(['indicador', 'alcance', 'local_id', 'user_id', 'objetivo']);
            if (! $this->existe($d)) {
                Meta::create($d + ['creado_por' => $request->user()->id]);
                $n++;
            }
        }
        $this->auditoria->registrar('meta.copiar', null, null, ['periodo' => $periodo->format('Y-m'), 'copiadas' => $n]);

        return back()->with('exito', $n ? "Se copiaron {$n} metas del mes anterior." : 'No había metas nuevas que copiar.');
    }

    private function validar(Request $request): array
    {
        $d = $request->validate([
            'periodo' => ['required', 'date_format:Y-m'],
            'indicador' => ['required', Rule::in(array_keys(Meta::INDICADORES))],
            'alcance' => ['required', Rule::in(array_keys(Meta::ALCANCES))],
            'local_id' => ['nullable', 'required_if:alcance,local', 'integer', Rule::exists('locales', 'id')],
            'user_id' => ['nullable', 'required_if:alcance,vendedor', 'integer', Rule::in(UsuarioEmpresa::where('activo', true)->pluck('user_id')->all())],
            'objetivo' => ['required', 'integer', 'min:1', 'max:999999999999'],
        ], ['local_id.required_if' => 'Elige el local.', 'user_id.required_if' => 'Elige el vendedor.', 'user_id.in' => 'El vendedor no pertenece a la empresa.']);
        $d['periodo'] = $this->periodo($d['periodo'])->toDateString();
        $d['local_id'] = $d['alcance'] === 'local' ? $d['local_id'] : null;
        $d['user_id'] = $d['alcance'] === 'vendedor' ? $d['user_id'] : null;

        return $d;
    }

    private function exigirUnica(array $d): void
    {
        if ($this->existe($d)) {
            throw ValidationException::withMessages(['indicador' => 'Ya existe esa meta en el período: edita su objetivo.']);
        }
    }

    private function existe(array $d): bool
    {
        return Meta::whereDate('periodo', $d['periodo'])->where('indicador', $d['indicador'])->where('alcance', $d['alcance'])
            ->where('local_id', $d['local_id'])->where('user_id', $d['user_id'])->exists();
    }

    private function periodo(?string $ym): Carbon
    {
        return $ym && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) ? Carbon::createFromFormat('Y-m-d', $ym.'-01')->startOfDay() : now()->startOfMonth();
    }

    /** @return array{dias:int, transcurridos:int} */
    private function avanceMes(Carbon $periodo): array
    {
        $hoy = now()->startOfDay();
        $dias = $periodo->daysInMonth;

        return ['dias' => $dias, 'transcurridos' => $hoy->lt($periodo) ? 0 : ($hoy->gt($periodo->copy()->endOfMonth()) ? $dias : $hoy->day)];
    }
}
