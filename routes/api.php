<?php

use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| API pública versionada. Solo tokens Sanctum con scopes; límites por token
| (mantenedor "Límites de API"); cada llamada queda registrada.
| Los endpoints de negocio (informe de ventas, DTE) se agregan en la fase 2.
*/

Route::prefix('v1')->middleware(['auth:sanctum', 'token.api', 'throttle:api-token'])->group(function () {
    Route::get('/ping', fn () => ['ok' => true, 'hora' => now()->toIso8601String()]);

    Route::get('/empresa', function () {
        $e = app(Tenancy::class)->empresa();

        return ['data' => ['rut' => $e->rut, 'razon_social' => $e->razon_social, 'giro' => $e->giro]];
    })->middleware('scope:empresa:read');
});
