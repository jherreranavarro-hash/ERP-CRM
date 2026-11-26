<script setup lang="ts">
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Kpi from '@/Components/Kpi.vue';
import Vacio from '@/Components/Vacio.vue';
import { pesos } from '@/lib/formato';

const props = defineProps<{
    anio: number;
    kpis: { dotacion: number; altas: number; bajas: number; rotacion: number; ausentismo: number; antiguedad: number; costo_anual: number; horas_extra: number };
    porLocal: { local: string; personas: number }[];
    porMes: { periodo: string; liquido: number; costo: number }[];
    costoPorCentro: { centro: string; costo: number; personas: number }[];
}>();
const anios = computed(() => Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i));
const maxLocal = computed(() => Math.max(1, ...props.porLocal.map((l) => l.personas)));
const maxMes = computed(() => Math.max(1, ...props.porMes.map((m) => m.costo)));
const maxCentro = computed(() => Math.max(1, ...props.costoPorCentro.map((c) => c.costo)));
const mes = (p: string) => new Date(`${p}-15T12:00:00`).toLocaleDateString('es-CL', { month: 'short' });
const pct = (n: number) => `${n.toLocaleString('es-CL')}%`;
</script>

<template>
    <Head title="Indicadores de RRHH" />
    <Encabezado titulo="Indicadores y reportes" bajada="Dotación, rotación, ausentismo, horas extra y costo de personal por centro de costo, a partir de las nóminas aprobadas.">
        <select :value="anio" class="campo h-11 w-32" aria-label="Año" @change="router.get('/rrhh/indicadores', { anio: ($event.target as HTMLSelectElement).value }, { preserveState: true })">
            <option v-for="a in anios" :key="a" :value="a">{{ a }}</option>
        </select>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Dotación activa" :valor="kpis.dotacion">{{ kpis.altas }} ingresos · {{ kpis.bajas }} egresos en {{ anio }}</Kpi>
        <Kpi etiqueta="Rotación" :valor="pct(kpis.rotacion)" :tono="kpis.rotacion > 20 ? 'error' : 'normal'">Egresos sobre dotación media</Kpi>
        <Kpi etiqueta="Ausentismo" :valor="pct(kpis.ausentismo)" :tono="kpis.ausentismo > 5 ? 'error' : 'normal'">Licencias y permisos sin goce</Kpi>
        <Kpi etiqueta="Antigüedad media" :valor="`${kpis.antiguedad.toLocaleString('es-CL')} años`" />
        <Kpi etiqueta="Costo de personal" :valor="pesos(kpis.costo_anual)" tono="naranja">Haberes más aportes del empleador</Kpi>
        <Kpi etiqueta="Horas extra pagadas" :valor="kpis.horas_extra.toLocaleString('es-CL')" />
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="tarjeta p-5">
            <h2 class="mb-4 text-[18px] font-bold">Costo mensual de remuneraciones</h2>
            <div v-if="porMes.length" class="flex h-56 items-end gap-2" role="img" :aria-label="`Costo mensual de ${anio}`">
                <div v-for="m in porMes" :key="m.periodo" class="flex flex-1 flex-col items-center gap-1">
                    <div class="flex w-full flex-1 items-end"><div class="w-full rounded-t-[4px] bg-naranja" :style="{ height: `${(m.costo / maxMes) * 100}%` }" :title="`${m.periodo}: ${pesos(m.costo)} (líquido ${pesos(m.liquido)})`"></div></div>
                    <span class="text-[12px] capitalize text-grafito">{{ mes(m.periodo) }}</span>
                </div>
            </div>
            <Vacio v-else titulo="Sin nóminas aprobadas en el año" />
        </section>
        <section class="tarjeta p-5">
            <h2 class="mb-4 text-[18px] font-bold">Dotación por local</h2>
            <ul v-if="porLocal.length" class="space-y-3">
                <li v-for="l in porLocal" :key="l.local">
                    <div class="mb-1 flex justify-between text-[14px]"><span>{{ l.local }}</span><span class="num font-semibold">{{ l.personas }}</span></div>
                    <div class="h-2 rounded-full bg-hormigon"><div class="h-2 rounded-full bg-carbon" :style="{ width: `${(l.personas / maxLocal) * 100}%` }"></div></div>
                </li>
            </ul>
            <Vacio v-else titulo="Sin personas activas" />
        </section>
        <section class="tarjeta p-5 xl:col-span-2">
            <h2 class="mb-4 text-[18px] font-bold">Costo de personal por centro de costo</h2>
            <table v-if="costoPorCentro.length" class="tabla">
                <thead><tr><th>Centro</th><th class="text-right">Personas</th><th class="text-right">Costo</th><th class="w-1/3"></th></tr></thead>
                <tbody>
                    <tr v-for="c in costoPorCentro" :key="c.centro">
                        <td>{{ c.centro }}</td>
                        <td class="num text-right">{{ c.personas }}</td>
                        <td class="num text-right font-semibold">{{ pesos(c.costo) }}</td>
                        <td><div class="h-2 rounded-full bg-hormigon"><div class="h-2 rounded-full bg-naranja" :style="{ width: `${(c.costo / maxCentro) * 100}%` }"></div></div></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin nóminas aprobadas en el año" />
        </section>
    </div>
</template>
