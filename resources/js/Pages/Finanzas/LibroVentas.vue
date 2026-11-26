<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Kpi from '@/Components/Kpi.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos, rut } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; tipo: string; tipo_dte: number; folio: number; fecha: string; rut: string | null; razon_social: string | null; neto: number; exento: number; iva: number; total: number; estado: string }
const props = defineProps<{ mes: string; resumen: { tipo: string; cantidad: number; neto: number; exento: number; iva: number; total: number }[]; totales: { neto: number; exento: number; iva: number; total: number; cantidad: number }; documentos: Paginado<Fila> }>();
const puede = usePermisos();
const mes = ref(props.mes);
watch(mes, (m) => router.get('/finanzas/libro-de-ventas', { mes: m }, { preserveState: true }));
const signo = (f: Fila, v: number) => (f.tipo_dte === 61 ? -v : v);
</script>

<template>
    <Head title="Libro de ventas" />
    <Encabezado titulo="Libro de ventas" bajada="Generado desde los documentos emitidos del período. Las notas de crédito restan.">
        <input v-model="mes" type="month" class="campo h-12 w-auto" aria-label="Mes" />
        <a v-if="puede('finanzas.libro-de-ventas.exportar')" :href="`/finanzas/libro-de-ventas?mes=${mes}&exportar=1`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Documentos" :valor="totales.cantidad" />
        <Kpi etiqueta="Neto" :valor="pesos(totales.neto)" />
        <Kpi etiqueta="IVA débito" :valor="pesos(totales.iva)" />
        <Kpi etiqueta="Total" :valor="pesos(totales.total)" tono="naranja" />
    </div>

    <section v-if="resumen.length" class="tarjeta mb-6 overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Tipo</th><th class="text-right">Cantidad</th><th class="text-right">Neto</th><th class="text-right">Exento</th><th class="text-right">IVA</th><th class="text-right">Total</th></tr></thead>
            <tbody><tr v-for="r in resumen" :key="r.tipo"><td class="font-semibold">{{ r.tipo }}</td><td class="num text-right">{{ r.cantidad }}</td><td class="num text-right">{{ pesos(r.neto) }}</td><td class="num text-right">{{ pesos(r.exento) }}</td><td class="num text-right">{{ pesos(r.iva) }}</td><td class="num text-right font-semibold">{{ pesos(r.total) }}</td></tr></tbody>
        </table>
    </section>

    <div class="tarjeta">
        <div class="overflow-x-auto">
            <table v-if="documentos.data.length" class="tabla">
                <thead><tr><th>Documento</th><th>Fecha</th><th>Receptor</th><th class="text-right">Neto</th><th class="text-right">Exento</th><th class="text-right">IVA</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                    <tr v-for="f in documentos.data" :key="f.id">
                        <td><Link :href="`/caja/documentos/${f.id}`" class="font-semibold">{{ f.tipo }} N° {{ f.folio }}</Link></td>
                        <td class="num">{{ fecha(f.fecha) }}</td>
                        <td>{{ f.razon_social ?? 'Consumidor final' }}<div v-if="f.rut" class="num text-[13px] text-grafito">{{ rut(f.rut) }}</div></td>
                        <td class="num text-right">{{ pesos(signo(f, f.neto)) }}</td>
                        <td class="num text-right">{{ pesos(signo(f, f.exento)) }}</td>
                        <td class="num text-right">{{ pesos(signo(f, f.iva)) }}</td>
                        <td class="num text-right font-semibold">{{ pesos(signo(f, f.total)) }}</td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin documentos en el período" />
        </div>
        <Paginacion :datos="documentos" nombre="documentos" />
    </div>
</template>
