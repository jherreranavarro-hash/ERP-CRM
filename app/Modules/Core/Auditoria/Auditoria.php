<?php

namespace App\Modules\Core\Auditoria;

use App\Modules\Core\Models\Trazabilidad;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Registro en la trazabilidad global (solo inserción).
 */
class Auditoria
{
    /** Atributos que nunca se copian a la trazabilidad. */
    private const OCULTOS = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'token', 'token_hash'];

    public function registrar(
        string $accion,
        ?Model $modelo = null,
        ?array $antes = null,
        ?array $despues = null,
        ?string $motivo = null,
        ?int $empresaId = null,
        ?int $userId = null,
        bool $global = false,
    ): Trazabilidad {
        $req = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return Trazabilidad::create([
            'empresa_id' => $global ? null : ($empresaId ?? ($modelo ? ($modelo->getAttributes()['empresa_id'] ?? null) : null) ?? app(Tenancy::class)->id()),
            'user_id' => $userId ?? Auth::id(),
            'accion' => $accion,
            'model_type' => $modelo ? $modelo->getMorphClass() : null,
            'model_id' => $modelo?->getKey(),
            'valor_anterior' => $antes ? $this->limpiar($antes) : null,
            'valor_nuevo' => $despues ? $this->limpiar($despues) : null,
            'motivo' => $motivo,
            'ip' => $req?->ip(),
            'user_agent' => $req ? substr((string) $req->userAgent(), 0, 255) : null,
            'canal' => $this->canal(),
        ]);
    }

    private function limpiar(array $datos): array
    {
        return array_diff_key($datos, array_flip(self::OCULTOS));
    }

    private function canal(): string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return 'consola';
        }

        return request()->is('api/*') ? 'api' : 'web';
    }
}
