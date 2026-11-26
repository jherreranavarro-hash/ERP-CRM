<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Tramo { dias: number; hasta: string; entradas: number; salidas: number; neto: number; saldo: number }
interface Semana { desde: string; hasta: string; entradas: number; salidas: number; saldo: number }
interface Mov { fecha: string; monto: number; tipo: 'cobro' | 'pago'; detalle: string; url: string }
const props = defineProps<{ hoy: string; disponible: number; vencido_cobrar: number; vencido_pagar: number; tramos: Tramo[]; semanas: Semana[]; proximos: Mov[] }>();
const puede = usePermisos();

const escala = computed(() => Math.max(1, ...props.semanas.flatMap((s) => [s.entradas, s.salidas])));
const minSaldo = computed(() => Math.min(...props.semanas.map((s) => s.saldo)));
</script>

<template>
    <Head title="Flujo de caja" />
    <Encabezado titulo="Flujo de caja" bajada="Proyección desde el saldo en caja y banco, con los cobros y pagos pendientes según su vencimiento. Lo vencido se cuenta en la primera semana.">
        <a v-if="puede('finanzas.flujos-de-caja.exportar')" href="/finanzas/flujos-de-caja?exportar=1" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Disponible hoy" :valor="pesos(disponible)" tono="naranja">Caja y banco según contabilidad</Kpi>
        <Kpi etiqueta="Por cobrar vencido" :valor="pesos(vencido_cobrar)" :tono="vencido_cobrar ? 'error' : 'normal'" />
        <Kpi etiqueta="Por pagar vencido" :valor="pesos(vencido_pagar)" :tono="vencido_pagar ? 'error' : 'normal'" />
        <Kpi etiqueta="Saldo mínimo proyectado" :valor="pesos(minSaldo)" :tono="minSaldo < 0 ? 'error' : 'ok'">Próximas 13 semanas</Kpi>
    </div>

    <section class="tarjeta mb-6 overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Horizonte</th><th class="text-right">Entradas</th><th class="text-right">Salidas</th><th class="text-right">Neto</th><th class="text-right">Saldo proyectado</th></tr></thead>
            <tbody>
                <tr v-for="t in tramos" :key="t.dias">
                    <td class="font-semibold">A {{ t.dias }} días <span class="num font-normal text-grafito">· al {{ fecha(t.hasta) }}</span></td>
                    <td class="num text-right" :class="{ 'text-estado-ok': t.entradas > 0 }">{{ pesos(t.entradas) }}</td>
                    <td class="num text-right" :class="{ 'text-estado-error': t.salidas > 0 }">{{ pesos(-t.salidas) }}</td>
                    <td class="num text-right">{{ pesos(t.neto) }}</td>
                    <td class="num text-right font-semibold" :class="t.saldo < 0 ? 'text-estado-error' : ''">{{ pesos(t.saldo) }}</td>
                </tr>
            </tbody>
        </table>
    </section>

    <div class="grid gap-6 lg:grid-cols-5">
        <section class="tarjeta lg:col-span-3">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Semana a semana</h2>
            <ul class="space-y-3 p-4">
                <li v-for="s in semanas" :key="s.desde" class="grid grid-cols-[90px_1fr_120px] items-center gap-3">
                    <span class="num text-[14px] text-grafito">{{ fecha(s.desde).slice(0, 5) }}</span>
                    <span class="space-y-1">
                        <span class="block h-2 rounded-full bg-hormigon"><span class="block h-2 rounded-full bg-estado-ok" :style="{ width: `${(s.entradas / escala) * 100}%` }" /></span>
                        <span class="block h-2 rounded-full bg-hormigon"><span class="block h-2 rounded-full bg-naranja" :style="{ width: `${(s.salidas / escala) * 100}%` }" /></span>
                    </span>
                    <span class="num text-right text-[14px] font-semibold" :class="s.saldo < 0 ? 'text-estado-error' : ''">{{ pesos(s.saldo) }}</span>
                </li>
            </ul>
            <p class="border-t border-linea p-4 text-[13px] text-grafito"><span class="mr-1 inline-block h-2 w-4 rounded-full bg-estado-ok" /> Cobros <span class="ml-4 mr-1 inline-block h-2 w-4 rounded-full bg-naranja" /> Pagos · a la derecha, saldo al cierre de la semana.</p>
        </section>
        <section class="tarjeta lg:col-span-2">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Próximos 90 días</h2>
            <ul v-if="proximos.length" class="max-h-[560px] divide-y divide-linea overflow-y-auto">
                <li v-for="(m, i) in proximos" :key="i" class="flex items-start justify-between gap-3 p-4">
                    <div>
                        <Link :href="m.url" class="font-semibold">{{ m.detalle }}</Link>
                        <p class="text-[13px] text-grafito"><span class="num">{{ fecha(m.fecha) }}</span> · <Chip chico :tono="m.fecha < hoy ? 'error' : m.tipo === 'cobro' ? 'ok' : 'neutro'">{{ m.fecha < hoy ? 'Vencido' : m.tipo === 'cobro' ? 'Cobro' : 'Pago' }}</Chip></p>
                    </div>
                    <p class="num font-semibold" :class="m.monto < 0 ? 'text-estado-error' : 'text-estado-ok'">{{ pesos(m.monto) }}</p>
                </li>
            </ul>
            <Vacio v-else titulo="Sin movimientos" texto="No hay cobros ni pagos pendientes." />
        </section>
    </div>
</template>
