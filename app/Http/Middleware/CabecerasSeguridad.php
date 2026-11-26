<?php

namespace App\Http\Middleware;

use App\Modules\Core\Parametros\Parametros;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en todas las respuestas. HSTS, X-Frame-Options DENY y
 * la ocultación de versiones son pisos fijos; los dominios de la CSP vienen del
 * mantenedor "Cabeceras y política de contenido".
 */
class CabecerasSeguridad
{
    public function __construct(private Parametros $parametros) {}

    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $h = $response->headers;
        $h->remove('X-Powered-By');
        $h->remove('Server');
        header_remove('X-Powered-By');

        $h->set('X-Frame-Options', 'DENY');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure() || app()->isProduction()) {
            $h->set('Strict-Transport-Security', 'max-age='.(int) $this->parametros->valor('cabeceras.hsts_max_age').'; includeSubDomains; preload');
        }

        $csp = $this->csp();
        $h->set($this->parametros->valor('cabeceras.csp_solo_reportar') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy', $csp);

        return $response;
    }

    private function csp(): string
    {
        $nonce = Vite::cspNonce();
        $dev = app()->isLocal() && is_file(public_path('hot')) ? trim((string) file_get_contents(public_path('hot'))) : null;
        $devWs = $dev ? preg_replace('#^http#', 'ws', $dev) : null;
        $extra = fn (string $p) => implode(' ', $this->parametros->valor($p));
        // Servidor de tiempo real (Reverb) para el wallboard del contact center.
        $rt = config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.options.host')
            ? (config('broadcasting.connections.reverb.options.scheme') === 'https' ? 'wss://' : 'ws://').config('broadcasting.connections.reverb.options.host').':'.config('broadcasting.connections.reverb.options.port')
            : null;

        return implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' {$dev} ".$extra('cabeceras.csp_scripts'),
            // Vue aplica estilos en línea por atributo; no se permiten <style> de terceros.
            "style-src 'self' 'unsafe-inline' {$dev}",
            "img-src 'self' data: blob: ".$extra('cabeceras.csp_imagenes'),
            "font-src 'self' data: {$dev}",
            "connect-src 'self' {$dev} {$devWs} {$rt} ".$extra('cabeceras.csp_conexiones'),
            ($f = $extra('cabeceras.csp_frames')) ? "frame-src {$f}" : "frame-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]));
    }
}
