<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import { fecha, pesos, rut } from '@/lib/formato';
import { cantidad, estadoDoc, estadoOrden } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Linea { producto_id: number | null; codigo: string | null; descripcion: string; cantidad: string; unidad: string; precio_unitario: string; descuento_pct: string; descuento_monto: number; monto_item: number; fisico: number | null }
interface Orden {
    id: number; numero: number; estado: string; fecha: string; reserva_hasta: string; forma_pago: string; local: string | null;
    cliente: { id: number; rut: string; razon_social: string; giro: string | null; direccion: string | null; comuna: string | null } | null;
    cotizacion: { id: number; numero: number } | null; documento: { id: number; nombre: string; estado: string } | null;
    neto: number; exento: number; iva: number; total: number; descuento_global_pct: string; descuento_global_monto: number;
    observaciones: string | null; motivo_anulacion: string | null; creador: string | null; lineas: Linea[];
}
const props = defineProps<{ orden: Orden; puedeFacturar: boolean }>();
const puede = usePermisos();
const page = usePage();
const o = computed(() => props.orden);
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);
const abierta = computed(() => ['confirmada', 'vencida'].includes(o.value.estado));

const facturacion = useForm({ tipo_dte: 33 });
const facturarAbierto = ref(false);
const anulacion = useForm({ motivo: '' });
const anularAbierto = ref(false);
const corto = (l: Linea) => l.fisico !== null && l.fisico < Number(l.cantidad);
</script>

<template>
    <Head :title="`Orden de venta N° ${o.numero}`" />
    <Encabezado :titulo="`Orden de venta N° ${o.numero}`" volver="/ventas/ordenes" :bajada="`${o.local ?? ''} · creada por ${o.creador ?? '—'}`">
        <button v-if="abierta && puede('ventascrm.orden-de-venta.eliminar')" class="btn btn-secundario" @click="anularAbierto = true">Anular</button>
        <Link v-if="o.documento" :href="`/caja/documentos/${o.documento.id}`" class="btn btn-primario no-underline">Ver {{ o.documento.nombre }}</Link>
        <button v-else-if="abierta && puedeFacturar" class="btn btn-primario" @click="facturarAbierto = true">Facturar</button>
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="estadoOrden[o.estado][0]">{{ estadoOrden[o.estado][1] }}</Chip>
        <Chip v-if="o.forma_pago === 'credito'" tono="info">Crédito</Chip>
        <Chip v-if="o.documento" :tono="estadoDoc[o.documento.estado][0]">{{ o.documento.nombre }} · {{ estadoDoc[o.documento.estado][1] }}</Chip>
    </div>

    <div v-if="errores.length && !facturarAbierto && !anularAbierto" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">
        <p class="font-semibold">No se pudo completar la acción:</p>
        <ul class="mt-1 list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul>
    </div>
    <div v-if="o.estado === 'confirmada'" class="tarjeta mb-6 border-[#B8D4F0] bg-[#EAF3FC] p-5 text-[#1D4F80]">El stock queda reservado en {{ o.local }} hasta el {{ fecha(o.reserva_hasta) }}. Al emitir el documento se descuenta.</div>
    <div v-else-if="o.estado === 'vencida'" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6] p-5 text-[#8A5A00]">La reserva venció el {{ fecha(o.reserva_hasta) }}: al facturar se vuelve a verificar que el stock alcance.</div>
    <div v-if="o.motivo_anulacion" class="tarjeta mb-6 p-5"><span class="font-semibold">Motivo de anulación:</span> {{ o.motivo_anulacion }}</div>

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <div class="space-y-6">
            <section class="tarjeta grid gap-4 p-5 md:grid-cols-3">
                <div class="md:col-span-2">
                    <p class="etiqueta">Cliente</p>
                    <template v-if="o.cliente"><p class="font-semibold">{{ o.cliente.razon_social }}</p><p class="num text-grafito">{{ rut(o.cliente.rut) }}</p></template>
                </div>
                <div>
                    <p class="etiqueta">Origen</p>
                    <Link v-if="o.cotizacion" :href="`/ventas/cotizaciones/${o.cotizacion.id}`">Cotización N° {{ o.cotizacion.numero }}</Link>
                    <p class="num text-grafito">{{ fecha(o.fecha) }}</p>
                </div>
            </section>

            <section class="tarjeta overflow-x-auto">
                <table class="tabla">
                    <thead><tr><th>Descripción</th><th class="text-right">Cant.</th><th class="text-right">Stock físico</th><th class="text-right">Precio neto</th><th class="text-right">Desc.</th><th class="text-right">Monto</th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in o.lineas" :key="i">
                            <td><div>{{ l.descripcion }}</div><div v-if="l.codigo" class="num text-[13px] text-grafito">{{ l.codigo }}</div></td>
                            <td class="num text-right">{{ cantidad(l.cantidad) }} {{ l.unidad }}</td>
                            <td class="num text-right" :class="abierta && corto(l) ? 'font-semibold text-estado-error' : ''">
                                <Link v-if="l.producto_id" :href="`/inventario/stock/${l.producto_id}/kardex`">{{ l.fisico === null ? '—' : cantidad(l.fisico) }}</Link><span v-else>—</span>
                            </td>
                            <td class="num text-right">{{ pesos(Number(l.precio_unitario)) }}</td>
                            <td class="num text-right">{{ l.descuento_monto ? pesos(-l.descuento_monto) : '—' }}</td>
                            <td class="num text-right font-semibold">{{ pesos(l.monto_item) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <p v-if="o.observaciones" class="text-[15px] text-grafito"><span class="font-semibold text-carbon">Observaciones:</span> {{ o.observaciones }}</p>
        </div>

        <aside class="tarjeta self-start p-5">
            <dl class="space-y-2 text-[15px]">
                <div v-if="o.descuento_global_monto" class="flex justify-between"><dt class="text-grafito">Descuento global {{ cantidad(o.descuento_global_pct) }} %</dt><dd class="num">{{ pesos(-o.descuento_global_monto) }}</dd></div>
                <div class="flex justify-between"><dt class="text-grafito">Neto</dt><dd class="num">{{ pesos(o.neto) }}</dd></div>
                <div v-if="o.exento" class="flex justify-between"><dt class="text-grafito">Exento</dt><dd class="num">{{ pesos(o.exento) }}</dd></div>
                <div class="flex justify-between"><dt class="text-grafito">IVA</dt><dd class="num">{{ pesos(o.iva) }}</dd></div>
                <div class="flex items-baseline justify-between border-t border-linea pt-3"><dt class="font-semibold">Total</dt><dd class="num text-[28px] font-bold text-naranja-profundo">{{ pesos(o.total) }}</dd></div>
            </dl>
        </aside>
    </div>

    <Modal :abierto="facturarAbierto" titulo="Facturar orden de venta" @cerrar="facturarAbierto = false">
        <p class="mb-4 text-[15px] text-grafito">Se crea un borrador con los precios de la orden. La reserva se mantiene hasta que el documento se emite; ahí se descuenta el stock.</p>
        <fieldset class="space-y-2">
            <legend class="etiqueta">Documento</legend>
            <label class="flex items-center gap-2"><input v-model.number="facturacion.tipo_dte" type="radio" :value="33" /> Factura electrónica</label>
            <label class="flex items-center gap-2"><input v-model.number="facturacion.tipo_dte" type="radio" :value="39" /> Boleta electrónica <span class="text-[14px] text-grafito">(precios pasan a IVA incluido)</span></label>
        </fieldset>
        <p v-if="(facturacion.errors as any).orden || (facturacion.errors as any).stock" class="error mt-3">{{ (facturacion.errors as any).orden || (facturacion.errors as any).stock }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="facturarAbierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="facturacion.processing" @click="facturacion.post(`/ventas/ordenes/${o.id}/facturar`)">Crear borrador</button>
        </template>
    </Modal>

    <Modal :abierto="anularAbierto" titulo="Anular orden de venta" @cerrar="anularAbierto = false">
        <p class="mb-4 text-[15px] text-grafito">La reserva de stock se libera. Si hay un borrador de documento, se elimina.</p>
        <Campo etiqueta="Motivo" id="motivo" obligatorio :error="anulacion.errors.motivo || (anulacion.errors as any).orden"><input id="motivo" v-model="anulacion.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="anularAbierto = false">Cancelar</button>
            <button class="btn btn-peligro" :disabled="anulacion.processing" @click="anulacion.post(`/ventas/ordenes/${o.id}/anular`, { preserveScroll: true, onSuccess: () => (anularAbierto = false) })">Anular orden</button>
        </template>
    </Modal>
</template>
