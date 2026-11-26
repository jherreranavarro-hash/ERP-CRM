<?php

namespace App\Http\Middleware;

use App\Modules\Core\Models\ApiRegistro;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\TokenAcceso;
use App\Modules\Core\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * API pública: solo tokens Sanctum (nunca la cookie del navegador), con
 * vencimiento obligatorio, IP permitidas y empresa propia. Cada llamada queda
 * en api_registros, separado del log de la aplicación web.
 */
class AutenticarTokenApi
{
    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $inicio = microtime(true);
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof TokenAcceso) {
            return $this->error('Se requiere un token de API (Authorization: Bearer).', 401);
        }
        if ($token->revocado_at || ! $token->expires_at || $token->expires_at->isPast()) {
            return $this->error('El token está revocado o vencido.', 401);
        }
        if ($token->ips_permitidas && ! IpUtils::checkIp((string) $request->ip(), $token->ips_permitidas)) {
            return $this->error('IP no permitida para este token.', 403);
        }
        $empresa = Empresa::where('activo', true)->find($token->empresa_id);
        if (! $empresa) {
            return $this->error('El token no está asociado a una empresa activa.', 403);
        }

        $this->tenancy->establecer($empresa);
        try {
            $response = $next($request);
            ApiRegistro::create([
                'empresa_id' => $empresa->id,
                'token_id' => $token->id,
                'user_id' => $token->tokenable_id,
                'metodo' => $request->method(),
                'ruta' => substr($request->path(), 0, 255),
                'estado_http' => $response->getStatusCode(),
                'duracion_ms' => (int) ((microtime(true) - $inicio) * 1000),
                'ip' => $request->ip(),
            ]);

            return $response;
        } finally {
            $this->tenancy->limpiar();
        }
    }

    private function error(string $m, int $status): Response
    {
        return response()->json(['message' => $m], $status);
    }
}
