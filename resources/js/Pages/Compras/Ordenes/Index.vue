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
import { estadoOC } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; numero: number; fecha: string; proveedor: string | null; local: string | null; entrega: string | null; neto: number; total: number; estado: string; creador: string | null }
const props = defineProps<{ ordenes: Paginado<Fila>; filtros: Record<string, string | undefined>; estados: Record<string, string>; pendientes: number }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/compras/ordenes', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
const exportar = () => (window.location.href = `/compras/ordenes?${new URLSearchParams({ ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), exportar: '1' })}`);
</script>

<template>
    <Head title="Órdenes de compra" />
    <Encabezado titulo="Órdenes de compra" bajada="Lo que se pide a proveedores. Se aprueban por monto, se reciben en bodega y se cruzan con la factura.">
        <button v-if="puede('compras.orden-de-compra.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <Link v-if="puede('compras.orden-de-compra.crear')" href="/compras/ordenes/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nueva orden</Link>
    </Encabezado>

    <button v-if="pendientes" class="tarjeta mb-6 block w-full border-[#F0D58A] bg-[#FFF3D6] p-4 text-left text-[#8A5A00]" @click="f.estado = 'por_aprobar'">
        {{ pendientes === 1 ? 'Hay 1 orden de compra' : `Hay ${pendientes} órdenes de compra` }} esperando aprobación.
    </button>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Número o proveedor" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todos los estados</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="ordenes.data.length" class="tabla">
                <thead><tr><th>N°</th><th>Fecha</th><th>Proveedor</th><th>Recibe</th><th>Entrega</th><th class="text-right">Total</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="o in ordenes.data" :key="o.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/compras/ordenes/${o.id}`)">
                        <td><Link :href="`/compras/ordenes/${o.id}`" class="num font-semibold">{{ o.numero }}</Link></td>
                        <td class="num whitespace-nowrap">{{ fecha(o.fecha) }}</td>
                        <td>{{ o.proveedor }}</td>
                        <td>{{ o.local }}</td>
                        <td class="num whitespace-nowrap">{{ o.entrega ? fecha(o.entrega) : '—' }}</td>
                        <td class="num text-right font-semibold">{{ pesos(o.total) }}<div class="text-[13px] font-normal text-grafito">neto {{ pesos(o.neto) }}</div></td>
                        <td><Chip chico :tono="estadoOC[o.estado][0]">{{ estadoOC[o.estado][1] }}</Chip></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin órdenes de compra" texto="Crea la primera o ajusta los filtros." />
        </div>
        <Paginacion :datos="ordenes" nombre="órdenes" />
    </div>
</template>
