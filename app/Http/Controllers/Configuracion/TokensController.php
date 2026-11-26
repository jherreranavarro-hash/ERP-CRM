<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\TokenAcceso;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Configuración → Tokens de API: crear, revocar y ver último uso. Sin vencimiento o sin scope no se crea. */
class TokensController extends Controller
{
    /** Scopes disponibles. Fase 2 agrega informe-ventas, clientes y dtes. */
    public const SCOPES = [
        'empresa:read' => 'Leer datos de la empresa',
        'informe-ventas:read' => 'Leer informe de ventas',
        'clientes:read' => 'Leer clientes',
        'dtes:read' => 'Leer documentos tributarios',
        'dtes:write' => 'Emitir documentos tributarios',
    ];

    public function index()
    {
        return Inertia::render('Configuracion/Tokens/Index', [
            'tokens' => TokenAcceso::query()->where('empresa_id', app(Tenancy::class)->id())->orderByDesc('id')->get()
                ->map(fn (TokenAcceso $t) => [
                    'id' => $t->id, 'nombre' => $t->name, 'scopes' => $t->abilities,
                    'vence' => $t->expires_at?->toIso8601String(), 'ultimo_uso' => $t->last_used_at?->toIso8601String(),
                    'ips' => $t->ips_permitidas ?? [], 'limite' => $t->limite_por_minuto,
                    'revocado' => (bool) $t->revocado_at, 'vencido' => $t->expires_at?->isPast() ?? true,
                ]),
            'scopes' => self::SCOPES,
            'maxDias' => app(Parametros::class)->valor('tokens.vencimiento_dias'),
            'tokenNuevo' => session('token_nuevo'),
        ]);
    }

    public function store(Request $request)
    {
        $max = (int) app(Parametros::class)->valor('tokens.vencimiento_dias');
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', array_keys(self::SCOPES))],
            'dias' => ['required', 'integer', 'min:1', "max:{$max}"],
            'ips' => ['array'],
            'ips.*' => ['string', function ($a, $v, $fail) {
                if (! filter_var(explode('/', $v)[0], FILTER_VALIDATE_IP)) {
                    $fail("IP o rango no válido: {$v}");
                }
            }],
            'limite' => ['nullable', 'integer', 'min:1', 'max:'.app(Parametros::class)->valor('api.solicitudes_por_minuto')],
        ], ['scopes.required' => 'Elige al menos un scope: ningún token se crea sin scope.']);

        $nuevo = $request->user()->createToken($data['nombre'], $data['scopes'], now()->addDays($data['dias']));
        $nuevo->accessToken->forceFill([
            'empresa_id' => app(Tenancy::class)->id(),
            'ips_permitidas' => ($data['ips'] ?? []) ?: null,
            'limite_por_minuto' => $data['limite'] ?? null,
            'creado_por' => $request->user()->id,
        ])->save();

        app(Auditoria::class)->registrar('token_api.crear', $nuevo->accessToken, null, ['nombre' => $data['nombre'], 'scopes' => $data['scopes'], 'dias' => $data['dias']]);

        // El texto del token se muestra una sola vez.
        return back()->with('token_nuevo', $nuevo->plainTextToken);
    }

    public function destroy(TokenAcceso $token)
    {
        abort_unless($token->empresa_id === app(Tenancy::class)->id(), 404);
        $token->forceFill(['revocado_at' => now()])->save();
        app(Auditoria::class)->registrar('token_api.revocar', $token);

        return back()->with('exito', 'Token revocado.');
    }
}
