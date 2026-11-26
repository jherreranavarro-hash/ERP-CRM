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
import { estadoDoc, estadoSii } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; tipo: string; tipo_dte: number; folio: number | null; fecha: string; receptor: string | null; total: number; saldo: number; estado: string; estado_sii: string | null; forma_pago: string }
const props = defineProps<{ documentos: Paginado<Fila>; filtros: Record<string, string | undefined>; tipos: Record<string, string>; estados: Record<string, string>; facturacionActiva: boolean }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', tipo_dte: props.filtros.tipo_dte ?? '', estado: props.filtros.estado ?? '', estado_sii: props.filtros.estado_sii ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/caja/documentos', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
const exportar = () => (window.location.href = `/caja/documentos?${new URLSearchParams({ ...f, exportar: '1' })}`);
</script>

<template>
    <Head title="Facturas de ventas" />
    <Encabezado titulo="Facturas de ventas" bajada="Boletas, facturas, guías y notas. Un documento emitido no se edita: se corrige con nota de crédito.">
        <button v-if="puede('caja.facturas-de-ventas.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <Link v-if="puede('caja.facturas-de-ventas.crear') && facturacionActiva" href="/caja/documentos/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nuevo documento</Link>
    </Encabezado>

    <div v-if="!facturacionActiva" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">
        La facturación electrónica no está activa en esta empresa. Se activa en Parámetros del sistema → Módulos y funcionalidades.
    </div>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-2 xl:grid-cols-6">
            <div class="relative xl:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Folio o cliente" aria-label="Buscar" />
            </div>
            <select v-model="f.tipo_dte" class="campo h-11" aria-label="Tipo"><option value="">Todos los tipos</option><option v-for="(n, k) in tipos" :key="k" :value="k">{{ n }}</option></select>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todos los estados</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
            <input v-model="f.desde" type="date" class="campo h-11" aria-label="Desde" />
            <input v-model="f.hasta" type="date" class="campo h-11" aria-label="Hasta" />
        </div>
        <div class="overflow-x-auto">
            <table v-if="documentos.data.length" class="tabla">
                <thead><tr><th>Documento</th><th>Fecha</th><th>Cliente</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="d in documentos.data" :key="d.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/caja/documentos/${d.id}`)">
                        <td><Link :href="`/caja/documentos/${d.id}`" class="font-semibold">{{ d.tipo }} {{ d.folio ? `N° ${d.folio}` : '' }}</Link><div v-if="d.forma_pago === 'credito'" class="text-[13px] text-grafito">Crédito</div></td>
                        <td class="num whitespace-nowrap">{{ fecha(d.fecha) }}</td>
                        <td>{{ d.receptor ?? 'Consumidor final' }}</td>
                        <td class="num text-right font-semibold">{{ pesos(d.total) }}</td>
                        <td class="num text-right" :class="d.saldo ? 'text-naranja-profundo font-semibold' : 'text-grafito'">{{ d.saldo ? pesos(d.saldo) : '—' }}</td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                <Chip chico :tono="estadoDoc[d.estado][0]">{{ estadoDoc[d.estado][1] }}</Chip>
                                <Chip v-if="d.estado_sii && d.estado_sii !== 'aceptado'" chico :tono="estadoSii[d.estado_sii][0]">{{ estadoSii[d.estado_sii][1] }}</Chip>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin documentos" texto="Crea una boleta o factura, o ajusta los filtros." />
        </div>
        <Paginacion :datos="documentos" nombre="documentos" />
    </div>
</template>
