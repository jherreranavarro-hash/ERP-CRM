<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Support\Api\OpenApi;
use Inertia\Inertia;

/** Configuración → Documentación Informe Ventas: OpenAPI generada desde las rutas de la API v1. */
class DocumentacionApiController extends Controller
{
    public function index(OpenApi $api)
    {
        $spec = $api->generar();

        return Inertia::render('Configuracion/DocumentacionApi', [
            'base' => $spec['servers'][0]['url'],
            'endpoints' => collect($api->endpoints())->map(function ($e) use ($spec) {
                $op = $spec['paths'][$e['ruta']][strtolower($e['metodo'])];
                $ok = collect($op['responses'])->keys()->first();

                return ['metodo' => $e['metodo'], 'ruta' => $e['ruta'], 'scope' => $e['scope'], 'documentada' => $e['documentada'], 'tag' => $e['tag'],
                    'resumen' => $e['resumen'], 'descripcion' => $e['descripcion'],
                    'parametros' => collect($op['parameters'] ?? [])->map(fn ($p) => ['nombre' => $p['name'], 'en' => $p['in'], 'requerido' => (bool) ($p['required'] ?? false),
                        'tipo' => $p['schema']['type'] ?? 'string', 'valores' => $p['schema']['enum'] ?? null, 'descripcion' => $p['description'] ?? null])->values(),
                    'cuerpo' => $op['requestBody']['content']['application/json']['example'] ?? null,
                    'codigo' => (string) $ok, 'respuesta' => $op['responses'][$ok]['content']['application/json']['example'] ?? null];
            })->sortBy([['tag', 'asc'], ['ruta', 'asc']])->values(),
        ]);
    }

    public function json(OpenApi $api)
    {
        return response()->json($api->generar(), 200, ['Content-Disposition' => 'attachment; filename="openapi-v1.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
