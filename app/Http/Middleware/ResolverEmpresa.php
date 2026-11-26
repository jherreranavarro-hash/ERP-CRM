<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resuelve la empresa activa desde la membresía del usuario con sesión (nunca
 * desde el cuerpo de la petición) y la fija en la conexión para el RLS.
 */
class ResolverEmpresa
{
    public function __construct(private Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $empresa = $this->resolver($user, $request->session()->get('empresa_id'));

        if (! $empresa) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => 'Tu usuario no tiene una empresa activa asignada.']);
        }

        $request->session()->put('empresa_id', $empresa->id);
        $this->tenancy->establecer($empresa);
        // Quién hace el cambio, para los registros que escribe la base de datos (historial de precios).
        DB::select("select set_config('app.usuario_id', ?, false)", [(string) $user->id]);

        try {
            return $next($request);
        } finally {
            $this->tenancy->limpiar();
            DB::select("select set_config('app.usuario_id', '', false)");
        }
    }

    private function resolver(User $user, ?int $solicitada): ?Empresa
    {
        $q = $user->es_superadmin ? Empresa::query()->where('activo', true) : $user->empresas();

        if ($solicitada && ($e = (clone $q)->where('empresas.id', $solicitada)->first())) {
            return $e;
        }

        return $q->orderBy('empresas.id')->first();
    }
}
