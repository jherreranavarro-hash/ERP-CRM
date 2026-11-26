<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Kpi from '@/Components/Kpi.vue';
import Periodo from '@/Components/Periodo.vue';
import Vacio from '@/Components/Vacio.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Ejecutivo { id: number; nombre: string; cotizaciones: number; convertidas: number; conversion: number | null; monto_cotizado: number; ventas: number; documentos: number; ticket: number; pipeline: number; pipeline_n: number; comisiones: number }
const props = defineProps<{
    filtros: { desde: string; hasta: string }; ejecutivos: Ejecutivo[];
    totales: { ventas: number; cotizaciones: number; convertidas: number; pipeline: number; comisiones: number };
    clientes: { id: number; nombre: string; ventas: number; documentos: number }[]; calculado: string;
}>();
const puede = usePermisos();
const pct = (n: number | null) => (n === null ? '—' : `${n.toLocaleString('es-CL', { maximumFractionDigits: 1 })}%`);
const maxVentas = computed(() => Math.max(1, ...props.ejecutivos.map((e) => e.ventas)));
const conversion = computed(() => (props.totales.cotizaciones ? (props.totales.convertidas / props.totales.cotizaciones) * 100 : null));
</script>

<template>
    <Head title="Dashboard comercial" />
    <Encabezado titulo="Dashboard comercial" :bajada="`Resultados del equipo comercial por ejecutivo. Actualizado el ${fechaHora(calculado)}`">
        <Link v-if="puede('ventascrm.cuentas-corrientes.ver')" href="/ventas/cuentas-corrientes" class="btn btn-secundario no-underline">Cuentas corrientes</Link>
    </Encabezado>
    <Periodo :desde="filtros.desde" :hasta="filtros.hasta" url="/ventas/dashboard" />

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Ventas netas del equipo" :valor="pesos(totales.ventas)" tono="naranja" />
        <Kpi etiqueta="Conversión de cotizaciones" :valor="pct(conversion)">{{ totales.convertidas }} de {{ totales.cotizaciones }} emitidas</Kpi>
        <Kpi etiqueta="Cotizaciones abiertas" :valor="pesos(totales.pipeline)">Enviadas o aceptadas, sin facturar</Kpi>
        <Kpi etiqueta="Comisiones generadas" :valor="pesos(totales.comisiones)" />
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="tarjeta overflow-x-auto xl:col-span-2">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Por ejecutivo</h2>
            <table v-if="ejecutivos.length" class="tabla">
                <thead><tr><th>Ejecutivo</th><th class="text-right">Ventas</th><th class="text-right">Ticket</th><th class="text-right">Cotizaciones</th><th class="text-right">Conversión</th><th class="text-right">Abiertas</th><th class="text-right">Comisión</th></tr></thead>
                <tbody>
                    <tr v-for="e in ejecutivos" :key="e.id">
                        <td>
                            <p class="font-semibold">{{ e.nombre }}</p>
                            <div class="mt-1 h-1.5 w-32 rounded-full bg-hormigon"><div class="h-1.5 rounded-full bg-naranja" :style="{ width: `${(Math.max(0, e.ventas) / maxVentas) * 100}%` }"></div></div>
                        </td>
                        <td class="num text-right font-semibold">{{ pesos(e.ventas) }}<span class="block text-[13px] font-normal text-grafito">{{ e.documentos }} doc.</span></td>
                        <td class="num text-right">{{ pesos(e.ticket) }}</td>
                        <td class="num text-right">{{ e.cotizaciones }}<span class="block text-[13px] text-grafito">{{ pesos(e.monto_cotizado) }}</span></td>
                        <td class="num text-right">{{ pct(e.conversion) }}</td>
                        <td class="num text-right">{{ pesos(e.pipeline) }}<span v-if="e.pipeline_n" class="block text-[13px] text-grafito">{{ e.pipeline_n }} cot.</span></td>
                        <td class="num text-right">{{ pesos(e.comisiones) }}</td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin actividad comercial en el período" />
        </section>
        <section class="tarjeta self-start">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Principales clientes</h2>
            <ol v-if="clientes.length" class="divide-y divide-linea">
                <li v-for="(c, i) in clientes" :key="c.id" class="flex items-center justify-between gap-3 p-4 text-[14px]">
                    <span class="flex min-w-0 items-center gap-3"><span class="num w-5 text-grafito">{{ i + 1 }}</span>
                        <Link v-if="puede('ventascrm.cuentas-corrientes.ver')" :href="`/ventas/cuentas-corrientes/${c.id}`" class="truncate font-semibold">{{ c.nombre }}</Link>
                        <span v-else class="truncate font-semibold">{{ c.nombre }}</span>
                    </span>
                    <span class="num whitespace-nowrap font-semibold">{{ pesos(c.ventas) }}</span>
                </li>
            </ol>
            <p v-else class="p-4 text-grafito">Sin ventas a clientes identificados.</p>
        </section>
    </div>
</template>
