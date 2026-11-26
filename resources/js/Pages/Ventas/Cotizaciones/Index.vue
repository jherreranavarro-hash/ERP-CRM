<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos } from '@/lib/formato';
import { estadoCot } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; numero: number; fecha: string; valida_hasta: string; destinatario: string; total: number; estado: string; ejecutivo: string | null; documento: string | null }
const props = defineProps<{ cotizaciones: Paginado<Fila>; filtros: Record<string, string | undefined>; estados: Record<string, string>; embudo: { enviadas: number; convertidas: number; tasa: number | null; monto_abierto: number } }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '', mias: props.filtros.mias ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/ventas/cotizaciones', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
const exportar = () => (window.location.href = `/ventas/cotizaciones?${new URLSearchParams({ ...f, exportar: '1' })}`);
</script>

<template>
    <Head title="Cotizaciones" />
    <Encabezado titulo="Cotizaciones" bajada="Arma la cotización, envíala y, cuando el cliente acepte, conviértela en factura o boleta con los mismos precios.">
        <button v-if="puede('ventascrm.cotizacion.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <Link v-if="puede('ventascrm.cotizacion.crear')" href="/ventas/cotizaciones/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nueva cotización</Link>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <Kpi etiqueta="Enviadas (30 días)" :valor="embudo.enviadas" />
        <Kpi etiqueta="Tasa de conversión" :valor="embudo.tasa === null ? '—' : `${embudo.tasa.toLocaleString('es-CL')} %`" tono="naranja">{{ embudo.convertidas }} convertidas en venta</Kpi>
        <Kpi etiqueta="En negociación" :valor="pesos(embudo.monto_abierto)">Enviadas o aceptadas y vigentes</Kpi>
    </div>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="relative xl:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Número o cliente" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todos los estados</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
            <select v-model="f.mias" class="campo h-11" aria-label="Ejecutivo"><option value="">De todo el equipo</option><option value="1">Solo las mías</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="cotizaciones.data.length" class="tabla">
                <thead><tr><th>N°</th><th>Fecha</th><th>Cliente</th><th>Válida hasta</th><th>Ejecutivo</th><th class="text-right">Total</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="c in cotizaciones.data" :key="c.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/ventas/cotizaciones/${c.id}`)">
                        <td><Link :href="`/ventas/cotizaciones/${c.id}`" class="num font-semibold">{{ c.numero }}</Link></td>
                        <td class="num whitespace-nowrap">{{ fecha(c.fecha) }}</td>
                        <td>{{ c.destinatario }}</td>
                        <td class="num whitespace-nowrap">{{ fecha(c.valida_hasta) }}</td>
                        <td>{{ c.ejecutivo }}</td>
                        <td class="num text-right font-semibold">{{ pesos(c.total) }}</td>
                        <td><Chip chico :tono="estadoCot[c.estado][0]">{{ estadoCot[c.estado][1] }}</Chip><div v-if="c.documento" class="text-[13px] text-grafito">{{ c.documento }}</div></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin cotizaciones" texto="Crea una cotización o ajusta los filtros." />
        </div>
        <Paginacion :datos="cotizaciones" nombre="cotizaciones" />
    </div>
</template>
