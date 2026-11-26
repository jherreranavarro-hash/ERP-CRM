<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Facturacion\Documentos;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Models\DocumentoReferencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * API v1 de ventas (reemplazo seguro de /api/informe-ventas). Endpoints separados
 * por granularidad, paginación obligatoria (máx. 200) y filtros aplicados en SQL.
 * La empresa la fija el token (AutenticarTokenApi); el RLS filtra el resto.
 */
class VentasController extends Controller
{
    private const MAX_POR_PAGINA = 200;

    /** GET /api/v1/informe-ventas/resumen?desde=&hasta= */
    public function resumen(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->periodo($request);
        $signo = 'CASE WHEN tipo_dte = 61 THEN -1 ELSE 1 END';

        $filas = Documento::query()->whereNotNull('folio')->whereIn('tipo_dte', [33, 34, 39, 41, 56, 61])
            ->whereBetween('fecha_emision', [$desde, $hasta])
            ->groupBy('tipo_dte')->orderBy('tipo_dte')
            ->get(['tipo_dte', DB::raw('count(*) as cantidad'), DB::raw("sum(monto_neto * {$signo}) as neto"), DB::raw("sum(monto_exento * {$signo}) as exento"), DB::raw("sum(monto_iva * {$signo}) as iva"), DB::raw("sum(monto_total * {$signo}) as total")]);

        $porTipo = $filas->map(fn ($f) => ['tipo_dte' => $f->tipo_dte, 'nombre' => Documento::TIPOS[$f->tipo_dte], 'cantidad' => (int) $f->cantidad, 'neto' => (int) $f->neto, 'exento' => (int) $f->exento, 'iva' => (int) $f->iva, 'total' => (int) $f->total]);

        return response()->json(['data' => [
            'desde' => $desde, 'hasta' => $hasta, 'moneda' => 'CLP',
            'cantidad' => $porTipo->sum('cantidad'), 'neto' => $porTipo->sum('neto'), 'exento' => $porTipo->sum('exento'), 'iva' => $porTipo->sum('iva'), 'total' => $porTipo->sum('total'),
            'por_tipo' => $porTipo->values(),
        ]]);
    }

    /** GET /api/v1/informe-ventas/dtes?desde=&hasta=&tipo_dte=&cliente_id=&local_id=&page=&per_page= */
    public function dtes(Request $request): JsonResponse
    {
        [$desde, $hasta] = $this->periodo($request);
        $f = $request->validate([
            'tipo_dte' => ['nullable', Rule::in(array_keys(Documento::TIPOS))],
            'cliente_id' => ['nullable', 'integer'],
            'local_id' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::in(['emitido', 'pagado', 'anulado'])],
        ]);

        $q = Documento::query()->whereNotNull('folio')->whereBetween('fecha_emision', [$desde, $hasta])
            ->when($f['tipo_dte'] ?? null, fn ($q, $v) => $q->where('tipo_dte', $v))
            ->when($f['cliente_id'] ?? null, fn ($q, $v) => $q->where('cliente_id', $v))
            ->when($f['local_id'] ?? null, fn ($q, $v) => $q->where('local_id', $v))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
            ->orderBy('fecha_emision')->orderBy('id');

        return $this->paginado($q->paginate($this->porPagina($request))->through(fn (Documento $d) => $this->resumenDte($d)));
    }

    /** GET /api/v1/dtes/{id} */
    public function dte(int $id): JsonResponse
    {
        $d = Documento::whereNotNull('folio')->with('lineas', 'referencias')->findOrFail($id);

        return response()->json(['data' => $this->resumenDte($d) + [
            'descuento_global_pct' => (string) $d->descuento_global_pct,
            'iva_incluido' => $d->iva_incluido,
            'lineas' => $d->lineas->map(fn ($l) => ['codigo' => $l->codigo, 'descripcion' => $l->descripcion, 'cantidad' => (string) $l->cantidad, 'unidad' => $l->unidad, 'precio_unitario' => (string) $l->precio_unitario, 'descuento_monto' => $l->descuento_monto, 'monto_item' => $l->monto_item, 'exento' => $l->exento]),
            'referencias' => $d->referencias->map(fn ($r) => ['tipo_dte' => $r->tipo_dte_ref, 'folio' => $r->folio_ref, 'fecha' => $r->fecha_ref->toDateString(), 'codigo' => $r->codigo, 'codigo_nombre' => DocumentoReferencia::CODIGOS[$r->codigo] ?? null, 'razon' => $r->razon]),
        ]]);
    }

    /**
     * POST /api/v1/dtes — crea el documento y, si "emitir" es true, lo emite.
     * Además del scope dtes:write, el dueño del token debe tener los permisos de la UI.
     */
    public function crear(Request $request, Documentos $documentos): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->tienePermiso('caja.facturas-de-ventas.crear'), 403, 'El usuario del token no puede crear documentos.');

        $d = $request->validate([
            'tipo_dte' => ['required', 'integer', Rule::in([33, 34, 39, 41, 52])],
            'cliente_id' => ['nullable', 'integer'],
            'local_id' => ['nullable', 'integer'],
            'forma_pago' => ['nullable', Rule::in(['contado', 'credito'])],
            'descuento_global_pct' => ['nullable', 'numeric', 'between:0,100'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'lineas' => ['required', 'array', 'min:1', 'max:60'],
            'lineas.*.producto_id' => ['nullable', 'integer'],
            'lineas.*.descripcion' => ['nullable', 'string', 'max:80'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'lineas.*.precio_unitario' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.descuento_pct' => ['nullable', 'numeric', 'between:0,100'],
            'emitir' => ['boolean'],
        ]);

        $doc = $documentos->guardar($d + ['forma_pago' => $d['forma_pago'] ?? 'contado'], $user);
        if ($request->boolean('emitir')) {
            abort_unless($user->tienePermiso('caja.facturas-de-ventas.emitir'), 403, 'El usuario del token no puede emitir.');
            $doc = $documentos->emitir($doc, $user);
        }

        return response()->json(['data' => ['id' => $doc->id, 'estado' => $doc->estado, 'tipo_dte' => $doc->tipo_dte, 'folio' => $doc->folio, 'total' => $doc->monto_total, 'motivos_aprobacion' => $doc->aprobacion_motivos ?? []]], 201);
    }

    /** GET /api/v1/clientes?buscar=&page=&per_page= */
    public function clientes(Request $request): JsonResponse
    {
        $b = $request->string('buscar')->trim()->value();
        $q = Cliente::query()->when($b, fn ($q) => $q->where('razon_social', 'ilike', "%{$b}%"))->orderBy('id');

        return $this->paginado($q->paginate($this->porPagina($request))->through(fn (Cliente $c) => $c->only(['id', 'rut', 'razon_social', 'giro', 'direccion', 'comuna', 'ciudad', 'lista_precio', 'tiene_credito', 'plazo_dias', 'activo'])));
    }

    private function resumenDte(Documento $d): array
    {
        return [
            'id' => $d->id, 'tipo_dte' => $d->tipo_dte, 'folio' => $d->folio, 'fecha_emision' => $d->fecha_emision->toDateString(), 'fecha_vencimiento' => $d->fecha_vencimiento?->toDateString(),
            'receptor' => ['rut' => $d->receptor_rut, 'razon_social' => $d->receptor_razon_social], 'cliente_id' => $d->cliente_id, 'local_id' => $d->local_id,
            'forma_pago' => $d->forma_pago, 'neto' => $d->monto_neto, 'exento' => $d->monto_exento, 'iva' => $d->monto_iva, 'total' => $d->monto_total, 'saldo' => $d->saldo,
            'estado' => $d->estado, 'estado_sii' => $d->estado_sii,
        ];
    }

    /** @return array{string, string} */
    private function periodo(Request $request): array
    {
        $v = $request->validate(['desde' => ['required', 'date_format:Y-m-d'], 'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde']]);
        if (now()->parse($v['desde'])->diffInDays(now()->parse($v['hasta'])) > 366) {
            abort(422, 'El período no puede superar un año.');
        }

        return [$v['desde'], $v['hasta']];
    }

    private function porPagina(Request $request): int
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_POR_PAGINA], 'page' => ['nullable', 'integer', 'min:1']]);

        return $request->integer('per_page', 50);
    }

    private function paginado($p): JsonResponse
    {
        return response()->json(['data' => $p->items(), 'meta' => ['total' => $p->total(), 'current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage()]]);
    }
}
