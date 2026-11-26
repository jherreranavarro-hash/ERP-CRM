<?php

namespace App\Modules\Core\Auditoria;

/**
 * Emite un evento de trazabilidad en cada alta, cambio, baja y restauración.
 * Las clases pueden definir $auditarExcluir con atributos a ignorar.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($m) => app(Auditoria::class)->registrar('crear', $m, null, $m->getAttributes()));

        static::updated(function ($m) {
            $cambios = array_diff_key($m->getChanges(), array_flip(['updated_at', ...($m->auditarExcluir ?? [])]));
            if ($cambios === []) {
                return;
            }
            $antes = array_intersect_key($m->getOriginal(), $cambios);
            app(Auditoria::class)->registrar('editar', $m, $antes, $cambios);
        });

        static::deleted(fn ($m) => app(Auditoria::class)->registrar(
            method_exists($m, 'isForceDeleting') && ! $m->isForceDeleting() ? 'eliminar' : 'eliminar_definitivo',
            $m, $m->getOriginal(), null));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($m) => app(Auditoria::class)->registrar('restaurar', $m));
        }
    }
}
