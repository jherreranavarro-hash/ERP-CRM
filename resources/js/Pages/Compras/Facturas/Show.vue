<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import { fecha, pesos, rut } from '@/lib/formato';
import { cantidad, estadoFacturaProveedor, estadoPagoProveedor } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Pago { id: number; fecha: string; monto: number; metodo: string; referencia: string | null; estado: string; registrador: string | null; aprobador: string | null; propio: boolean; motivo_rechazo: string | null }
interface Factura {
    id: number; nombre: string; estado: string; proveedor: { id: number; razon_social: string; rut: string }; emision: string; vencimiento: string; oc: { id: number; numero: number } | null;
    neto: number; exento: number; iva: number; total: number; saldo: number; diferencias: string[]; motivo_rechazo: string | null; observaciones: string | null;
    registrador: string | null; aprobador: string | null; propia: boolean;
    lineas: { id: number; descripcion: string; cantidad: string; costo_unitario: string; monto: number; costo_oc: string | null }[];
    pagos: Pago[];
}
const props = defineProps<{ factura: Factura; metodos: Record<string, string>; limitePago: number }>();
const puede = usePermisos();
const page = usePage();
const f = computed(() => props.factura);
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);

const enTramite = computed(() => f.value.pagos.filter((p) => p.estado === 'por_aprobar').reduce((s, p) => s + p.monto, 0));
const disponible = computed(() => f.value.saldo - enTramite.value);
const hoy = new Date().toISOString().slice(0, 10);
const pago = useForm({ monto: 0, fecha: hoy, metodo: 'transferencia', referencia: '' });
const pagoAbierto = ref(false);
const abrirPago = () => { pago.reset(); pago.clearErrors(); pago.monto = disponible.value; pagoAbierto.value = true; };
const pagar = () => pago.post(`/finanzas/cuentas-por-pagar/${f.value.id}/pagos`, { preserveScroll: true, onSuccess: () => (pagoAbierto.value = false) });

const rechazo = useForm({ motivo: '' });
const rechazo_ = ref<{ url: string; titulo: string } | null>(null);
const rechazar = () => rechazo.post(rechazo_.value!.url, { preserveScroll: true, onSuccess: () => { rechazo_.value = null; rechazo.reset(); } });
const aprobar = () => confirm('¿Aceptar las diferencias y pasar la factura a pago?') && router.post(`/compras/facturas/${f.value.id}/aprobar`, {}, { preserveScroll: true });
const aprobarPago = (p: Pago) => confirm(`¿Aprobar el pago de ${pesos(p.monto)}?`) && router.post(`/finanzas/pagos-proveedor/${p.id}/aprobar`, {}, { preserveScroll: true });
</script>

<template>
    <Head :title="f.nombre" />
    <Encabezado :titulo="f.nombre" volver="/compras/facturas" :bajada="`${f.proveedor.razon_social} · registrada por ${f.registrador ?? '—'}`">
        <template v-if="f.estado === 'por_revisar' && puede('compras.factura-de-proveedores.aprobar') && !f.propia">
            <button class="btn btn-secundario" @click="rechazo_ = { url: `/compras/facturas/${f.id}/rechazar`, titulo: 'Rechazar factura' }">Rechazar</button>
            <button class="btn btn-primario" @click="aprobar">Aceptar diferencias</button>
        </template>
        <button v-if="f.estado === 'por_pagar' && disponible > 0 && puede('finanzas.cuentas-por-pagar.crear')" class="btn btn-primario" @click="abrirPago">Registrar pago</button>
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="estadoFacturaProveedor[f.estado][0]">{{ estadoFacturaProveedor[f.estado][1] }}</Chip>
        <Chip v-if="f.oc" tono="neutro"><Link :href="`/compras/ordenes/${f.oc.id}`" class="text-inherit">Orden de compra N° {{ f.oc.numero }}</Link></Chip>
        <Chip v-if="f.aprobador" tono="neutro">Revisó {{ f.aprobador }}</Chip>
    </div>
    <div v-if="errores.length && !pagoAbierto && !rechazo_" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]"><ul class="list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul></div>
    <div v-if="f.diferencias.length" class="tarjeta mb-6 p-5" :class="f.estado === 'por_revisar' ? 'border-[#F0D58A] bg-[#FFF3D6] text-[#8A5A00]' : ''">
        <p class="font-semibold">{{ f.estado === 'por_revisar' ? (f.propia ? 'No calza; debe revisarla otra persona:' : 'No calza con la orden y lo recibido:') : 'Diferencias encontradas al registrar:' }}</p>
        <ul class="mt-1 list-disc pl-5"><li v-for="d in f.diferencias" :key="d">{{ d }}</li></ul>
    </div>
    <div v-else-if="f.oc" class="tarjeta mb-6 border-[#B7E1C7] bg-[#E8F6EE] p-4 text-[#1E6B3F]">Calza con la orden de compra y lo recibido (3-way match).</div>
    <div v-if="f.motivo_rechazo" class="tarjeta mb-6 p-5"><span class="font-semibold">Motivo del rechazo:</span> {{ f.motivo_rechazo }}</div>

    <section class="tarjeta mb-6 grid gap-4 p-5 md:grid-cols-4">
        <div><p class="etiqueta">Proveedor</p><p class="font-semibold">{{ f.proveedor.razon_social }}</p><p class="num text-grafito">{{ rut(f.proveedor.rut) }}</p></div>
        <div><p class="etiqueta">Emisión · vencimiento</p><p class="num">{{ fecha(f.emision) }} · {{ fecha(f.vencimiento) }}</p></div>
        <div><p class="etiqueta">Total</p><p class="num text-[20px] font-bold">{{ pesos(f.total) }}</p><p class="num text-[13px] text-grafito">neto {{ pesos(f.neto) }}<template v-if="f.exento"> · exento {{ pesos(f.exento) }}</template> · IVA {{ pesos(f.iva) }}</p></div>
        <div><p class="etiqueta">Saldo por pagar</p><p class="num text-[20px] font-bold text-naranja-profundo">{{ pesos(f.saldo) }}</p><p v-if="enTramite" class="text-[13px] text-[#8A5A00]">{{ pesos(enTramite) }} en pagos por aprobar</p></div>
    </section>

    <section v-if="f.lineas.length" class="tarjeta mb-6 overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Detalle</th><th class="text-right">Cantidad</th><th class="text-right">Costo unitario</th><th class="text-right">Costo en OC</th><th class="text-right">Subtotal</th></tr></thead>
            <tbody>
                <tr v-for="l in f.lineas" :key="l.id">
                    <td class="font-semibold">{{ l.descripcion }}</td>
                    <td class="num text-right">{{ cantidad(l.cantidad) }}</td>
                    <td class="num text-right">{{ pesos(Number(l.costo_unitario)) }}</td>
                    <td class="num text-right text-grafito">{{ l.costo_oc === null ? '—' : pesos(Number(l.costo_oc)) }}</td>
                    <td class="num text-right font-semibold">{{ pesos(l.monto) }}</td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="tarjeta">
        <h2 class="border-b border-linea p-4 text-[18px] font-bold">Pagos</h2>
        <div v-if="f.pagos.length" class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Fecha</th><th>Medio</th><th>Referencia</th><th>Registró</th><th class="text-right">Monto</th><th>Estado</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="p in f.pagos" :key="p.id">
                        <td class="num">{{ fecha(p.fecha) }}</td>
                        <td>{{ p.metodo }}</td>
                        <td class="num">{{ p.referencia ?? '—' }}</td>
                        <td>{{ p.registrador }}<div v-if="p.aprobador" class="text-[13px] text-grafito">aprobó {{ p.aprobador }}</div><div v-if="p.motivo_rechazo" class="text-[13px] text-estado-error">{{ p.motivo_rechazo }}</div></td>
                        <td class="num text-right font-semibold">{{ pesos(p.monto) }}</td>
                        <td><Chip chico :tono="estadoPagoProveedor[p.estado][0]">{{ estadoPagoProveedor[p.estado][1] }}</Chip></td>
                        <td class="whitespace-nowrap">
                            <div v-if="p.estado === 'por_aprobar' && puede('finanzas.cuentas-por-pagar.aprobar') && !p.propio" class="flex gap-2">
                                <button class="btn btn-secundario btn-sm" @click="rechazo_ = { url: `/finanzas/pagos-proveedor/${p.id}/rechazar`, titulo: 'Rechazar pago' }">Rechazar</button>
                                <button class="btn btn-primario btn-sm" @click="aprobarPago(p)">Aprobar</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="p-4 text-grafito">Sin pagos registrados.</p>
    </section>
    <p v-if="f.observaciones" class="mt-4 text-[15px] text-grafito"><span class="font-semibold text-carbon">Observaciones:</span> {{ f.observaciones }}</p>

    <Modal :abierto="pagoAbierto" titulo="Registrar pago" @cerrar="pagoAbierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Monto" id="monto" obligatorio :error="pago.errors.monto || (pago.errors as any).factura" :ayuda="`Hasta ${pesos(disponible)}. Sobre ${pesos(limitePago)} lo aprueba otra persona.`"><input id="monto" v-model.number="pago.monto" type="number" min="1" :max="disponible" class="campo num h-11 text-right" /></Campo>
            <Campo etiqueta="Fecha" id="fecha" obligatorio :error="pago.errors.fecha"><input id="fecha" v-model="pago.fecha" type="date" :max="hoy" class="campo num h-11" /></Campo>
            <Campo etiqueta="Medio de pago" id="metodo" obligatorio :error="pago.errors.metodo"><select id="metodo" v-model="pago.metodo" class="campo h-11"><option v-for="(n, k) in metodos" :key="k" :value="k">{{ n }}</option></select></Campo>
            <Campo etiqueta="Referencia" id="ref" :error="pago.errors.referencia" ayuda="N° de transferencia o de cheque."><input id="ref" v-model="pago.referencia" class="campo h-11" maxlength="60" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="pagoAbierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="pago.processing" @click="pagar">Registrar pago</button>
        </template>
    </Modal>

    <Modal :abierto="rechazo_ !== null" :titulo="rechazo_?.titulo ?? ''" @cerrar="rechazo_ = null">
        <Campo etiqueta="Motivo" id="motivo" obligatorio :error="rechazo.errors.motivo || (rechazo.errors as any).factura || (rechazo.errors as any).pago"><input id="motivo" v-model="rechazo.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="rechazo_ = null">Cancelar</button>
            <button class="btn btn-peligro" :disabled="rechazo.processing" @click="rechazar">Rechazar</button>
        </template>
    </Modal>
</template>
