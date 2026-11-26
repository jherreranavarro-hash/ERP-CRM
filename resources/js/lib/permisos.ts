import { usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';

/** Solo para mostrar u ocultar controles: el backend verifica cada permiso igual. */
export function usePermisos() {
    const page = usePage<PageProps>();
    return (permiso: string): boolean => page.props.auth.permisos.includes(permiso);
}
