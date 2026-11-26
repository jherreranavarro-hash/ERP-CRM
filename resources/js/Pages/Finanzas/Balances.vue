<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Kpi from '@/Components/Kpi.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Saldo { cuenta_id: number; codigo: string; nombre: string; tipo: string; debe: number; haber: number; saldo: number; deudor?: number; acreedor?: number }
interface Mov { fecha: string; numero: number | null; asiento_id: number | null; glosa: string; debe: number; haber: number; saldo: number }
type Filtros = { desde: string; hasta: string; centro_costo_id: number | null; cuenta_id: number | null };
const props = defineProps<{ vista: string; vistas: Record<string, string>; filtros: Filtros; centros: { id: number; codigo: string; nombre: string }[]; cuentas: { id: number; codigo: string; nombre: string }[]; filas: (Saldo | Mov)[]; totales: Record<string, number> | null }>();
const puede = usePermisos();
const f = reactive({ vista: props.vista, ...props.filtros });
watch(f, () => router.get('/finanzas/balances', limpio(), { preserveState: true, replace: true }));
function limpio() {
    const q: Record<string, string> = { vista: f.vista, desde: f.desde, hasta: f.hasta };
    if (f.vista === 'resultados' && f.centro_costo_id) q.centro_costo_id = String(f.centro_costo_id);
    if (f.vista === 'mayor' && f.cuenta_id) q.cuenta_id = String(f.cuenta_id);
    return q;
}
const exportar = computed(() => `/finanzas/balances?${new URLSearchParams({ ...limpio(), exportar: '1' })}`);
const saldos = computed(() => props.filas as Saldo[]);
const movs = computed(() => props.filas as Mov[]);
const de = (tipo: string) => saldos.value.filter((s) => s.tipo === tipo);
const t = computed(() => props.totales ?? {});
</script>

<template>
    <Head :title="vistas[vista]" />
    <Encabezado titulo="Balances y reportes" bajada="Calculados en línea desde los asientos contables. El balance general es a la fecha de cierre; los demás, del período.">
        <a v-if="puede('finanzas.balances-y-reportes.exportar')" :href="exportar" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
    </Encabezado>

    <div class="tarjeta mb-6 flex flex-wrap items-end gap-3 p-4">
        <div class="flex flex-wrap gap-2" role="tablist">
            <button v-for="(n, k) in vistas" :key="k" role="tab" :aria-selected="f.vista === k" class="btn btn-sm" :class="f.vista === k ? 'btn-oscuro' : 'btn-secundario'" @click="f.vista = k">{{ n }}</button>
        </div>
        <div class="ml-auto flex flex-wrap gap-2">
            <select v-if="f.vista === 'mayor'" v-model="f.cuenta_id" class="campo h-10 w-auto" aria-label="Cuenta"><option :value="null">Elige una cuenta</option><option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select>
            <select v-if="f.vista === 'resultados'" v-model="f.centro_costo_id" class="campo h-10 w-auto" aria-label="Centro de costo"><option :value="null">Todos los centros</option><option v-for="c in centros" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select>
            <input v-if="f.vista !== 'general'" v-model="f.desde" type="date" class="campo h-10 w-auto" aria-label="Desde" />
            <input v-model="f.hasta" type="date" class="campo h-10 w-auto" :aria-label="f.vista === 'general' ? 'Al' : 'Hasta'" />
        </div>
    </div>

    <!-- Comprobación -->
    <div v-if="vista === 'comprobacion'" class="tarjeta overflow-x-auto">
        <table v-if="saldos.length" class="tabla">
            <thead><tr><th>Código</th><th>Cuenta</th><th class="text-right">Debe</th><th class="text-right">Haber</th><th class="text-right">Deudor</th><th class="text-right">Acreedor</th></tr></thead>
            <tbody>
                <tr v-for="s in saldos" :key="s.cuenta_id">
                    <td class="num">{{ s.codigo }}</td>
                    <td><Link :href="`/finanzas/balances?vista=mayor&cuenta_id=${s.cuenta_id}&desde=${f.desde}&hasta=${f.hasta}`">{{ s.nombre }}</Link></td>
                    <td class="num text-right">{{ pesos(s.debe) }}</td><td class="num text-right">{{ pesos(s.haber) }}</td>
                    <td class="num text-right">{{ s.deudor ? pesos(s.deudor) : '' }}</td><td class="num text-right">{{ s.acreedor ? pesos(s.acreedor) : '' }}</td>
                </tr>
            </tbody>
            <tfoot><tr class="font-semibold"><td colspan="2">Sumas iguales</td><td class="num text-right">{{ pesos(t.debe) }}</td><td class="num text-right">{{ pesos(t.haber) }}</td><td class="num text-right">{{ pesos(t.deudor) }}</td><td class="num text-right">{{ pesos(t.acreedor) }}</td></tr></tfoot>
        </table>
        <Vacio v-else titulo="Sin movimientos en el período" />
    </div>

    <!-- Resultados -->
    <template v-else-if="vista === 'resultados'">
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <Kpi etiqueta="Ingresos" :valor="pesos(t.ingresos)" />
            <Kpi etiqueta="Costos y gastos" :valor="pesos(t.gastos)" />
            <Kpi etiqueta="Resultado del período" :valor="pesos(t.resultado)" :tono="t.resultado < 0 ? 'error' : 'ok'" />
        </div>
        <div class="grid gap-6 lg:grid-cols-2">
            <section v-for="g in [{ k: 'ingreso', n: 'Ingresos', total: t.ingresos }, { k: 'gasto', n: 'Costos y gastos', total: t.gastos }]" :key="g.k" class="tarjeta">
                <h2 class="border-b border-linea p-4 text-[18px] font-bold">{{ g.n }}</h2>
                <table class="tabla">
                    <tbody><tr v-for="s in de(g.k)" :key="s.cuenta_id"><td class="num w-24">{{ s.codigo }}</td><td>{{ s.nombre }}</td><td class="num text-right">{{ pesos(s.saldo) }}</td></tr></tbody>
                    <tfoot><tr class="font-semibold"><td colspan="2">Total</td><td class="num text-right">{{ pesos(g.total) }}</td></tr></tfoot>
                </table>
            </section>
        </div>
    </template>

    <!-- General -->
    <template v-else-if="vista === 'general'">
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <Kpi etiqueta="Activos" :valor="pesos(t.activo)" />
            <Kpi etiqueta="Pasivos + patrimonio + resultado" :valor="pesos(t.pasivo + t.patrimonio + t.resultado)" />
            <Kpi etiqueta="Cuadratura" :valor="t.activo === t.pasivo + t.patrimonio + t.resultado ? 'Cuadra' : pesos(t.activo - t.pasivo - t.patrimonio - t.resultado)" :tono="t.activo === t.pasivo + t.patrimonio + t.resultado ? 'ok' : 'error'" />
        </div>
        <div class="grid gap-6 lg:grid-cols-2">
            <section class="tarjeta">
                <h2 class="border-b border-linea p-4 text-[18px] font-bold">Activo</h2>
                <table class="tabla">
                    <tbody><tr v-for="s in de('activo')" :key="s.cuenta_id"><td class="num w-24">{{ s.codigo }}</td><td>{{ s.nombre }}</td><td class="num text-right">{{ pesos(s.saldo) }}</td></tr></tbody>
                    <tfoot><tr class="font-semibold"><td colspan="2">Total activo</td><td class="num text-right">{{ pesos(t.activo) }}</td></tr></tfoot>
                </table>
            </section>
            <section class="tarjeta">
                <h2 class="border-b border-linea p-4 text-[18px] font-bold">Pasivo y patrimonio</h2>
                <table class="tabla">
                    <tbody>
                        <tr v-for="s in [...de('pasivo'), ...de('patrimonio')]" :key="s.cuenta_id"><td class="num w-24">{{ s.codigo }}</td><td>{{ s.nombre }}</td><td class="num text-right">{{ pesos(s.saldo) }}</td></tr>
                        <tr><td></td><td>Resultado del ejercicio</td><td class="num text-right" :class="t.resultado < 0 && 'text-estado-error'">{{ pesos(t.resultado) }}</td></tr>
                    </tbody>
                    <tfoot><tr class="font-semibold"><td colspan="2">Total pasivo y patrimonio</td><td class="num text-right">{{ pesos(t.pasivo + t.patrimonio + t.resultado) }}</td></tr></tfoot>
                </table>
            </section>
        </div>
    </template>

    <!-- Mayor -->
    <div v-else class="tarjeta overflow-x-auto">
        <table v-if="movs.length" class="tabla">
            <thead><tr><th>Fecha</th><th>Asiento</th><th>Glosa</th><th class="text-right">Debe</th><th class="text-right">Haber</th><th class="text-right">Saldo</th></tr></thead>
            <tbody>
                <tr v-for="(m, i) in movs" :key="i" :class="m.numero === null && 'text-grafito'">
                    <td class="num">{{ fecha(m.fecha) }}</td>
                    <td><Link v-if="m.asiento_id" :href="`/finanzas/asientos/${m.asiento_id}`" class="num">N° {{ m.numero }}</Link></td>
                    <td>{{ m.glosa }}</td>
                    <td class="num text-right">{{ m.debe ? pesos(m.debe) : '' }}</td><td class="num text-right">{{ m.haber ? pesos(m.haber) : '' }}</td>
                    <td class="num text-right font-semibold">{{ pesos(m.saldo) }}</td>
                </tr>
            </tbody>
        </table>
        <Vacio v-else titulo="Elige una cuenta" texto="El libro mayor muestra los movimientos de una cuenta con su saldo corrido." />
    </div>
</template>
