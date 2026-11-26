<?php

use App\Http\Controllers\Api\V1\VentasController;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| API pública versionada. Solo tokens Sanctum con scopes; límites por token
| (mantenedor "Límites de API"); cada llamada queda registrada.
| Documentación: docs/api-v1.md.
*/

Route::prefix('v1')->middleware(['auth:sanctum', 'token.api', 'throttle:api-token'])->group(function () {
    Route::get('/ping', fn () => ['ok' => true, 'hora' => now()->toIso8601String()]);

    Route::get('/empresa', function () {
        $e = app(Tenancy::class)->empresa();

        return ['data' => ['rut' => $e->rut, 'razon_social' => $e->razon_social, 'giro' => $e->giro]];
    })->middleware('scope:empresa:read');

    Route::get('/informe-ventas/resumen', [VentasController::class, 'resumen'])->middleware('scope:informe-ventas:read');
    Route::get('/informe-ventas/dtes', [VentasController::class, 'dtes'])->middleware('scope:informe-ventas:read');
    Route::get('/clientes', [VentasController::class, 'clientes'])->middleware('scope:clientes:read');
    Route::get('/dtes/{id}', [VentasController::class, 'dte'])->whereNumber('id')->middleware('scope:dtes:read');
    Route::post('/dtes', [VentasController::class, 'crear'])->middleware('scope:dtes:write');
});
