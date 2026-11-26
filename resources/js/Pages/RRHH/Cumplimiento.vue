<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';

interface Control { clave: string; titulo: string; norma: string; items: { empleado_id: number | null; empleado: string; detalle: string }[] }
const props = defineProps<{ controles: Control[]; activos: number }>();
const conHallazgos = computed(() => props.controles.filter((c) => c.items.length).length);
</script>

<template>
    <Head title="Cumplimiento" />
    <Encabezado titulo="Cumplimiento normativo" bajada="Revisión en línea de las obligaciones laborales que el sistema puede verificar con sus propios datos. No reemplaza la asesoría legal." />

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Personas activas" :valor="activos" />
        <Kpi etiqueta="Controles al día" :valor="`${controles.length - conHallazgos} de ${controles.length}`" :tono="conHallazgos ? 'normal' : 'ok'" />
        <Kpi etiqueta="Controles con observaciones" :valor="conHallazgos" :tono="conHallazgos ? 'error' : 'ok'" />
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section v-for="c in controles" :key="c.clave" class="tarjeta p-5">
            <div class="mb-2 flex items-start justify-between gap-3">
                <h2 class="text-[18px] font-bold">{{ c.titulo }}</h2>
                <Chip chico :tono="c.items.length ? 'error' : 'ok'">{{ c.items.length ? `${c.items.length} ${c.items.length === 1 ? 'observación' : 'observaciones'}` : 'Al día' }}</Chip>
            </div>
            <p class="mb-3 text-[14px] text-grafito">{{ c.norma }}</p>
            <ul v-if="c.items.length" class="divide-y divide-linea border-t border-linea text-[14px]">
                <li v-for="(i, n) in c.items" :key="n" class="flex justify-between gap-3 py-2">
                    <Link v-if="i.empleado_id" :href="`/rrhh/personal/${i.empleado_id}`" class="font-semibold">{{ i.empleado }}</Link>
                    <span v-else class="font-semibold">{{ i.empleado }}</span>
                    <span class="text-right text-grafito">{{ i.detalle }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
