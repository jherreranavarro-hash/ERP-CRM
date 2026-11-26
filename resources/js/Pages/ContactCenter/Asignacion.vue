<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import type { Paginado } from '@/types';
import { estadoCampana, estadoLead } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface AgenteCarga { id: number; nombre: string; abiertos: number; capacidad: number; zonas: string[] }
interface Campana { id: number; nombre: string; estado: string; regla: string; sin_asignar: number; agentes: AgenteCarga[] }
interface Lead { id: number; nombre: string; comuna: string | null; estado: string; agente_id: number | null; agente: string | null }
const props = defineProps<{ campanas: Campana[]; campanaId: number | null; leads: Paginado<Lead> | null; reglas: Record<string, string>; estadosLead: Record<string, string> }>();
const puede = usePermisos();
const actual = computed(() => props.campanas.find((c) => c.id === props.campanaId) ?? null);
const ver = (id: number) => router.get('/ventas/asignacion', { campana: id }, { preserveScroll: true });
const asignar = (c: Campana) => router.post(`/ventas/asignacion/${c.id}`, {}, { preserveScroll: true });

const marcados = ref<number[]>([]);
watch(() => props.leads?.current_page, () => (marcados.value = []));
const destino = ref<number | null>(null);
const todos = computed({
    get: () => !!props.leads?.data.length && props.leads.data.every((l) => marcados.value.includes(l.id)),
    set: (v: boolean) => { marcados.value = v ? (props.leads?.data.map((l) => l.id) ?? []) : []; },
});
const reasignar = () => router.post('/ventas/asignacion/reasignar', { leads: marcados.value, agente_id: destino.value }, { preserveScroll: true, onSuccess: () => { marcados.value = []; } });
</script>

<template>
    <Head title="Asignación de prospectos" />
    <Encabezado titulo="Asignación de prospectos" bajada="Reparte los prospectos sin agente según la regla de cada campaña (rotativa, por zona o por carga) respetando la capacidad de cada agente." />

    <div v-if="campanas.length" class="mb-6 grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        <div v-for="c in campanas" :key="c.id" class="tarjeta p-4" :class="{ 'ring-2 ring-naranja': c.id === campanaId }">
            <div class="mb-2 flex items-start justify-between gap-2">
                <div>
                    <button class="text-left font-bold underline-offset-2 hover:underline" @click="ver(c.id)">{{ c.nombre }}</button>
                    <p class="text-[13px] text-grafito">{{ reglas[c.regla] }}</p>
                </div>
                <Chip chico :tono="estadoCampana[c.estado][0]">{{ estadoCampana[c.estado][1] }}</Chip>
            </div>
            <ul class="mb-3 space-y-1 text-[14px]">
                <li v-for="a in c.agentes" :key="a.id" class="flex justify-between gap-2">
                    <span>{{ a.nombre }}<span v-if="a.zonas.length" class="text-grafito"> · {{ a.zonas.join(', ') }}</span></span>
                    <span class="num" :class="{ 'font-semibold text-estado-error': a.abiertos >= a.capacidad }">{{ a.abiertos }}/{{ a.capacidad }}</span>
                </li>
                <li v-if="!c.agentes.length" class="text-grafito">Sin agentes en la campaña.</li>
            </ul>
            <div class="flex items-center justify-between gap-2">
                <span class="num text-[14px]"><strong>{{ c.sin_asignar }}</strong> sin asignar</span>
                <button v-if="puede('ventascrm.asignacion.editar')" class="btn btn-primario btn-sm" :disabled="!c.sin_asignar || !c.agentes.length" @click="asignar(c)">Asignar ahora</button>
            </div>
        </div>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin campañas abiertas" texto="Crea una campaña y agrégale agentes para repartir prospectos." />

    <div v-if="actual && leads" class="tarjeta">
        <div class="flex flex-wrap items-center gap-2 border-b border-linea p-4">
            <h2 class="mr-auto text-[18px] font-bold">Prospectos abiertos · <Link :href="`/ventas/campanas/${actual.id}`">{{ actual.nombre }}</Link></h2>
            <template v-if="puede('ventascrm.asignacion.editar')">
                <select v-model="destino" class="campo h-9 w-56" aria-label="Agente de destino"><option :value="null" disabled>Reasignar a…</option><option v-for="a in actual.agentes" :key="a.id" :value="a.id">{{ a.nombre }}</option></select>
                <button class="btn btn-oscuro btn-sm" :disabled="!marcados.length || !destino" @click="reasignar">Reasignar {{ marcados.length || '' }}</button>
            </template>
        </div>
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th class="w-10"><input v-model="todos" type="checkbox" aria-label="Marcar todos" /></th><th>Prospecto</th><th>Comuna</th><th>Estado</th><th>Agente</th></tr></thead>
                <tbody>
                    <tr v-for="l in leads.data" :key="l.id">
                        <td><input v-model="marcados" type="checkbox" :value="l.id" :aria-label="`Marcar ${l.nombre}`" /></td>
                        <td class="font-semibold">{{ l.nombre }}</td>
                        <td>{{ l.comuna ?? '—' }}</td>
                        <td><Chip chico :tono="estadoLead[l.estado][0]">{{ estadoLead[l.estado][1] }}</Chip></td>
                        <td>{{ l.agente ?? 'Sin asignar' }}</td>
                    </tr>
                    <tr v-if="!leads.data.length"><td colspan="5" class="text-center text-grafito">No hay prospectos abiertos.</td></tr>
                </tbody>
            </table>
        </div>
        <Paginacion :datos="leads" nombre="prospectos" />
    </div>
</template>
