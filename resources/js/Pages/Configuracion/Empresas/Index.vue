<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Icono from '@/Components/Icono.vue';
import { usePermisos } from '@/lib/permisos';

interface Empresa { id: number; rut: string; razon_social: string; nombre_fantasia: string | null; comuna: string | null; activo: boolean; eliminada: boolean; es_activa: boolean }
const props = defineProps<{ empresas: Paginado<Empresa>; filtros: { buscar?: string; estado?: string }; puedeEditarTodas: boolean }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/configuracion/empresas', { ...f }, { preserveState: true, replace: true }), 300); });

function eliminar(e: Empresa) {
    if (confirm(`¿Eliminar ${e.razon_social}? Queda como eliminación lógica y se puede auditar.`)) router.delete(`/configuracion/empresas/${e.id}`, { preserveScroll: true });
}
const exportar = () => (window.location.href = `/configuracion/empresas?${new URLSearchParams({ ...f, exportar: '1' })}`);
</script>

<template>
    <Head title="Negocio" />
    <Encabezado titulo="Negocio" bajada="Empresas emisoras (tenants): datos tributarios del emisor que usará la facturación.">
        <button v-if="puede('configuracion.negocio.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <Link v-if="puedeEditarTodas" href="/configuracion/empresas/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nueva empresa</Link>
    </Encabezado>

    <div class="tarjeta">
        <div class="flex flex-wrap gap-3 border-b border-linea p-4">
            <div class="relative min-w-[240px] flex-1">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Buscar por RUT o razón social" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11 w-auto" aria-label="Estado">
                <option value="">Activas</option>
                <option value="inactivas">Inactivas</option>
                <option value="eliminadas">Eliminadas</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="empresas.data.length" class="tabla">
                <thead><tr><th>RUT</th><th>Razón social</th><th>Comuna</th><th>Estado</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="e in empresas.data" :key="e.id">
                        <td class="num whitespace-nowrap font-semibold">{{ e.rut }}</td>
                        <td>
                            <div class="font-semibold">{{ e.razon_social }}</div>
                            <div v-if="e.nombre_fantasia" class="text-[14px] text-grafito">{{ e.nombre_fantasia }}</div>
                        </td>
                        <td>{{ e.comuna ?? '—' }}</td>
                        <td class="space-x-2 whitespace-nowrap">
                            <Chip chico :tono="e.eliminada ? 'error' : e.activo ? 'ok' : 'neutro'">{{ e.eliminada ? 'Eliminada' : e.activo ? 'Activa' : 'Inactiva' }}</Chip>
                            <Chip v-if="e.es_activa" chico tono="naranja">Empresa en uso</Chip>
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <div v-if="!e.eliminada" class="flex justify-end gap-2">
                                <Link v-if="puede('configuracion.negocio.editar') && (puedeEditarTodas || e.es_activa)" :href="`/configuracion/empresas/${e.id}/editar`" class="btn btn-secundario btn-sm no-underline">Editar</Link>
                                <MenuAcciones v-if="puedeEditarTodas && !e.es_activa" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(e) }]" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="No hay empresas con ese filtro" />
        </div>
        <Paginacion :datos="empresas" nombre="empresas" />
    </div>
</template>
