<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Kpi from '@/Components/Kpi.vue';
import Periodo from '@/Components/Periodo.vue';
import Vacio from '@/Components/Vacio.vue';
import { fecha, fechaHora, pesos } from '@/lib/formato';

interface Categoria { categoria: string; venta: number; costo: number; margen: number; margen_pct: number | null }
const props = defineProps<{
    filtros: { desde: string; hasta: string };
    kpis: { ventas: number; ventas_anterior: number; resultado: number; margen: number; margen_pct: number; cotizaciones: number; convertidas: number; conversion: number;
        clientes_activos: number; compradores: number; cobertura: number };
    categorias: Categoria[];
    serie: { dia: string; ventas: number }[];
    actividad: { maximo: number; ejecutivos: { id: number; nombre: string; total: number; dias: Record<string, number> }[] };
    calculado: string;
}>();

const pct = (n: number) => `${n.toLocaleString('es-CL', { maximumFractionDigits: 1 })}%`;
const variacion = computed(() => (props.kpis.ventas_anterior ? ((props.kpis.ventas - props.kpis.ventas_anterior) / props.kpis.ventas_anterior) * 100 : null));
const maxSerie = computed(() => Math.max(1, ...props.serie.map((s) => s.ventas)));
const maxCat = computed(() => Math.max(1, ...props.categorias.map((c) => Math.abs(c.venta))));

// Mapa de calor: por día si el período es de hasta 31 días; si no, por semana.
const semanal = computed(() => props.serie.length > 31);
const columnas = computed(() => {
    if (!semanal.value) return props.serie.map((s) => ({ clave: s.dia, dias: [s.dia], etiqueta: String(Number(s.dia.slice(8))) }));
    const cols: { clave: string; dias: string[]; etiqueta: string }[] = [];
    props.serie.forEach((s, i) => {
        if (i % 7 === 0) cols.push({ clave: s.dia, dias: [], etiqueta: `${Number(s.dia.slice(8))}/${Number(s.dia.slice(5, 7))}` });
        cols[cols.length - 1].dias.push(s.dia);
    });
    return cols;
});
const celda = (dias: Record<string, number>, col: string[]) => col.reduce((n, d) => n + (dias[d] ?? 0), 0);
const maxCelda = computed(() => Math.max(1, ...props.actividad.ejecutivos.flatMap((e) => columnas.value.map((c) => celda(e.dias, c.dias)))));
const intensidad = (n: number) => (n ? 0.15 + 0.85 * (n / maxCelda.value) : 0);
const esFinde = (iso: string) => [0, 6].includes(new Date(`${iso}T12:00:00`).getDay());
</script>

<template>
    <Head title="Dashboard CRM" />
    <Encabezado titulo="Dashboard CRM" :bajada="`Resultados del período con datos de facturación, cotizaciones, inventario y contabilidad. Actualizado el ${fechaHora(calculado)}`" />
    <Periodo :desde="filtros.desde" :hasta="filtros.hasta" url="/dashboard" />

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Kpi etiqueta="Ventas netas" :valor="pesos(kpis.ventas)" tono="naranja">
            <template v-if="variacion !== null"><span :class="variacion >= 0 ? 'text-estado-ok' : 'text-estado-error'">{{ variacion >= 0 ? '▲' : '▼' }} {{ pct(Math.abs(variacion)) }}</span> vs. período anterior</template>
            <template v-else>Sin ventas en el período anterior</template>
        </Kpi>
        <Kpi etiqueta="Resultado del período" :valor="pesos(kpis.resultado)" :tono="kpis.resultado < 0 ? 'error' : 'normal'">Ingresos menos gastos contabilizados</Kpi>
        <Kpi etiqueta="Margen bruto" :valor="pct(kpis.margen_pct)">{{ pesos(kpis.margen) }} sobre el costo de lo vendido</Kpi>
        <Kpi etiqueta="Cotizaciones emitidas" :valor="kpis.cotizaciones">{{ kpis.convertidas }} convertidas en venta</Kpi>
        <Kpi etiqueta="Tasa de conversión" :valor="pct(kpis.conversion)" :tono="kpis.cotizaciones && kpis.conversion < 20 ? 'error' : 'normal'" />
        <Kpi etiqueta="Cobertura de cartera" :valor="pct(kpis.cobertura)">{{ kpis.compradores }} de {{ kpis.clientes_activos }} clientes compraron</Kpi>
    </div>

    <div class="mb-6 grid gap-6 xl:grid-cols-5">
        <section class="tarjeta p-5 xl:col-span-3">
            <h2 class="mb-4 text-[18px] font-bold">Ventas netas por día</h2>
            <div v-if="kpis.ventas" class="flex h-56 items-end gap-[2px]" role="img" :aria-label="`Ventas diarias entre ${fecha(filtros.desde)} y ${fecha(filtros.hasta)}`">
                <div v-for="s in serie" :key="s.dia" class="group relative flex h-full flex-1 items-end">
                    <div class="w-full rounded-t-[3px]" :class="esFinde(s.dia) ? 'bg-naranja/50' : 'bg-naranja'" :style="{ height: `${(Math.max(0, s.ventas) / maxSerie) * 100}%` }"></div>
                    <span class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded-boton bg-carbon px-2 py-1 text-[12px] text-hormigon group-hover:block">{{ fecha(s.dia) }} · {{ pesos(s.ventas) }}</span>
                </div>
            </div>
            <Vacio v-else titulo="Sin ventas en el período" />
        </section>
        <section class="tarjeta p-5 xl:col-span-2">
            <h2 class="mb-4 text-[18px] font-bold">Mix de margen por categoría</h2>
            <ul v-if="categorias.length" class="space-y-3">
                <li v-for="c in categorias" :key="c.categoria">
                    <div class="mb-1 flex justify-between gap-3 text-[14px]">
                        <span class="font-semibold">{{ c.categoria }}</span>
                        <span class="num">{{ pesos(c.venta) }} · <span :class="{ 'text-estado-error': (c.margen_pct ?? 0) < 0 }">{{ c.margen_pct === null ? '—' : pct(c.margen_pct) }}</span></span>
                    </div>
                    <div class="flex h-2 overflow-hidden rounded-full bg-hormigon" :title="`Costo ${pesos(c.costo)} · margen ${pesos(c.margen)}`">
                        <div class="h-2 bg-carbon" :style="{ width: `${(Math.max(0, Math.min(c.costo, c.venta)) / maxCat) * 100}%` }"></div>
                        <div class="h-2 bg-naranja" :style="{ width: `${(Math.max(0, c.margen) / maxCat) * 100}%` }"></div>
                    </div>
                </li>
            </ul>
            <Vacio v-else titulo="Sin ventas en el período" />
            <p v-if="categorias.length" class="mt-4 flex gap-4 text-[13px] text-grafito"><span class="flex items-center gap-1"><span class="inline-block h-2 w-3 rounded-full bg-carbon"></span> Costo</span><span class="flex items-center gap-1"><span class="inline-block h-2 w-3 rounded-full bg-naranja"></span> Margen</span></p>
        </section>
    </div>

    <section class="tarjeta p-5">
        <h2 class="mb-1 text-[18px] font-bold">Actividad {{ semanal ? 'semanal' : 'diaria' }} por ejecutivo</h2>
        <p class="mb-4 text-[14px] text-grafito">Documentos emitidos y cotizaciones creadas.</p>
        <div v-if="actividad.ejecutivos.length" class="overflow-x-auto">
            <table class="border-separate border-spacing-[3px] text-[12px]">
                <thead><tr><th class="sr-only">Ejecutivo</th><th v-for="c in columnas" :key="c.clave" class="num min-w-6 font-normal text-grafito">{{ c.etiqueta }}</th><th class="pl-2 text-right font-semibold">Total</th></tr></thead>
                <tbody>
                    <tr v-for="e in actividad.ejecutivos" :key="e.id">
                        <th class="whitespace-nowrap pr-3 text-left text-[14px] font-semibold">{{ e.nombre }}</th>
                        <td v-for="c in columnas" :key="c.clave" class="h-6 min-w-6 rounded-[4px] bg-hormigon p-0" :title="`${e.nombre} · ${c.etiqueta}: ${celda(e.dias, c.dias)}`">
                            <div class="h-6 w-full rounded-[4px] bg-naranja" :style="{ opacity: intensidad(celda(e.dias, c.dias)) }"></div>
                        </td>
                        <td class="num pl-2 text-right text-[14px] font-semibold">{{ e.total }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <Vacio v-else titulo="Sin actividad en el período" />
    </section>
</template>
