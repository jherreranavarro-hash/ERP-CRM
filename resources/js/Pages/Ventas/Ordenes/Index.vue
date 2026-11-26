<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos } from '@/lib/formato';
import { estadoOrden } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; numero: number; fecha: string; reserva_hasta: string; cliente: string | null; local: string | null; total: number; estado: string; documento: string | null; ejecutivo: string | null }
const props = defineProps<{ ordenes: Paginado<Fila>; filtros: Record<string, string | undefined>; estados: Record<string, string> }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/ventas/ordenes', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
const exportar = () => (window.location.href = `/ventas/ordenes?${new URLSearchParams({ ...f, exportar: '1' })}`);
</script>

<template>
    <Head title="Órdenes de venta" />
    <Encabezado titulo="Órdenes de venta" bajada="Nacen de una cotización aceptada y reservan el stock en su local hasta facturarse.">
        <button v-if="puede('ventascrm.orden-de-venta.exportar') || puede('inventario.orden-de-venta.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <Link v-if="puede('ventascrm.cotizacion.ver')" href="/ventas/cotizaciones?estado=aceptada" class="btn btn-primario no-underline">Desde una cotización</Link>
    </Encabezado>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Número o cliente" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todos los estados</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="ordenes.data.length" class="tabla">
                <thead><tr><th>N°</th><th>Fecha</th><th>Cliente</th><th>Local</th><th>Reserva hasta</th><th class="text-right">Total</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="o in ordenes.data" :key="o.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/ventas/ordenes/${o.id}`)">
                        <td><Link :href="`/ventas/ordenes/${o.id}`" class="num font-semibold">{{ o.numero }}</Link></td>
                        <td class="num whitespace-nowrap">{{ fecha(o.fecha) }}</td>
                        <td>{{ o.cliente }}<div class="text-[13px] text-grafito">{{ o.ejecutivo }}</div></td>
                        <td>{{ o.local }}</td>
                        <td class="num whitespace-nowrap">{{ fecha(o.reserva_hasta) }}</td>
                        <td class="num text-right font-semibold">{{ pesos(o.total) }}</td>
                        <td><Chip chico :tono="estadoOrden[o.estado][0]">{{ estadoOrden[o.estado][1] }}</Chip><div v-if="o.documento" class="text-[13px] text-grafito">{{ o.documento }}</div></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin órdenes de venta" texto="Confirma una orden desde una cotización enviada o aceptada." />
        </div>
        <Paginacion :datos="ordenes" nombre="ordenes" />
    </div>
</template>
