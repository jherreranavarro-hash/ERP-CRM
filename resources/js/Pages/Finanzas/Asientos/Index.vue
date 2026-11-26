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
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; numero: number; fecha: string; glosa: string; tipo: string; total: number; lineas: number; creador: string | null; origen: { texto: string; url: string | null } | null }
const props = defineProps<{ asientos: Paginado<Fila>; filtros: { tipo?: string; desde?: string; hasta?: string; buscar?: string; cuenta_id?: string }; tipos: Record<string, string>; cuentas: { id: number; codigo: string; nombre: string }[] }>();
const puede = usePermisos();
const f = reactive({ tipo: props.filtros.tipo ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '', buscar: props.filtros.buscar ?? '', cuenta_id: props.filtros.cuenta_id ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/finanzas/asientos', { ...f }, { preserveState: true, replace: true }), 300); });
const tono: Record<string, 'neutro' | 'info' | 'alerta'> = { manual: 'neutro', automatico: 'info', reverso: 'alerta' };
const qs = () => new URLSearchParams({ ...f, exportar: '1' }).toString();
</script>

<template>
    <Head title="Asientos contables" />
    <Encabezado titulo="Libro diario" bajada="Asientos manuales y los que genera el sistema al emitir, comprar y pagar. Un asiento no se edita: se revierte.">
        <Link href="/finanzas/plan-de-cuentas" class="btn btn-secundario no-underline">Plan de cuentas</Link>
        <a v-if="puede('finanzas.asientos-contables.exportar')" :href="`/finanzas/asientos?${qs()}`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <Link v-if="puede('finanzas.asientos-contables.crear')" href="/finanzas/asientos/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nuevo asiento</Link>
    </Encabezado>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-2 xl:grid-cols-5">
            <div class="relative xl:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="N° o glosa" aria-label="Buscar" />
            </div>
            <select v-model="f.tipo" class="campo h-11" aria-label="Tipo"><option value="">Todos los tipos</option><option v-for="(n, k) in tipos" :key="k" :value="k">{{ n }}</option></select>
            <select v-model="f.cuenta_id" class="campo h-11" aria-label="Cuenta"><option value="">Todas las cuentas</option><option v-for="c in cuentas" :key="c.id" :value="String(c.id)">{{ c.codigo }} · {{ c.nombre }}</option></select>
            <div class="flex gap-2"><input v-model="f.desde" type="date" class="campo h-11" aria-label="Desde" /><input v-model="f.hasta" type="date" class="campo h-11" aria-label="Hasta" /></div>
        </div>
        <div class="overflow-x-auto">
            <table v-if="asientos.data.length" class="tabla">
                <thead><tr><th>N°</th><th>Fecha</th><th>Glosa</th><th>Origen</th><th>Tipo</th><th class="text-right">Total</th><th>Registró</th></tr></thead>
                <tbody>
                    <tr v-for="a in asientos.data" :key="a.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/finanzas/asientos/${a.id}`)">
                        <td><Link :href="`/finanzas/asientos/${a.id}`" class="num font-semibold">{{ a.numero }}</Link></td>
                        <td class="num">{{ fecha(a.fecha) }}</td>
                        <td>{{ a.glosa }}<div class="text-[13px] text-grafito">{{ a.lineas }} líneas</div></td>
                        <td>{{ a.origen?.texto ?? '—' }}</td>
                        <td><Chip chico :tono="tono[a.tipo]">{{ tipos[a.tipo] }}</Chip></td>
                        <td class="num text-right font-semibold">{{ pesos(a.total) }}</td>
                        <td>{{ a.creador ?? 'Sistema' }}</td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin asientos" texto="Los asientos aparecen al emitir documentos, registrar compras y pagos, o al crear uno manual." />
        </div>
        <Paginacion :datos="asientos" nombre="asientos" />
    </div>
</template>
