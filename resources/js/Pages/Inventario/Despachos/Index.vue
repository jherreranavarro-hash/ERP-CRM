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
import { fecha } from '@/lib/formato';
import { estadoDespacho } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; numero: number; fecha: string; ventana: string | null; documento: string | null; cliente: string | null; direccion: string; comuna: string; transportista: string; tracking: string | null; estado: string; intentos: number }
const props = defineProps<{ despachos: Paginado<Fila>; filtros: Record<string, string | undefined>; estados: Record<string, string>; resumen: { hoy: number; en_ruta: number; por_aprobar: number; fallidos: number } }>();
const puede = usePermisos();

const f = reactive({ estado: props.filtros.estado ?? '', fecha: props.filtros.fecha ?? '', buscar: props.filtros.buscar ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/inventario/despachos', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
const exportar = () => (window.location.href = `/inventario/despachos?${new URLSearchParams({ ...f, exportar: '1' })}`);
const hoy = new Date().toLocaleDateString('sv-SE');
</script>

<template>
    <Head title="Despachos" />
    <Encabezado titulo="Despachos" bajada="Entregas de documentos emitidos. Se programan desde la factura, boleta o guía.">
        <button v-if="puede('inventario.despachos.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <button class="text-left" @click="Object.assign(f, { fecha: hoy, estado: '' })"><Kpi etiqueta="Para hoy" :valor="resumen.hoy" tono="naranja">Programados o en ruta</Kpi></button>
        <button class="text-left" @click="Object.assign(f, { estado: 'en_ruta', fecha: '' })"><Kpi etiqueta="En ruta" :valor="resumen.en_ruta" /></button>
        <button class="text-left" @click="Object.assign(f, { estado: 'por_aprobar', fecha: '' })"><Kpi etiqueta="Por aprobar" :valor="resumen.por_aprobar">Despacho gratis</Kpi></button>
        <button class="text-left" @click="Object.assign(f, { estado: 'fallido', fecha: '' })"><Kpi etiqueta="Fallidos" :valor="resumen.fallidos" :tono="resumen.fallidos ? 'error' : 'normal'">Por reprogramar</Kpi></button>
    </div>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="relative xl:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="N°, folio, cliente o comuna" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todos los estados</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
            <input v-model="f.fecha" type="date" class="campo h-11" aria-label="Fecha programada" />
        </div>
        <div class="overflow-x-auto">
            <table v-if="despachos.data.length" class="tabla">
                <thead><tr><th>N°</th><th>Fecha</th><th>Documento</th><th>Destino</th><th>Transportista</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="d in despachos.data" :key="d.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/inventario/despachos/${d.id}`)">
                        <td><Link :href="`/inventario/despachos/${d.id}`" class="num font-semibold">{{ d.numero }}</Link></td>
                        <td class="num whitespace-nowrap">{{ fecha(d.fecha) }}<div v-if="d.ventana" class="text-[13px] uppercase text-grafito">{{ d.ventana }}</div></td>
                        <td>{{ d.documento }}<div class="text-[13px] text-grafito">{{ d.cliente }}</div></td>
                        <td>{{ d.direccion }}<div class="text-[13px] text-grafito">{{ d.comuna }}</div></td>
                        <td>{{ d.transportista }}<div v-if="d.tracking" class="num text-[13px] text-grafito">{{ d.tracking }}</div></td>
                        <td><Chip chico :tono="estadoDespacho[d.estado][0]">{{ estadoDespacho[d.estado][1] }}</Chip><div v-if="d.intentos" class="text-[13px] text-grafito">{{ d.intentos }} reintento{{ d.intentos > 1 ? 's' : '' }}</div></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin despachos" texto="Programa un despacho desde una factura o boleta emitida." />
        </div>
        <Paginacion :datos="despachos" nombre="despachos" />
    </div>
</template>
