<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Auth\Captcha\CaptchaAritmetico;
use App\Modules\Auth\Captcha\VerificadorCaptcha;
use App\Modules\Facturacion\Sii\ProveedorDTE;
use App\Modules\Facturacion\Sii\ProveedorDteSimulado;
use App\Modules\Auth\EventosAutenticacion;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\TokenAcceso;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Rbac\Rbac;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Estado por petición (o por job): se recrean en cada ciclo.
        $this->app->scoped(Tenancy::class);
        $this->app->scoped(Rbac::class);
        $this->app->scoped(Parametros::class);
        $this->app->singleton(Auditoria::class);
        $this->app->bind(VerificadorCaptcha::class, CaptchaAritmetico::class);
        // Proveedor DTE real pendiente de elección; mientras tanto, el simulado.
        $this->app->bind(ProveedorDTE::class, ProveedorDteSimulado::class);
        // RRHH: firma electrónica simulada y lector del archivo del reloj control (proveedores reales en fase 4).
        $this->app->bind(\App\Modules\RRHH\Integraciones\FirmaElectronica::class, \App\Modules\RRHH\Integraciones\FirmaSimulada::class);
        $this->app->bind(\App\Modules\RRHH\Integraciones\RelojControl::class, \App\Modules\RRHH\Integraciones\RelojCsv::class);
        // Transportistas externos: por ahora solo el simulado (integraciones reales en fase 4).
        $this->app->singleton(\App\Modules\Inventario\Transporte\Transportistas::class, fn () => new \App\Modules\Inventario\Transporte\Transportistas([new \App\Modules\Inventario\Transporte\TransportistaSimulado]));
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Sanctum::usePersonalAccessTokenModel(TokenAcceso::class);

        // Los permisos se verifican en el backend: Gate y @can delegan en el RBAC.
        Gate::before(fn (User $user, string $ability) => str_contains($ability, '.') ? $user->tienePermiso($ability) : null);

        Event::subscribe(EventosAutenticacion::class);
        // Eventos de dominio entre módulos (síncronos, dentro de la transacción que los origina).
        Event::listen(\App\Modules\Facturacion\Eventos\DocumentoEmitido::class, \App\Modules\Inventario\Escuchas\MoverStockAlEmitir::class);
        Event::listen(\App\Modules\Facturacion\Eventos\DocumentoEmitido::class, \App\Modules\Finanzas\Escuchas\ContabilizarDocumento::class);
        Event::listen(\App\Modules\Facturacion\Eventos\DocumentoEmitido::class, \App\Modules\RRHH\Escuchas\RegistrarComision::class);

        RateLimiter::for('api-token', function (Request $request) {
            $token = $request->user()?->currentAccessToken();
            $limite = $token?->limite_por_minuto ?: (int) app(Parametros::class)->valor('api.solicitudes_por_minuto');

            return Limit::perMinute($limite)->by('token:'.($token?->id ?? $request->ip()));
        });
    }
}
