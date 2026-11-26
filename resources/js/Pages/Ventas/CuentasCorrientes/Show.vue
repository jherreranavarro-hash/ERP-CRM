<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Kpi from '@/Components/Kpi.vue';
import Vacio from '@/Components/Vacio.vue';
import { fecha, pesos, rut } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Mov { fecha: string; documento_id: number; detalle: string; cargo: number; abono: number; vence: string | null; saldo: number }
defineProps<{
    cliente: { id: number; rut: string; nombre: string; cupo: number | null; plazo: number | null };
    resumen: { facturado: number; abonado: number; saldo: number; vencido: number };
    movimientos: Mov[];
}>();
const puede = usePermisos();
</script>

<template>
    <Head :title="cliente.nombre" />
    <Encabezado :titulo="cliente.nombre" :bajada="`RUT ${rut(cliente.rut)} · ${cliente.cupo !== null ? `crédito de ${pesos(cliente.cupo)} a ${cliente.plazo ?? 0} días` : 'cliente contado'}`" volver="/ventas/cuentas-corrientes" />

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Facturado" :valor="pesos(resumen.facturado)" />
        <Kpi etiqueta="Pagos y notas de crédito" :valor="pesos(resumen.abonado)" />
        <Kpi etiqueta="Saldo por cobrar" :valor="pesos(resumen.saldo)" tono="naranja"><template v-if="cliente.cupo !== null">{{ resumen.saldo > cliente.cupo ? 'Sobre el cupo' : `Disponible ${pesos(cliente.cupo - resumen.saldo)}` }}</template></Kpi>
        <Kpi etiqueta="Vencido" :valor="pesos(resumen.vencido)" :tono="resumen.vencido ? 'error' : 'ok'" />
    </div>

    <div v-if="movimientos.length" class="tarjeta overflow-x-auto">
        <h2 class="border-b border-linea p-4 text-[18px] font-bold">Cartola de movimientos</h2>
        <table class="tabla">
            <thead><tr><th>Fecha</th><th>Detalle</th><th>Vence</th><th class="text-right">Cargo</th><th class="text-right">Abono</th><th class="text-right">Saldo</th></tr></thead>
            <tbody>
                <tr v-for="(m, i) in movimientos" :key="i">
                    <td class="num">{{ fecha(m.fecha) }}</td>
                    <td><Link v-if="puede('caja.facturas-de-ventas.ver')" :href="`/caja/documentos/${m.documento_id}`">{{ m.detalle }}</Link><span v-else>{{ m.detalle }}</span></td>
                    <td class="num">{{ m.vence ? fecha(m.vence) : '' }}</td>
                    <td class="num text-right">{{ m.cargo ? pesos(m.cargo) : '' }}</td>
                    <td class="num text-right">{{ m.abono ? pesos(m.abono) : '' }}</td>
                    <td class="num text-right font-semibold">{{ pesos(m.saldo) }}</td>
                </tr>
            </tbody>
        </table>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin movimientos" />
</template>
