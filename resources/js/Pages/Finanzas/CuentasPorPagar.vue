<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; nombre: string; proveedor: string | null; emision: string; vencimiento: string; total: number; saldo: number; tramo: string; vencido: boolean }
interface Semana { desde: string | null; hasta: string; nombre: string | null; monto: number; cantidad: number }
interface PagoPendiente { id: number; factura_id: number; factura: string | null; proveedor: string | null; monto: number; fecha: string; metodo: string; registrador: string | null; propio: boolean }
const props = defineProps<{ tramos: { clave: string; nombre: string; monto: number }[]; total: number; semanas: Semana[]; facturas: Paginado<Fila>; pagosPorAprobar: PagoPendiente[]; porRevisar: number; filtros: { buscar?: string } }>();
const puede = usePermisos();
const f = reactive({ buscar: props.filtros.buscar ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/finanzas/cuentas-por-pagar', { ...f }, { preserveState: true, replace: true }), 300); });

const maxSemana = computed(() => Math.max(1, ...props.semanas.map((s) => s.monto)));
const etiquetaSemana = (s: Semana) => s.nombre ?? `${fecha(s.desde).slice(0, 5)} – ${fecha(s.hasta).slice(0, 5)}`;
const aprobar = (p: PagoPendiente) => confirm(`¿Aprobar el pago de ${pesos(p.monto)} a ${p.proveedor}?`) && router.post(`/finanzas/pagos-proveedor/${p.id}/aprobar`, {}, { preserveScroll: true });
const rechazo = useForm({ motivo: '' });
const rechazando = ref<PagoPendiente | null>(null);
const rechazar = () => rechazo.post(`/finanzas/pagos-proveedor/${rechazando.value!.id}/rechazar`, { preserveScroll: true, onSuccess: () => { rechazando.value = null; rechazo.reset(); } });
</script>

<template>
    <Head title="Cuentas por pagar" />
    <Encabezado titulo="Cuentas por pagar" bajada="Facturas de proveedor por pagar, por antigüedad desde el vencimiento, y el calendario de pagos de las próximas semanas.">
        <a v-if="puede('finanzas.cuentas-por-pagar.exportar')" :href="`/finanzas/cuentas-por-pagar?exportar=1&buscar=${encodeURIComponent(f.buscar)}`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
    </Encabezado>

    <Link v-if="porRevisar" href="/compras/facturas?estado=por_revisar" class="tarjeta mb-6 block border-[#F0D58A] bg-[#FFF3D6] p-4 text-[#8A5A00] no-underline">
        {{ porRevisar === 1 ? '1 factura con diferencias' : `${porRevisar} facturas con diferencias` }} aún no pasa a pago: revísala en Facturas de proveedores.
    </Link>

    <div class="mb-6 grid gap-4 sm:grid-cols-3 xl:grid-cols-6">
        <Kpi etiqueta="Total por pagar" :valor="pesos(total)" tono="naranja" />
        <Kpi v-for="x in tramos" :key="x.clave" :etiqueta="x.nombre" :valor="pesos(x.monto)" :tono="x.clave !== 'al_dia' && x.monto ? 'error' : 'normal'" />
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-5">
        <section class="tarjeta lg:col-span-3">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Calendario de pagos</h2>
            <ul class="space-y-3 p-4">
                <li v-for="s in semanas" :key="s.hasta" class="grid grid-cols-[110px_1fr_auto] items-center gap-3">
                    <span class="num text-[14px]" :class="s.nombre ? 'font-semibold text-estado-error' : 'text-grafito'">{{ etiquetaSemana(s) }}</span>
                    <span class="h-3 rounded-full bg-hormigon"><span class="block h-3 rounded-full" :class="s.nombre ? 'bg-estado-error' : 'bg-naranja'" :style="{ width: `${(s.monto / maxSemana) * 100}%` }" /></span>
                    <span class="num w-32 text-right text-[14px]">{{ s.monto ? pesos(s.monto) : '—' }}<span v-if="s.cantidad" class="text-grafito"> · {{ s.cantidad }}</span></span>
                </li>
            </ul>
        </section>
        <section class="tarjeta lg:col-span-2">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Pagos por aprobar</h2>
            <ul v-if="pagosPorAprobar.length" class="divide-y divide-linea">
                <li v-for="p in pagosPorAprobar" :key="p.id" class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div><Link :href="`/compras/facturas/${p.factura_id}`" class="font-semibold">{{ p.proveedor }}</Link><p class="text-[13px] text-grafito">{{ p.factura }} · {{ p.metodo }} · {{ fecha(p.fecha) }} · registró {{ p.registrador }}</p></div>
                        <p class="num font-semibold">{{ pesos(p.monto) }}</p>
                    </div>
                    <div v-if="puede('finanzas.cuentas-por-pagar.aprobar')" class="mt-2 flex justify-end gap-2">
                        <span v-if="p.propio" class="text-[13px] text-grafito">Lo aprueba otra persona</span>
                        <template v-else>
                            <button class="btn btn-secundario btn-sm" @click="rechazando = p">Rechazar</button>
                            <button class="btn btn-primario btn-sm" @click="aprobar(p)">Aprobar</button>
                        </template>
                    </div>
                </li>
            </ul>
            <p v-else class="p-4 text-grafito">No hay pagos esperando aprobación.</p>
        </section>
    </div>

    <div class="tarjeta">
        <div class="border-b border-linea p-4">
            <div class="relative max-w-md">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Buscar proveedor" aria-label="Buscar" />
            </div>
        </div>
        <div class="overflow-x-auto">
            <table v-if="facturas.data.length" class="tabla">
                <thead><tr><th>Documento</th><th>Proveedor</th><th>Emisión</th><th>Vence</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Tramo</th></tr></thead>
                <tbody>
                    <tr v-for="d in facturas.data" :key="d.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/compras/facturas/${d.id}`)">
                        <td><Link :href="`/compras/facturas/${d.id}`" class="font-semibold">{{ d.nombre }}</Link></td>
                        <td>{{ d.proveedor }}</td>
                        <td class="num">{{ fecha(d.emision) }}</td>
                        <td class="num">{{ fecha(d.vencimiento) }}</td>
                        <td class="num text-right">{{ pesos(d.total) }}</td>
                        <td class="num text-right font-semibold text-naranja-profundo">{{ pesos(d.saldo) }}</td>
                        <td><Chip chico :tono="d.vencido ? 'error' : 'ok'">{{ d.tramo }}</Chip></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Nada por pagar" texto="No hay facturas de proveedor con saldo pendiente." />
        </div>
        <Paginacion :datos="facturas" nombre="facturas" />
    </div>

    <Modal :abierto="rechazando !== null" titulo="Rechazar pago" @cerrar="rechazando = null">
        <Campo etiqueta="Motivo" id="motivo" obligatorio :error="rechazo.errors.motivo || (rechazo.errors as any).pago"><input id="motivo" v-model="rechazo.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="rechazando = null">Cancelar</button>
            <button class="btn btn-peligro" :disabled="rechazo.processing" @click="rechazar">Rechazar</button>
        </template>
    </Modal>
</template>
