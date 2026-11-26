<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { estadoDevolucion } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; numero: number; fecha: string; documento: string | null; cliente: string | null; motivo: string; monto: number; estado: string; nota_credito: string | null; solicitante: string | null }
const props = defineProps<{ devoluciones: Paginado<Fila>; filtros: Record<string, string | undefined>; estados: Record<string, string>; pendientes: number }>();
const puede = usePermisos();

const f = reactive({ estado: props.filtros.estado ?? '', buscar: props.filtros.buscar ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/inventario/devoluciones', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
</script>

<template>
    <Head title="Devoluciones" />
    <Encabezado titulo="Devoluciones" bajada="Vinculadas a la factura o boleta original. Al aprobarse se emite la nota de crédito y el stock reingresa.">
        <Link v-if="puede('inventario.devolucion.crear') || puede('caja.devolucion.crear')" href="/inventario/devoluciones/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nueva devolución</Link>
    </Encabezado>

    <button v-if="pendientes" class="tarjeta mb-6 block w-full border-[#F0D58A] bg-[#FFF3D6] p-4 text-left text-[#8A5A00]" @click="f.estado = 'solicitada'">
        {{ pendientes === 1 ? 'Hay 1 devolución' : `Hay ${pendientes} devoluciones` }} esperando aprobación.
    </button>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="N°, folio o cliente" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todos los estados</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="devoluciones.data.length" class="tabla">
                <thead><tr><th>N°</th><th>Fecha</th><th>Documento</th><th>Motivo</th><th class="text-right">Monto</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="d in devoluciones.data" :key="d.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/inventario/devoluciones/${d.id}`)">
                        <td><Link :href="`/inventario/devoluciones/${d.id}`" class="num font-semibold">{{ d.numero }}</Link></td>
                        <td class="num whitespace-nowrap">{{ fechaHora(d.fecha) }}<div class="text-[13px] text-grafito">{{ d.solicitante }}</div></td>
                        <td>{{ d.documento }}<div class="text-[13px] text-grafito">{{ d.cliente }}</div></td>
                        <td>{{ d.motivo }}</td>
                        <td class="num text-right font-semibold">{{ pesos(d.monto) }}</td>
                        <td><Chip chico :tono="estadoDevolucion[d.estado][0]">{{ estadoDevolucion[d.estado][1] }}</Chip><div v-if="d.nota_credito" class="text-[13px] text-grafito">{{ d.nota_credito }}</div></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin devoluciones" texto="Registra una devolución desde la factura o boleta, o búscala por folio." />
        </div>
        <Paginacion :datos="devoluciones" nombre="devoluciones" />
    </div>
</template>
