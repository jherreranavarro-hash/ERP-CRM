<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos, rut } from '@/lib/formato';
import { estadoFacturaProveedor } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; tipo: string; tipo_dte: number; folio: number; fecha: string; rut: string | null; razon_social: string | null; neto: number; exento: number; iva: number; total: number; estado: string }
const props = defineProps<{ mes: string; resumen: { tipo: string; cantidad: number; neto: number; exento: number; iva: number; total: number }[]; totales: { neto: number; exento: number; iva: number; total: number; cantidad: number }; facturas: Paginado<Fila> }>();
const puede = usePermisos();
const mes = ref(props.mes);
watch(mes, (m) => router.get('/finanzas/libro-de-compras', { mes: m }, { preserveState: true }));
</script>

<template>
    <Head title="Libro de compras" />
    <Encabezado titulo="Libro de compras" bajada="Generado desde las facturas de proveedor registradas en el período. Las rechazadas no entran.">
        <input v-model="mes" type="month" class="campo h-12 w-auto" aria-label="Mes" />
        <a v-if="puede('finanzas.libro-de-compras.exportar')" :href="`/finanzas/libro-de-compras?mes=${mes}&exportar=1`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Documentos" :valor="totales.cantidad" />
        <Kpi etiqueta="Neto" :valor="pesos(totales.neto)" />
        <Kpi etiqueta="IVA crédito fiscal" :valor="pesos(totales.iva)" />
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
            <table v-if="facturas.data.length" class="tabla">
                <thead><tr><th>Documento</th><th>Fecha</th><th>Proveedor</th><th class="text-right">Neto</th><th class="text-right">Exento</th><th class="text-right">IVA</th><th class="text-right">Total</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="f in facturas.data" :key="f.id">
                        <td><Link :href="`/compras/facturas/${f.id}`" class="font-semibold">{{ f.tipo }} N° {{ f.folio }}</Link></td>
                        <td class="num">{{ fecha(f.fecha) }}</td>
                        <td>{{ f.razon_social }}<div v-if="f.rut" class="num text-[13px] text-grafito">{{ rut(f.rut) }}</div></td>
                        <td class="num text-right">{{ pesos(f.neto) }}</td>
                        <td class="num text-right">{{ pesos(f.exento) }}</td>
                        <td class="num text-right">{{ pesos(f.iva) }}</td>
                        <td class="num text-right font-semibold">{{ pesos(f.total) }}</td>
                        <td><Chip chico :tono="estadoFacturaProveedor[f.estado][0]">{{ estadoFacturaProveedor[f.estado][1] }}</Chip></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin facturas en el período" />
        </div>
        <Paginacion :datos="facturas" nombre="facturas" />
    </div>
</template>
