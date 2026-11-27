<?php

namespace App\Modules\Operacion;

use App\Models\User;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Operacion\Models\Alerta;
use App\Notifications\AlertaSistema;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Mantenedor "Notificaciones y alertas": cada alerta queda en el panel y, si el canal
 * "correo" está activo, se envía a los destinatarios de su tipo. Las de seguridad
 * siempre tienen al menos un destinatario (piso del mantenedor). La misma alerta
 * (código + clave) no se repite dentro de la ventana, para no inundar a nadie.
 */
class Alertas
{
    /** Códigos de seguridad; el resto son operativos. */
    public const SEGURIDAD = ['api_anomalia', 'api_errores', 'privilegio_solicitado', 'superadmin_revocado', 'respaldo_fallido', 'restauracion_fallida'];

    public const CODIGOS = [
        'api_anomalia' => 'Uso anómalo de API',
        'api_errores' => 'Errores repetidos de API',
        'privilegio_solicitado' => 'Solicitud de privilegios',
        'superadmin_revocado' => 'SuperAdmin revocado',
        'respaldo_fallido' => 'Respaldo fallido',
        'respaldo_vencido' => 'Respaldo atrasado',
        'restauracion_fallida' => 'Prueba de restauración fallida',
        'restauracion_vencida' => 'Prueba de restauración atrasada',
        'folios_por_agotarse' => 'Folios por agotarse',
        'dte_rechazado' => 'DTE rechazado',
        'diferencia_caja' => 'Diferencia de caja',
        'integracion_caida' => 'Integración caída',
    ];

    public function __construct(private Parametros $parametros, private Tenancy $tenancy) {}

    /**
     * @param  array<string, mixed>  $detalle  sin datos personales ni secretos
     */
    public function emitir(string $codigo, string $titulo, array $detalle = [], ?string $clave = null, bool $global = false, int $ventanaHoras = 24): ?Alerta
    {
        $empresaId = $global ? null : $this->tenancy->id();
        $clave = mb_substr($clave ?? $codigo, 0, 120);

        $repetida = Alerta::query()
            ->where('codigo', $codigo)->where('clave', $clave)
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId), fn ($q) => $q->whereNull('empresa_id'))
            ->whereNull('atendida_at')
            ->where('created_at', '>=', now()->subHours($ventanaHoras))
            ->exists();
        if ($repetida) {
            return null;
        }

        $tipo = in_array($codigo, self::SEGURIDAD, true) ? 'seguridad' : 'operacion';
        $destinatarios = $this->destinatarios($tipo);

        $alerta = Alerta::create([
            'empresa_id' => $empresaId, 'tipo' => $tipo, 'codigo' => $codigo, 'clave' => $clave,
            'titulo' => mb_substr($titulo, 0, 200), 'detalle' => $detalle ?: null,
            'notificada_a' => $destinatarios ?: null,
        ]);

        if ($destinatarios) {
            try {
                Notification::route('mail', $destinatarios)->notify(new AlertaSistema($alerta));
            } catch (Throwable $e) {
                // Un correo caído no puede botar la operación que originó la alerta: queda en el panel.
                report($e);
            }
        }

        return $alerta;
    }

    public function atender(Alerta $alerta, User $user): void
    {
        if ($alerta->atendida_at) {
            return;
        }
        $alerta->forceFill(['atendida_por' => $user->id, 'atendida_at' => now()])->save();
    }

    /** @return list<string> */
    private function destinatarios(string $tipo): array
    {
        $canales = (array) $this->parametros->valor('notificaciones.canales');
        if (! in_array('correo', $canales, true) && $tipo !== 'seguridad') {
            return [];
        }
        $lista = (array) $this->parametros->valor($tipo === 'seguridad' ? 'notificaciones.seguridad' : 'notificaciones.operacion');
        if ($tipo === 'operacion' && ! $lista) {
            // Sin destinatarios operativos, avisa al menos a seguridad para que nada quede sin dueño.
            $lista = (array) $this->parametros->valor('notificaciones.seguridad');
        }

        return array_values(array_unique(array_filter($lista, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))));
    }
}
