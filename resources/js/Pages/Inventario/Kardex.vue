<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Kpi from '@/Components/Kpi.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { cantidad } from '@/lib/documentos';

interface Mov { id: number; fecha: string; tipo: string; cantidad: number; costo: number | null; saldo: number; costo_promedio: number; referencia: string | null; usuario: string | null; enlace: string | null }
const props = defineProps<{
    producto: { id: number; sku: string; nombre: string; unidad: string };
    locales: { id: number; nombre: string }[];
    localId: number | null;
    stock: { fisico: number; reservado: number; costo: number; minimo: number | null };
    movimientos: Paginado<Mov>;
}>();
const cambiarLocal = (e: Event) => router.get(`/inventario/stock/${props.producto.id}/kardex`, { local_id: (e.target as HTMLSelectElement).value }, { preserveState: true, replace: true });
</script>

<template>
    <Head :title="`Kardex · ${producto.nombre}`" />
    <Encabezado :titulo="producto.nombre" :bajada="`Kardex · ${producto.sku}`" :volver="`/inventario/stock?local_id=${localId ?? ''}`">
        <select :value="localId ?? ''" class="campo h-11 w-auto" aria-label="Local" @change="cambiarLocal"><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Físico" :valor="`${cantidad(stock.fisico)} ${producto.unidad}`" />
        <Kpi etiqueta="Reservado" :valor="cantidad(stock.reservado)">Órdenes de venta vigentes</Kpi>
        <Kpi etiqueta="Disponible" :valor="cantidad(stock.fisico - stock.reservado)" :tono="stock.fisico - stock.reservado <= 0 ? 'error' : 'ok'">
            <template v-if="stock.minimo !== null">Mínimo {{ cantidad(stock.minimo) }}</template>
        </Kpi>
        <Kpi etiqueta="Costo promedio" :valor="pesos(Math.round(stock.costo))" tono="naranja">Valorizado {{ pesos(Math.round(stock.fisico * stock.costo)) }}</Kpi>
    </div>

    <div class="tarjeta">
        <div class="overflow-x-auto">
            <table v-if="movimientos.data.length" class="tabla">
                <thead><tr><th>Fecha</th><th>Movimiento</th><th>Referencia</th><th class="text-right">Entrada</th><th class="text-right">Salida</th><th class="text-right">Costo unit.</th><th class="text-right">Saldo</th><th class="text-right">Costo prom.</th><th>Usuario</th></tr></thead>
                <tbody>
                    <tr v-for="m in movimientos.data" :key="m.id">
                        <td class="num whitespace-nowrap">{{ fechaHora(m.fecha) }}</td>
                        <td>{{ m.tipo }}</td>
                        <td><Link v-if="m.enlace" :href="m.enlace">{{ m.referencia ?? 'Ver' }}</Link><span v-else>{{ m.referencia ?? '—' }}</span></td>
                        <td class="num text-right text-estado-ok">{{ m.cantidad > 0 ? cantidad(m.cantidad) : '' }}</td>
                        <td class="num text-right text-estado-error">{{ m.cantidad < 0 ? cantidad(-m.cantidad) : '' }}</td>
                        <td class="num text-right">{{ m.costo === null ? '—' : pesos(Math.round(m.costo)) }}</td>
                        <td class="num text-right font-semibold">{{ cantidad(m.saldo) }}</td>
                        <td class="num text-right">{{ pesos(Math.round(m.costo_promedio)) }}</td>
                        <td>{{ m.usuario }}</td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin movimientos" texto="Este producto aún no tiene movimientos en el local." />
        </div>
        <Paginacion :datos="movimientos" nombre="movimientos" />
    </div>
</template>
