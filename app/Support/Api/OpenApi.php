<?php

namespace App\Support\Api;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Rutas;

/**
 * Especificación OpenAPI 3.1 de la API v1, generada desde las rutas registradas:
 * método, ruta y scope salen del router; la descripción, parámetros y ejemplos,
 * del catálogo de abajo. Una ruta nueva sin descripción aparece marcada como
 * "sin documentar" (y la prueba de la API falla), así la documentación no se desfasa.
 */
class OpenApi
{
    private const PAGINACION = [
        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1], 'description' => 'Página (desde 1).'],
        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50], 'description' => 'Filas por página.'],
    ];

    private const PERIODO = [
        ['name' => 'desde', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Fecha inicial (AAAA-MM-DD).'],
        ['name' => 'hasta', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Fecha final; el período no supera un año.'],
    ];

    /** @return array<string, array<string, mixed>> */
    private function catalogo(): array
    {
        $dte = ['id' => 812, 'tipo_dte' => 33, 'folio' => 1407, 'fecha_emision' => '2026-11-20', 'fecha_vencimiento' => '2026-12-20',
            'receptor' => ['rut' => '76543210-3', 'razon_social' => 'Constructora Andes SpA'], 'cliente_id' => 4, 'local_id' => 1,
            'forma_pago' => 'credito', 'neto' => 100000, 'exento' => 0, 'iva' => 19000, 'total' => 119000, 'saldo' => 119000, 'estado' => 'emitido', 'estado_sii' => 'aceptado'];
        $meta = ['total' => 1234, 'current_page' => 1, 'last_page' => 25, 'per_page' => 50];

        return [
            'GET /ping' => ['tag' => 'General', 'resumen' => 'Comprueba el token', 'ejemplo' => ['ok' => true, 'hora' => '2026-11-27T10:00:00-03:00']],
            'GET /empresa' => ['tag' => 'General', 'resumen' => 'Datos del emisor', 'ejemplo' => ['data' => ['rut' => '76123456-7', 'razon_social' => 'Empresa Demo SpA', 'giro' => 'Venta de materiales de construcción']]],
            'GET /informe-ventas/resumen' => ['tag' => 'Informe de ventas', 'resumen' => 'Totales del período por tipo de documento',
                'descripcion' => 'Suma los documentos con folio del período. Las notas de crédito (61) restan.',
                'parametros' => self::PERIODO,
                'ejemplo' => ['data' => ['desde' => '2026-11-01', 'hasta' => '2026-11-30', 'moneda' => 'CLP', 'cantidad' => 152, 'neto' => 18250000, 'exento' => 0, 'iva' => 3467500, 'total' => 21717500,
                    'por_tipo' => [['tipo_dte' => 33, 'nombre' => 'Factura electrónica', 'cantidad' => 40, 'neto' => 15000000, 'exento' => 0, 'iva' => 2850000, 'total' => 17850000]]]]],
            'GET /informe-ventas/dtes' => ['tag' => 'Informe de ventas', 'resumen' => 'Documentos emitidos del período',
                'parametros' => array_merge(self::PERIODO, [
                    ['name' => 'tipo_dte', 'in' => 'query', 'schema' => ['type' => 'integer', 'enum' => [33, 34, 39, 41, 52, 56, 61]]],
                    ['name' => 'cliente_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                    ['name' => 'local_id', 'in' => 'query', 'schema' => ['type' => 'integer']],
                    ['name' => 'estado', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['emitido', 'pagado', 'anulado']]],
                ], self::PAGINACION),
                'ejemplo' => ['data' => [$dte], 'meta' => $meta]],
            'GET /dtes/{id}' => ['tag' => 'Documentos', 'resumen' => 'Un documento con líneas y referencias',
                'parametros' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                'ejemplo' => ['data' => $dte + ['descuento_global_pct' => '0', 'iva_incluido' => false,
                    'lineas' => [['codigo' => 'CEM-25', 'descripcion' => 'Cemento 25 kg', 'cantidad' => '20', 'unidad' => 'UN', 'precio_unitario' => '5000', 'descuento_monto' => 0, 'monto_item' => 100000, 'exento' => false]],
                    'referencias' => []]]],
            'POST /dtes' => ['tag' => 'Documentos', 'resumen' => 'Crea un documento y, opcionalmente, lo emite',
                'descripcion' => 'Tipos 33, 34, 39, 41 y 52. El precio lo decide el sistema según la lista del cliente; un precio_unitario enviado queda como precio manual. Si requiere aprobación (descuento, precio bajo lista, cupo) queda por_aprobar y no se emite. El dueño del token debe tener además los permisos de crear y emitir.',
                'cuerpo' => ['tipo_dte' => 39, 'emitir' => true, 'lineas' => [['producto_id' => 15, 'cantidad' => 2]]],
                'esquema' => ['type' => 'object', 'required' => ['tipo_dte', 'lineas'], 'properties' => [
                    'tipo_dte' => ['type' => 'integer', 'enum' => [33, 34, 39, 41, 52]], 'cliente_id' => ['type' => 'integer'], 'local_id' => ['type' => 'integer'],
                    'forma_pago' => ['type' => 'string', 'enum' => ['contado', 'credito']], 'descuento_global_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100],
                    'observaciones' => ['type' => 'string', 'maxLength' => 500], 'emitir' => ['type' => 'boolean'],
                    'lineas' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 60, 'items' => ['type' => 'object', 'required' => ['cantidad'], 'properties' => [
                        'producto_id' => ['type' => 'integer'], 'descripcion' => ['type' => 'string', 'maxLength' => 80], 'cantidad' => ['type' => 'number', 'exclusiveMinimum' => 0],
                        'precio_unitario' => ['type' => 'number', 'minimum' => 0], 'descuento_pct' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100]]]],
                ]],
                'codigo' => '201',
                'ejemplo' => ['data' => ['id' => 812, 'estado' => 'emitido', 'tipo_dte' => 39, 'folio' => 1407, 'total' => 11876, 'motivos_aprobacion' => []]]],
            'GET /clientes' => ['tag' => 'Clientes', 'resumen' => 'Clientes de la empresa',
                'parametros' => array_merge([['name' => 'buscar', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Parte de la razón social.']], self::PAGINACION),
                'ejemplo' => ['data' => [['id' => 4, 'rut' => '76543210-3', 'razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción', 'direccion' => 'Av. Los Leones 100', 'comuna' => 'Providencia', 'ciudad' => 'Santiago', 'lista_precio' => 'mayorista', 'tiene_credito' => true, 'plazo_dias' => 30, 'activo' => true]], 'meta' => $meta]],
        ];
    }

    /** @return list<array{metodo:string, ruta:string, scope:?string, documentada:bool, tag:string, resumen:string, descripcion:?string}> */
    public function endpoints(): array
    {
        $catalogo = $this->catalogo();
        $salida = [];
        /** @var Route $r */
        foreach (Rutas::getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'api/v1/')) {
                continue;
            }
            $ruta = '/'.substr($r->uri(), strlen('api/v1/'));
            $scope = collect($r->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'scope:'));
            foreach (array_diff($r->methods(), ['HEAD']) as $metodo) {
                $info = $catalogo["{$metodo} {$ruta}"] ?? null;
                $salida[] = ['metodo' => $metodo, 'ruta' => $ruta, 'scope' => $scope ? substr($scope, 6) : null, 'documentada' => $info !== null,
                    'tag' => $info['tag'] ?? 'Sin documentar', 'resumen' => $info['resumen'] ?? 'Sin documentar', 'descripcion' => $info['descripcion'] ?? null,
                    'info' => $info];
            }
        }

        return $salida;
    }

    /** @return array<string, mixed> */
    public function generar(): array
    {
        $paths = [];
        foreach ($this->endpoints() as $e) {
            $i = $e['info'] ?? [];
            $op = [
                'tags' => [$e['tag']],
                'summary' => $e['resumen'],
                'security' => [['token' => $e['scope'] ? [$e['scope']] : []]],
            ];
            if ($e['descripcion']) {
                $op['description'] = $e['descripcion'];
            }
            if (! empty($i['parametros'])) {
                $op['parameters'] = $i['parametros'];
            }
            if (! empty($i['cuerpo'])) {
                $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => $i['esquema'] ?? ['type' => 'object'], 'example' => $i['cuerpo']]]];
            }
            $op['responses'] = [
                $i['codigo'] ?? '200' => ['description' => 'Correcto', 'content' => ['application/json' => ['example' => $i['ejemplo'] ?? new \stdClass]]],
                '401' => ['description' => 'Token ausente, vencido o revocado'],
                '403' => ['description' => 'El token no tiene el scope o la IP no está permitida'],
                '422' => ['description' => 'Datos inválidos (message y errors)'],
                '429' => ['description' => 'Límite de llamadas del token excedido'],
            ];
            $paths[$e['ruta']][strtolower($e['metodo'])] = $op;
        }
        ksort($paths);

        $scopes = collect($this->endpoints())->pluck('scope')->filter()->unique()->sort()->mapWithKeys(fn ($s) => [$s => $s])->all();

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'API v1 · Informe de ventas y documentos', 'version' => '1.0.0',
                'description' => 'Solo tokens de API (Authorization: Bearer). Montos en pesos chilenos enteros; fechas AAAA-MM-DD. Cada llamada queda registrada.'],
            'servers' => [['url' => rtrim(config('app.url'), '/').'/api/v1']],
            'components' => ['securitySchemes' => ['token' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Token creado en Configuración → Tokens de API. Scopes: '.implode(', ', $scopes)]]],
            'paths' => $paths,
        ];
    }
}
