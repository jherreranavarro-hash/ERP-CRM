<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Registro { id: number; fecha: string; usuario: string | null; accion: string; entidad: string | null; entidad_id: number | null; antes: Record<string, unknown> | null; despues: Record<string, unknown> | null; motivo: string | null; ip: string | null; canal: string }
const props = defineProps<{ registros: Paginado<Registro>; filtros: Record<string, string | undefined>; acciones: string[] }>();
const puede = usePermisos();

const f = reactive({ accion: props.filtros.accion ?? '', usuario: props.filtros.usuario ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '', entidad: props.filtros.entidad ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/configuracion/auditoria', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 350); });

const abierto = ref<number | null>(null);
const exportar = () => (window.location.href = `/configuracion/auditoria?${new URLSearchParams({ ...f, exportar: '1' })}`);

function tono(a: string): 'error' | 'alerta' | 'ok' | 'info' | 'neutro' {
    if (a.includes('fallido') || a.startsWith('eliminar') || a.includes('cierre_forzado') || a.includes('rechazar')) return 'error';
    if (a.startsWith('parametro') || a.startsWith('rol.') || a.startsWith('token')) return 'alerta';
    if (a.startsWith('login.exitoso') || a.startsWith('crear')) return 'ok';
    if (a.startsWith('editar')) return 'info';
    return 'neutro';
}
</script>

<template>
    <Head title="Tracking de actividades" />
    <Encabezado titulo="Tracking de actividades" bajada="Trazabilidad de solo lectura: nadie, ni SuperAdmin, puede modificar ni borrar estos registros.">
        <button v-if="puede('configuracion.tracking-de-actividades.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
    </Encabezado>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-2 xl:grid-cols-5">
            <select v-model="f.accion" class="campo h-11" aria-label="Acción"><option value="">Todas las acciones</option><option v-for="a in acciones" :key="a" :value="a">{{ a }}</option></select>
            <input v-model="f.usuario" class="campo h-11" placeholder="Usuario o correo" aria-label="Usuario" />
            <input v-model="f.entidad" class="campo h-11" placeholder="Entidad (ej. Local)" aria-label="Entidad" />
            <input v-model="f.desde" type="date" class="campo h-11" aria-label="Desde" />
            <input v-model="f.hasta" type="date" class="campo h-11" aria-label="Hasta" />
        </div>
        <div class="overflow-x-auto">
            <table v-if="registros.data.length" class="tabla">
                <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>IP · canal</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <template v-for="r in registros.data" :key="r.id">
                        <tr>
                            <td class="num whitespace-nowrap text-grafito">{{ fechaHora(r.fecha) }}</td>
                            <td class="font-semibold">{{ r.usuario ?? 'Sistema' }}</td>
                            <td><Chip chico :tono="tono(r.accion)">{{ r.accion }}</Chip></td>
                            <td>{{ r.entidad ? `${r.entidad} #${r.entidad_id}` : '—' }}</td>
                            <td class="num text-[14px] text-grafito">{{ r.ip ?? '—' }} · {{ r.canal }}</td>
                            <td class="text-right">
                                <button v-if="r.antes || r.despues || r.motivo" class="btn btn-secundario btn-sm" :aria-expanded="abierto === r.id" @click="abierto = abierto === r.id ? null : r.id">{{ abierto === r.id ? 'Ocultar' : 'Detalle' }}</button>
                            </td>
                        </tr>
                        <tr v-if="abierto === r.id">
                            <td colspan="6" class="bg-hormigon">
                                <p v-if="r.motivo" class="mb-2"><span class="font-semibold">Motivo:</span> {{ r.motivo }}</p>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div><p class="mb-1 text-[13px] font-semibold uppercase text-grafito">Antes</p><pre class="overflow-x-auto whitespace-pre-wrap rounded-boton bg-white p-3 text-[13px]">{{ r.antes ? JSON.stringify(r.antes, null, 2) : '—' }}</pre></div>
                                    <div><p class="mb-1 text-[13px] font-semibold uppercase text-grafito">Después</p><pre class="overflow-x-auto whitespace-pre-wrap rounded-boton bg-white p-3 text-[13px]">{{ r.despues ? JSON.stringify(r.despues, null, 2) : '—' }}</pre></div>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin registros con esos filtros" />
        </div>
        <Paginacion :datos="registros" nombre="registros" />
    </div>
</template>
