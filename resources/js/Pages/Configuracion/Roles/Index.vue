<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Icono from '@/Components/Icono.vue';
import { usePermisos } from '@/lib/permisos';

interface Rol { id: number; nombre: string; descripcion: string | null; perfil: string; activo: boolean; permisos: number; usuarios: number }
defineProps<{ roles: Rol[] }>();
const puede = usePermisos();

function eliminar(r: Rol) {
    if (confirm(`¿Eliminar el rol ${r.nombre}?`)) router.delete(`/configuracion/roles/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Perfiles y roles" />
    <Encabezado titulo="Perfiles y roles" bajada="Cada rol pertenece a un perfil y define qué puede hacer en cada submódulo. El backend verifica cada permiso; ocultar un menú no basta.">
        <Link v-if="puede('configuracion.perfiles.crear')" href="/configuracion/roles/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nuevo rol</Link>
    </Encabezado>

    <div class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Rol</th><th>Perfil</th><th class="text-right">Permisos</th><th class="text-right">Usuarios</th><th>Estado</th><th class="w-px"></th></tr></thead>
            <tbody>
                <tr v-for="r in roles" :key="r.id">
                    <td><div class="font-semibold">{{ r.nombre }}</div><div v-if="r.descripcion" class="text-[14px] text-grafito">{{ r.descripcion }}</div></td>
                    <td>{{ r.perfil }}</td>
                    <td class="num text-right">{{ r.permisos }}</td>
                    <td class="num text-right">{{ r.usuarios }}</td>
                    <td><Chip chico :tono="r.activo ? 'ok' : 'neutro'">{{ r.activo ? 'Activo' : 'Inactivo' }}</Chip></td>
                    <td class="whitespace-nowrap text-right">
                        <div class="flex justify-end gap-2">
                            <Link v-if="puede('configuracion.perfiles.editar')" :href="`/configuracion/roles/${r.id}/editar`" class="btn btn-secundario btn-sm no-underline">Editar permisos</Link>
                            <MenuAcciones v-if="puede('configuracion.perfiles.eliminar') && !r.usuarios" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(r) }]" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
