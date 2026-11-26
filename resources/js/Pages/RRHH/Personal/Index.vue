<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import { consulta } from '@/lib/http';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, rut } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; rut: string; nombre: string; cargo: string; local: string | null; ingreso: string; estado: string; contrato: { tipo: string; termino: string | null } | null }
const props = defineProps<{ empleados: Paginado<Fila>; filtros: { estado: string; local_id?: string | null; buscar?: string | null }; locales: { id: number; nombre: string }[]; resumen: { activos: number; sin_contrato: number } }>();
const puede = usePermisos();
const f = reactive({ estado: props.filtros.estado, local_id: props.filtros.local_id ?? '', buscar: props.filtros.buscar ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/rrhh/personal', { ...f }, { preserveState: true, replace: true }), 300); });
</script>

<template>
    <Head title="Ficha de personal" />
    <Encabezado titulo="Ficha de personal" bajada="Expediente de cada persona: datos, previsión, contratos y documentos.">
        <a v-if="puede('rrhh.ficha-de-personal.exportar')" :href="`/rrhh/personal?${consulta({ ...f, exportar: '1' })}`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <Link v-if="puede('rrhh.ficha-de-personal.crear')" href="/rrhh/personal/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nueva ficha</Link>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Personas activas" :valor="resumen.activos" />
        <Kpi etiqueta="Sin contrato vigente" :valor="resumen.sin_contrato" :tono="resumen.sin_contrato ? 'error' : 'ok'" />
    </div>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <div class="relative">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Nombre, RUT o cargo" aria-label="Buscar" />
            </div>
            <select v-model="f.local_id" class="campo h-11" aria-label="Local"><option value="">Todos los locales</option><option v-for="l in locales" :key="l.id" :value="String(l.id)">{{ l.nombre }}</option></select>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="activo">Activas</option><option value="desvinculado">Desvinculadas</option><option value="todos">Todas</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="empleados.data.length" class="tabla">
                <thead><tr><th>Persona</th><th>Cargo</th><th>Local</th><th>Ingreso</th><th>Contrato</th></tr></thead>
                <tbody>
                    <tr v-for="e in empleados.data" :key="e.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/rrhh/personal/${e.id}`)">
                        <td><Link :href="`/rrhh/personal/${e.id}`" class="font-semibold">{{ e.nombre }}</Link><div class="num text-[13px] text-grafito">{{ rut(e.rut) }}</div></td>
                        <td>{{ e.cargo }}</td>
                        <td>{{ e.local ?? '—' }}</td>
                        <td class="num">{{ fecha(e.ingreso) }}</td>
                        <td>
                            <Chip v-if="e.estado === 'desvinculado'" chico>Desvinculada</Chip>
                            <Chip v-else-if="!e.contrato" chico tono="error">Sin contrato</Chip>
                            <template v-else><Chip chico tono="ok">{{ e.contrato.tipo }}</Chip><div v-if="e.contrato.termino" class="num text-[13px] text-grafito">hasta {{ fecha(e.contrato.termino) }}</div></template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin personas" texto="Crea la ficha de cada trabajador para llevar contratos, asistencia y remuneraciones." />
        </div>
        <Paginacion :datos="empleados" nombre="personas" />
    </div>
</template>
