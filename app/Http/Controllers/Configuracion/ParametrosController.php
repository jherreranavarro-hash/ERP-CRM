<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Models\ParametroCambio;
use App\Modules\Core\Parametros\Catalogo;
use App\Modules\Core\Parametros\Parametros;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/** Configuración → Parámetros del Sistema: los 28 mantenedores de requisitos no funcionales. */
class ParametrosController extends Controller
{
    public function __construct(private Parametros $parametros) {}

    public function index(Request $request)
    {
        $pendientes = ParametroCambio::where('estado', 'propuesto')->get()->groupBy(fn ($c) => Catalogo::definicion($c->clave)['mantenedor']);
        $grupos = [];
        foreach (Catalogo::mantenedores() as $clave => $m) {
            $grupos[$m['grupo']]['nombre'] = Catalogo::GRUPOS[$m['grupo']];
            $grupos[$m['grupo']]['mantenedores'][] = [
                'clave' => $clave,
                'nombre' => $m['nombre'],
                'sensible' => $m['sensible'],
                'edita' => $m['edita'],
                'piso' => $m['piso'],
                'parametros' => count($m['parametros']),
                'pendientes' => isset($pendientes[$clave]) ? $pendientes[$clave]->count() : 0,
                'entidad' => isset($m['entidad']) ? route($m['entidad'], absolute: false) : null,
            ];
        }

        return Inertia::render('Configuracion/Parametros/Index', [
            'grupos' => array_values($grupos),
            'totalMantenedores' => count(Catalogo::mantenedores()),
            'porAprobar' => ParametroCambio::with(['proponente:id,name'])->where('estado', 'propuesto')
                ->where('propuesto_por', '!=', $request->user()->id)->orderBy('id')->get()->map(fn ($c) => $this->cambio($c)),
        ]);
    }

    public function show(Request $request, string $mantenedor)
    {
        $m = Catalogo::mantenedores()[$mantenedor] ?? abort(404);
        $localId = $request->integer('local_id') ?: null;

        $parametros = [];
        foreach ($m['parametros'] as $clave => $def) {
            $def = Catalogo::definicion($clave);
            $parametros[] = [
                'clave' => $clave,
                'etiqueta' => $def['etiqueta'],
                'tipo' => $def['tipo'],
                'unidad' => $def['unidad'] ?? null,
                'ayuda' => $def['ayuda'] ?? null,
                'min' => $def['min'] ?? null,
                'max' => $def['max'] ?? null,
                'opciones' => $def['opciones'] ?? null,
                'obligatorias' => $def['obligatorias'] ?? [],
                'estricto' => $def['estricto'] ?? null,
                'alcances' => $def['alcances'],
                'inicial' => $def['inicial'],
                'efectivo' => $this->parametros->valor($clave, $localId),
                'valores' => [
                    'global' => $this->parametros->valorEnAlcance($clave, 'global'),
                    'empresa' => $this->parametros->valorEnAlcance($clave, 'empresa'),
                    'local' => $localId ? $this->parametros->valorEnAlcance($clave, 'local', $localId) : null,
                ],
            ];
        }

        $historial = ParametroCambio::with(['proponente:id,name', 'resolutor:id,name'])
            ->whereIn('clave', array_keys($m['parametros']))->orderByDesc('id')->limit(100)->get()
            ->map(fn ($c) => $this->cambio($c));

        return Inertia::render('Configuracion/Parametros/Show', [
            'mantenedor' => ['clave' => $mantenedor, 'entidad' => isset($m['entidad']) ? route($m['entidad'], absolute: false) : null] + collect($m)->except('parametros', 'entidad')->all() + ['grupo_nombre' => Catalogo::GRUPOS[$m['grupo']]],
            'parametros' => $parametros,
            'historial' => $historial,
            'locales' => Local::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'localId' => $localId,
        ]);
    }

    public function proponer(Request $request)
    {
        $data = $request->validate([
            'clave' => ['required', 'string'],
            'valor' => ['present'],
            'alcance' => ['required', 'in:global,empresa,local'],
            'local_id' => ['nullable', 'integer', 'exists:locales,id'],
            'motivo' => ['required', 'string', 'max:500'],
            'vigente_desde' => ['nullable', 'date', 'after_or_equal:today'],
        ]);
        $def = Catalogo::parametros()[$data['clave']] ?? abort(404);
        $this->exigirAlcanceGlobal($request, $data['alcance']);

        $cambio = $this->parametros->proponer(
            $data['clave'], $data['valor'], $data['alcance'], $data['motivo'], $request->user(),
            $data['local_id'] ?? null, isset($data['vigente_desde']) ? Carbon::parse($data['vigente_desde'], 'America/Santiago') : null,
        );

        return back()->with('exito', $cambio->estado === 'aprobado'
            ? "«{$def['etiqueta']}» actualizado."
            : "Cambio propuesto. Debe aprobarlo otro usuario con permiso (mantenedor sensible).");
    }

    public function aprobar(Request $request, ParametroCambio $cambio)
    {
        $data = $request->validate(['comentario' => ['nullable', 'string', 'max:500']]);
        $this->exigirAlcanceGlobal($request, $cambio->alcance());
        $this->parametros->aprobar($cambio, $request->user(), $data['comentario'] ?? null);

        return back()->with('exito', 'Cambio aprobado. Rige desde '.$cambio->vigente_desde->timezone('America/Santiago')->format('d-m-Y H:i').'.');
    }

    public function rechazar(Request $request, ParametroCambio $cambio)
    {
        $data = $request->validate(['comentario' => ['required', 'string', 'max:500']]);
        $this->exigirAlcanceGlobal($request, $cambio->alcance());
        $this->parametros->rechazar($cambio, $request->user(), $data['comentario']);

        return back()->with('exito', 'Cambio rechazado.');
    }

    public function revertir(Request $request, ParametroCambio $cambio)
    {
        $data = $request->validate(['motivo' => ['required', 'string', 'max:500']]);
        $this->exigirAlcanceGlobal($request, $cambio->alcance());
        $nuevo = $this->parametros->revertir($cambio, $request->user(), $data['motivo']);

        return back()->with('exito', $nuevo->estado === 'aprobado' ? 'Se restauró la versión anterior.' : 'Se propuso volver a la versión anterior; falta la aprobación.');
    }

    public function exportar()
    {
        $json = $this->parametros->exportar();

        return response()->streamDownload(fn () => print (json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'configuracion-'.now()->format('Ymd-His').'.json', ['Content-Type' => 'application/json']);
    }

    public function importar(Request $request)
    {
        $data = $request->validate([
            'archivo' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:512'],
            'motivo' => ['required', 'string', 'max:500'],
        ]);
        $json = json_decode((string) file_get_contents($data['archivo']->getRealPath()), true);
        abort_unless(is_array($json), 422, 'El archivo no es un JSON válido.');
        $this->exigirAlcanceGlobal($request, 'global');
        $n = $this->parametros->importar($json, $request->user(), $data['motivo']);

        return back()->with('exito', "Importación lista: {$n} cambio(s) creados (los sensibles quedan por aprobar).");
    }

    /** Los valores globales afectan a todas las empresas: solo SuperAdmin. */
    private function exigirAlcanceGlobal(Request $request, string $alcance): void
    {
        abort_if($alcance === 'global' && ! $request->user()->es_superadmin, 403, 'Solo SuperAdmin cambia valores globales.');
    }

    private function cambio(ParametroCambio $c): array
    {
        $def = Catalogo::definicion($c->clave);

        return [
            'id' => $c->id,
            'clave' => $c->clave,
            'etiqueta' => $def['etiqueta'],
            'mantenedor' => Catalogo::mantenedores()[$def['mantenedor']]['nombre'],
            'mantenedor_clave' => $def['mantenedor'],
            'valor' => $c->valor,
            'unidad' => $def['unidad'] ?? null,
            'alcance' => $c->alcance(),
            'estado' => $c->estado,
            'motivo' => $c->motivo,
            'vigente_desde' => $c->vigente_desde->toIso8601String(),
            'propuesto_por' => $c->proponente?->name,
            'propuesto_por_id' => $c->propuesto_por,
            'resuelto_por' => $c->relationLoaded('resolutor') ? $c->resolutor?->name : null,
            'comentario' => $c->comentario_resolucion,
            'auto' => $c->auto_aprobado,
            'fecha' => $c->created_at->toIso8601String(),
        ];
    }
}
