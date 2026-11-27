<?php

namespace App\Modules\Operacion;

use App\Models\User;
use App\Modules\Auth\Sesiones;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Permiso;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Rbac\Rbac;
use App\Modules\Operacion\Models\SolicitudPrivilegio;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cuatro ojos sobre "Roles y permisos (RBAC)", mantenedor sensible:
 *  - Agregar permisos a un rol, u otorgar la marca SuperAdmin, queda como solicitud
 *    que debe aprobar otra persona (SuperAdmin o con configuracion.perfiles.aprobar;
 *    para SuperAdmin, solo otro SuperAdmin).
 *  - Quitar permisos o revocar SuperAdmin rige de inmediato: reducir privilegios
 *    nunca espera, pero siempre queda al menos un SuperAdmin activo con 2FA (piso).
 */
class Privilegios
{
    public function __construct(private Auditoria $auditoria, private Alertas $alertas, private Rbac $rbac) {}

    /**
     * Aplica en el acto lo que quita y deja pendiente lo que agrega.
     *
     * @param  list<string>  $claves  matriz completa deseada
     * @return list<string> claves que quedaron pendientes de aprobación
     */
    public function cambiarPermisosRol(Rol $rol, array $claves, User $actor, ?string $motivo = null): array
    {
        return DB::transaction(function () use ($rol, $claves, $actor, $motivo) {
            $antes = $rol->permisos()->pluck('clave')->all();
            $deseados = array_values(array_unique($claves));
            $quitar = array_values(array_diff($antes, $deseados));
            $agregar = array_values(array_diff($deseados, $antes));

            if ($quitar) {
                $rol->permisos()->detach(Permiso::whereIn('clave', $quitar)->pluck('id'));
                $this->auditoria->registrar('rol.permisos_quitados', $rol, ['permisos' => $quitar], null, $motivo, global: true);
                $this->rbac->olvidar();
            }

            $pendiente = SolicitudPrivilegio::where('tipo', 'rol_permisos')->where('rol_id', $rol->id)->where('estado', 'pendiente')->lockForUpdate()->first();
            if ($pendiente) {
                // La solicitud vigente se reemplaza por la matriz nueva y pasa a nombre de quien editó.
                $agregar = array_values(array_intersect(array_unique([...$pendiente->permisos, ...$agregar]), $deseados));
                if (! $agregar) {
                    $pendiente->forceFill(['estado' => 'anulada', 'resuelto_at' => now(), 'comentario' => 'Reemplazada por una edición del rol sin permisos nuevos.'])->save();

                    return [];
                }
                $pendiente->forceFill(['permisos' => $agregar, 'solicitado_por' => $actor->id, 'motivo' => $this->motivo($motivo, $rol)])->save();
                $this->auditoria->registrar('rol.permisos_solicitados', $rol, null, ['permisos' => $agregar], $motivo, global: true);

                return $agregar;
            }

            if (! $agregar) {
                return [];
            }

            $s = SolicitudPrivilegio::create([
                'tipo' => 'rol_permisos', 'rol_id' => $rol->id, 'permisos' => $agregar,
                'motivo' => $this->motivo($motivo, $rol), 'solicitado_por' => $actor->id,
            ]);
            $this->auditoria->registrar('rol.permisos_solicitados', $rol, null, ['permisos' => $agregar], $motivo, global: true);
            $this->alertas->emitir('privilegio_solicitado', "Permisos pendientes de aprobación para el rol {$rol->nombre}", [
                'rol' => $rol->nombre, 'permisos' => count($agregar), 'solicitado_por' => $actor->name,
            ], "solicitud:{$s->id}", global: true);

            return $agregar;
        });
    }

    public function solicitarSuperadmin(User $objetivo, User $actor, string $motivo): SolicitudPrivilegio
    {
        if (! $actor->es_superadmin) {
            throw ValidationException::withMessages(['superadmin' => 'Solo un SuperAdmin puede proponer otro SuperAdmin.']);
        }
        if ($objetivo->es_superadmin) {
            throw ValidationException::withMessages(['superadmin' => 'El usuario ya es SuperAdmin.']);
        }
        if ($objetivo->id === $actor->id) {
            throw ValidationException::withMessages(['superadmin' => 'Nadie puede proponerse a sí mismo.']);
        }
        if (SolicitudPrivilegio::where('tipo', 'superadmin')->where('user_id', $objetivo->id)->where('estado', 'pendiente')->exists()) {
            throw ValidationException::withMessages(['superadmin' => 'Ya hay una solicitud pendiente para este usuario.']);
        }

        $s = SolicitudPrivilegio::create([
            'tipo' => 'superadmin', 'user_id' => $objetivo->id, 'otorgar' => true,
            'motivo' => mb_substr($motivo, 0, 500), 'solicitado_por' => $actor->id,
        ]);
        $this->auditoria->registrar('usuario.superadmin_solicitado', $objetivo, null, ['solicitud' => $s->id], $motivo, global: true);
        $this->alertas->emitir('privilegio_solicitado', "Se propuso a {$objetivo->name} como SuperAdmin", [
            'usuario' => $objetivo->name, 'solicitado_por' => $actor->name,
        ], "solicitud:{$s->id}", global: true);

        return $s;
    }

    public function revocarSuperadmin(User $objetivo, User $actor, string $motivo): void
    {
        if (! $actor->es_superadmin) {
            throw ValidationException::withMessages(['superadmin' => 'Solo un SuperAdmin puede revocar la marca.']);
        }
        DB::transaction(function () use ($objetivo, $actor, $motivo) {
            // Bloquea a los SuperAdmin para que dos revocaciones simultáneas no dejen el sistema sin ninguno.
            $restantes = User::where('es_superadmin', true)->where('activo', true)->whereNotNull('two_factor_confirmed_at')
                ->where('id', '<>', $objetivo->id)->lockForUpdate()->pluck('id')->count();
            if ($restantes < 1) {
                throw ValidationException::withMessages(['superadmin' => 'Debe quedar al menos un SuperAdmin activo con 2FA.']);
            }
            $objetivo->forceFill(['es_superadmin' => false])->save();
            app(Sesiones::class)->cerrarTodas($objetivo);
            $this->auditoria->registrar('usuario.superadmin_revocado', $objetivo, ['es_superadmin' => true], ['es_superadmin' => false], $motivo, userId: $actor->id, global: true);
        });
        $this->alertas->emitir('superadmin_revocado', "Se revocó SuperAdmin a {$objetivo->name}", ['revocado_por' => $actor->name], "user:{$objetivo->id}", global: true, ventanaHoras: 0);
    }

    public function aprobar(SolicitudPrivilegio $s, User $aprobador, ?string $comentario = null): void
    {
        $this->exigirResoluble($s, $aprobador);

        DB::transaction(function () use ($s, $aprobador, $comentario) {
            $s = SolicitudPrivilegio::lockForUpdate()->findOrFail($s->id);
            if ($s->estado !== 'pendiente') {
                throw ValidationException::withMessages(['solicitud' => 'La solicitud ya fue resuelta.']);
            }

            if ($s->tipo === 'rol_permisos') {
                $rol = Rol::findOrFail($s->rol_id);
                $rol->permisos()->syncWithoutDetaching(Permiso::whereIn('clave', $s->permisos)->pluck('id'));
                $this->auditoria->registrar('rol.permisos_aprobados', $rol, null, ['permisos' => $s->permisos, 'solicitud' => $s->id], $comentario, global: true);
            } else {
                $u = User::findOrFail($s->user_id);
                if (! $u->activo || ! $u->two_factor_confirmed_at) {
                    throw ValidationException::withMessages(['solicitud' => 'El usuario debe estar activo y con 2FA confirmado para ser SuperAdmin.']);
                }
                $u->forceFill(['es_superadmin' => true])->save();
                $this->auditoria->registrar('usuario.superadmin_otorgado', $u, ['es_superadmin' => false], ['es_superadmin' => true, 'solicitud' => $s->id], $comentario, global: true);
            }

            $s->forceFill(['estado' => 'aprobada', 'resuelto_por' => $aprobador->id, 'resuelto_at' => now(), 'comentario' => $comentario])->save();
        });
        $this->rbac->olvidar();
    }

    public function rechazar(SolicitudPrivilegio $s, User $aprobador, string $comentario): void
    {
        $this->exigirResoluble($s, $aprobador);
        $s->forceFill(['estado' => 'rechazada', 'resuelto_por' => $aprobador->id, 'resuelto_at' => now(), 'comentario' => mb_substr($comentario, 0, 500)])->save();
        $this->auditoria->registrar('privilegio.solicitud_rechazada', $s->tipo === 'rol_permisos' ? $s->rol : $s->usuario, null, ['solicitud' => $s->id], $comentario, global: true);
    }

    public function anular(SolicitudPrivilegio $s, User $actor): void
    {
        if ($s->estado !== 'pendiente' || (int) $s->solicitado_por !== $actor->id) {
            throw ValidationException::withMessages(['solicitud' => 'Solo quien la pidió puede anular una solicitud pendiente.']);
        }
        $s->forceFill(['estado' => 'anulada', 'resuelto_at' => now()])->save();
    }

    private function exigirResoluble(SolicitudPrivilegio $s, User $u): void
    {
        if ($s->estado !== 'pendiente') {
            throw ValidationException::withMessages(['solicitud' => 'La solicitud ya fue resuelta.']);
        }
        if ((int) $s->solicitado_por === $u->id) {
            throw ValidationException::withMessages(['solicitud' => 'Nadie aprueba su propia solicitud: debe hacerlo otra persona.']);
        }
        if ($s->tipo === 'superadmin' && (! $u->es_superadmin || (int) $s->user_id === $u->id)) {
            throw ValidationException::withMessages(['solicitud' => 'Solo otro SuperAdmin puede resolver esta solicitud.']);
        }
        if ($s->tipo === 'rol_permisos' && ! $this->rbac->permite($u, 'configuracion.perfiles.aprobar')) {
            throw ValidationException::withMessages(['solicitud' => 'No tienes permiso para aprobar cambios de roles.']);
        }
    }

    private function motivo(?string $motivo, Rol $rol): string
    {
        return mb_substr(trim((string) $motivo) ?: "Edición del rol {$rol->nombre}", 0, 500);
    }
}
