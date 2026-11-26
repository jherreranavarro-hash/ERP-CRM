<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import { fecha, fechaHora, pesos, rut } from '@/lib/formato';
import { cantidad, estadoFacturaProveedor, estadoOC } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Linea { id: number; producto_id: number; sku: string | null; nombre: string | null; unidad: string | null; cantidad: string; costo_unitario: string; monto: number; recibida: string; facturada: string }
interface Cotizacion { proveedor_nombre: string; monto_neto: number; plazo_entrega_dias: number | null; observacion: string | null; elegida: boolean }
interface Orden {
    id: number; numero: number; estado: string; fecha: string; fecha_entrega: string | null; proveedor: { id: number; razon_social: string; rut: string; plazo_pago_dias: number } | null;
    local: string | null; observaciones: string | null; neto: number; iva: number; total: number; motivos: string[]; motivo_rechazo: string | null;
    creador: string | null; aprobador: string | null; aprobado_at: string | null; propia: boolean; lineas: Linea[]; cotizaciones: Cotizacion[];
    recepciones: { id: number; numero: number; fecha: string; estado: string; costo_total: number }[];
    facturas: { id: number; nombre: string; fecha: string; total: number; estado: string }[];
}
const props = defineProps<{ orden: Orden; estados: Record<string, string> }>();
const puede = usePermisos();
const page = usePage();
const o = computed(() => props.orden);
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);

const post = (accion: string, pregunta: string) => confirm(pregunta) && router.post(`/compras/ordenes/${o.value.id}/${accion}`, {}, { preserveScroll: true });
const motivo = useForm({ motivo: '' });
const modal = ref<'rechazar' | 'anular' | null>(null);
const enviarMotivo = () => motivo.post(`/compras/ordenes/${o.value.id}/${modal.value}`, { preserveScroll: true, onSuccess: () => { modal.value = null; motivo.reset(); } });

const recibible = computed(() => ['aprobada', 'parcial'].includes(o.value.estado));
const porFacturar = computed(() => o.value.lineas.some((l) => Number(l.recibida) > Number(l.facturada)));
const masBarata = computed(() => Math.min(...o.value.cotizaciones.map((c) => c.monto_neto)));
const acciones = computed(() => [
    ...(o.value.estado === 'borrador' && puede('compras.orden-de-compra.eliminar') ? [{ etiqueta: 'Eliminar borrador', peligro: true, accion: () => confirm('¿Eliminar este borrador?') && router.delete(`/compras/ordenes/${o.value.id}`) }] : []),
    ...(['por_aprobar', 'aprobada'].includes(o.value.estado) && !o.value.recepciones.length && puede('compras.orden-de-compra.eliminar') ? [{ etiqueta: 'Anular orden', peligro: true, accion: () => (modal.value = 'anular') }] : []),
    ...(['parcial', 'recibida'].includes(o.value.estado) && puede('compras.orden-de-compra.editar') ? [{ etiqueta: 'Cerrar orden', accion: () => post('cerrar', '¿Cerrar la orden? Lo pendiente ya no se espera.') }] : []),
]);
</script>

<template>
    <Head :title="`Orden de compra N° ${o.numero}`" />
    <Encabezado :titulo="`Orden de compra N° ${o.numero}`" volver="/compras/ordenes" :bajada="`${o.proveedor?.razon_social ?? ''} · creada por ${o.creador ?? '—'} el ${fecha(o.fecha)}`">
        <template v-if="o.estado === 'borrador'">
            <Link v-if="puede('compras.orden-de-compra.editar')" :href="`/compras/ordenes/${o.id}/editar`" class="btn btn-secundario no-underline">Editar</Link>
            <button v-if="puede('compras.orden-de-compra.editar')" class="btn btn-primario" @click="post('enviar', '¿Enviar la orden? Ya no podrá editarse.')">Enviar orden</button>
        </template>
        <template v-if="o.estado === 'por_aprobar' && puede('compras.orden-de-compra.aprobar') && !o.propia">
            <button class="btn btn-secundario" @click="modal = 'rechazar'">Rechazar</button>
            <button class="btn btn-primario" @click="post('aprobar', '¿Aprobar esta orden de compra?')">Aprobar</button>
        </template>
        <Link v-if="recibible && puede('inventario.recepcion-de-mercaderia.crear')" :href="`/inventario/recepciones/crear?orden_compra_id=${o.id}`" class="btn btn-primario no-underline">Recibir mercadería</Link>
        <Link v-if="porFacturar && puede('compras.factura-de-proveedores.crear')" :href="`/compras/facturas/crear?orden_compra_id=${o.id}`" class="btn btn-secundario no-underline">Registrar factura</Link>
        <MenuAcciones v-if="acciones.length" :acciones="acciones" />
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="estadoOC[o.estado][0]">{{ estadoOC[o.estado][1] }}</Chip>
        <Chip v-if="o.aprobado_at" tono="info">{{ o.aprobador ? `Aprobó ${o.aprobador}` : 'Aprobada sin revisión (bajo el monto)' }} · {{ fechaHora(o.aprobado_at) }}</Chip>
    </div>
    <div v-if="errores.length && !modal" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]"><ul class="list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul></div>
    <div v-if="o.estado === 'por_aprobar'" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6] p-5 text-[#8A5A00]">
        <p class="font-semibold">{{ o.propia ? 'Debe aprobarla otra persona:' : 'Requiere aprobación:' }}</p>
        <ul class="mt-1 list-disc pl-5"><li v-for="m in o.motivos" :key="m">{{ m }}</li></ul>
    </div>
    <div v-if="o.motivo_rechazo" class="tarjeta mb-6 p-5"><span class="font-semibold">{{ o.estado === 'anulada' ? 'Motivo de la anulación' : 'Motivo del rechazo' }}:</span> {{ o.motivo_rechazo }}</div>

    <section class="tarjeta mb-6 grid gap-4 p-5 md:grid-cols-4">
        <div><p class="etiqueta">Proveedor</p><p class="font-semibold">{{ o.proveedor?.razon_social }}</p><p v-if="o.proveedor" class="num text-grafito">{{ rut(o.proveedor.rut) }} · paga a {{ o.proveedor.plazo_pago_dias }} días</p></div>
        <div><p class="etiqueta">Recibe</p><p>{{ o.local }}</p></div>
        <div><p class="etiqueta">Entrega comprometida</p><p class="num">{{ o.fecha_entrega ? fecha(o.fecha_entrega) : '—' }}</p></div>
        <div><p class="etiqueta">Total</p><p class="num text-[22px] font-bold text-naranja-profundo">{{ pesos(o.total) }}</p><p class="num text-[13px] text-grafito">neto {{ pesos(o.neto) }} · IVA {{ pesos(o.iva) }}</p></div>
    </section>

    <section class="tarjeta mb-6 overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Producto</th><th class="text-right">Pedido</th><th class="text-right">Recibido</th><th class="text-right">Facturado</th><th class="text-right">Costo unitario</th><th class="text-right">Subtotal</th></tr></thead>
            <tbody>
                <tr v-for="l in o.lineas" :key="l.id">
                    <td><Link :href="`/inventario/stock/${l.producto_id}/kardex`" class="font-semibold">{{ l.nombre }}</Link><div class="num text-[13px] text-grafito">{{ l.sku }}</div></td>
                    <td class="num text-right">{{ cantidad(l.cantidad) }} {{ l.unidad }}</td>
                    <td class="num text-right" :class="Number(l.recibida) >= Number(l.cantidad) ? 'text-estado-ok' : Number(l.recibida) > 0 ? 'text-[#8A5A00]' : 'text-grafito'">{{ cantidad(l.recibida) }}</td>
                    <td class="num text-right text-grafito">{{ cantidad(l.facturada) }}</td>
                    <td class="num text-right">{{ pesos(Number(l.costo_unitario)) }}</td>
                    <td class="num text-right font-semibold">{{ pesos(l.monto) }}</td>
                </tr>
            </tbody>
        </table>
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        <section v-if="o.cotizaciones.length" class="tarjeta">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Cotizaciones comparadas</h2>
            <ul class="divide-y divide-linea">
                <li v-for="c in o.cotizaciones" :key="c.proveedor_nombre + c.monto_neto" class="flex items-start justify-between gap-3 p-4">
                    <div><p class="font-semibold">{{ c.proveedor_nombre }}</p><p class="text-[13px] text-grafito">{{ [c.plazo_entrega_dias !== null ? `${c.plazo_entrega_dias} días` : null, c.observacion].filter(Boolean).join(' · ') }}</p></div>
                    <div class="text-right"><p class="num font-semibold">{{ pesos(c.monto_neto) }}</p><div class="mt-1 flex justify-end gap-1"><Chip v-if="c.elegida" chico tono="info">Elegida</Chip><Chip v-if="c.monto_neto === masBarata" chico tono="ok">Más barata</Chip></div></div>
                </li>
            </ul>
        </section>
        <section class="tarjeta">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Recepciones</h2>
            <ul v-if="o.recepciones.length" class="divide-y divide-linea">
                <li v-for="r in o.recepciones" :key="r.id" class="flex items-center justify-between p-4">
                    <div><Link :href="`/inventario/recepciones/${r.id}`" class="font-semibold">Recepción N° {{ r.numero }}</Link><p class="num text-[13px] text-grafito">{{ fecha(r.fecha) }}</p></div>
                    <div class="text-right"><p class="num">{{ pesos(r.costo_total) }}</p><Chip chico :tono="r.estado === 'confirmada' ? 'ok' : 'neutro'">{{ r.estado === 'confirmada' ? 'Confirmada' : 'Borrador' }}</Chip></div>
                </li>
            </ul>
            <p v-else class="p-4 text-grafito">Aún no llega mercadería de esta orden.</p>
        </section>
        <section class="tarjeta">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Facturas del proveedor</h2>
            <ul v-if="o.facturas.length" class="divide-y divide-linea">
                <li v-for="f in o.facturas" :key="f.id" class="flex items-center justify-between p-4">
                    <div><Link :href="`/compras/facturas/${f.id}`" class="font-semibold">{{ f.nombre }}</Link><p class="num text-[13px] text-grafito">{{ fecha(f.fecha) }}</p></div>
                    <div class="text-right"><p class="num">{{ pesos(f.total) }}</p><Chip chico :tono="estadoFacturaProveedor[f.estado][0]">{{ estadoFacturaProveedor[f.estado][1] }}</Chip></div>
                </li>
            </ul>
            <p v-else class="p-4 text-grafito">Sin facturas registradas.</p>
        </section>
    </div>
    <p v-if="o.observaciones" class="mt-4 text-[15px] text-grafito"><span class="font-semibold text-carbon">Observaciones:</span> {{ o.observaciones }}</p>

    <Modal :abierto="modal !== null" :titulo="modal === 'anular' ? 'Anular orden de compra' : 'Rechazar orden de compra'" @cerrar="modal = null">
        <Campo etiqueta="Motivo" id="motivo" obligatorio :error="motivo.errors.motivo || (motivo.errors as any).orden"><input id="motivo" v-model="motivo.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="modal = null">Cancelar</button>
            <button class="btn btn-peligro" :disabled="motivo.processing" @click="enviarMotivo">{{ modal === 'anular' ? 'Anular' : 'Rechazar' }}</button>
        </template>
    </Modal>
</template>
