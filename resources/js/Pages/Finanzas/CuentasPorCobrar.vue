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
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; nombre: string; cliente: string | null; rut: string | null; emision: string; vencimiento: string | null; total: number; saldo: number; tramo: string; vencido: boolean }
const props = defineProps<{ tramos: { clave: string; nombre: string; monto: number }[]; total: number; documentos: Paginado<Fila>; filtros: { buscar?: string } }>();
const puede = usePermisos();
const f = reactive({ buscar: props.filtros.buscar ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/finanzas/cuentas-por-cobrar', { ...f }, { preserveState: true, replace: true }), 300); });
</script>

<template>
    <Head title="Cuentas por cobrar" />
    <Encabezado titulo="Cuentas por cobrar" bajada="Documentos emitidos con saldo pendiente, por antigüedad desde el vencimiento.">
        <a v-if="puede('finanzas.cuentas-por-cobrar.exportar')" :href="`/finanzas/cuentas-por-cobrar?exportar=1&buscar=${encodeURIComponent(f.buscar)}`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
        <Kpi etiqueta="Total por cobrar" :valor="pesos(total)" tono="naranja" />
        <Kpi v-for="t in tramos" :key="t.clave" :etiqueta="t.nombre" :valor="pesos(t.monto)" :tono="t.clave !== 'al_dia' && t.monto ? 'error' : 'normal'" />
    </div>

    <div class="tarjeta">
        <div class="border-b border-linea p-4">
            <div class="relative max-w-md">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Buscar cliente" aria-label="Buscar" />
            </div>
        </div>
        <div class="overflow-x-auto">
            <table v-if="documentos.data.length" class="tabla">
                <thead><tr><th>Documento</th><th>Cliente</th><th>Emisión</th><th>Vence</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Tramo</th></tr></thead>
                <tbody>
                    <tr v-for="d in documentos.data" :key="d.id">
                        <td><Link :href="`/caja/documentos/${d.id}`" class="font-semibold">{{ d.nombre }}</Link></td>
                        <td>{{ d.cliente ?? 'Consumidor final' }}</td>
                        <td class="num">{{ fecha(d.emision) }}</td>
                        <td class="num">{{ fecha(d.vencimiento) }}</td>
                        <td class="num text-right">{{ pesos(d.total) }}</td>
                        <td class="num text-right font-semibold text-naranja-profundo">{{ pesos(d.saldo) }}</td>
                        <td><Chip chico :tono="d.vencido ? 'error' : 'ok'">{{ d.tramo }}</Chip></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Nada por cobrar" texto="No hay documentos emitidos con saldo pendiente." />
        </div>
        <Paginacion :datos="documentos" nombre="documentos" />
    </div>
</template>
