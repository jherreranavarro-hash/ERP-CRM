<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import { fecha, pesos, rut } from '@/lib/formato';
import { estadoCot, estadoDoc, estadoOrden } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Linea { descripcion: string; codigo: string | null; cantidad: string; unidad: string; precio_unitario: string; precio_lista: string | null; descuento_pct: string; descuento_monto: number; monto_item: number; exento: boolean }
interface Cot {
    id: number; numero: number; fecha: string; valida_hasta: string; estado: string; estado_real: string; destinatario: string; forma_pago: string;
    cliente: { rut: string; razon_social: string; giro: string | null; direccion: string | null; comuna: string | null } | null;
    contacto_nombre: string | null; contacto_email: string | null; contacto_telefono: string | null;
    descuento_global_pct: string; descuento_global_monto: number; neto: number; exento: number; iva: number; total: number; tasa_iva: string;
    observaciones: string | null; motivo_rechazo: string | null; motivos: string[]; aprobado: boolean; propia: boolean; creador: string | null; aprobador: string | null; local: string | null;
    documento: { id: number; nombre: string; estado: string } | null; lineas: Linea[];
    orden: { id: number; numero: number; estado: string } | null; local_id: number | null; tiene_cliente: boolean;
}
const props = defineProps<{ cotizacion: Cot; puedeFacturar: boolean; puedeOrden: boolean; locales: { id: number; nombre: string }[] }>();
const puede = usePermisos();
const page = usePage();
const c = computed(() => props.cotizacion);
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);
const editable = computed(() => ['borrador', 'por_aprobar', 'enviada'].includes(c.value.estado_real));
const vencida = computed(() => c.value.estado === 'vencida');

const accion = (ruta: string) => router.post(`/ventas/cotizaciones/${c.value.id}/${ruta}`, {}, { preserveScroll: true });
const aprobacion = useForm({ comentario: '' });
const aprobarAbierto = ref(false);
const rechazo = useForm({ motivo: '' });
const rechazoAbierto = ref(false);
const conversion = useForm({ tipo_dte: 33 });
const convertirAbierto = ref(false);
const orden = useForm({ local_id: props.cotizacion.local_id ?? props.locales[0]?.id ?? null });
const ordenAbierto = ref(false);
const puedeConfirmarOrden = computed(() => props.puedeOrden && ['enviada', 'aceptada'].includes(c.value.estado));

const secundarias = computed(() => {
    const a: { etiqueta: string; peligro?: boolean; accion: () => void }[] = [];
    if (['borrador', 'por_aprobar', 'enviada', 'aceptada'].includes(c.value.estado_real) && puede('ventascrm.cotizacion.editar')) a.push({ etiqueta: 'Marcar como rechazada', peligro: true, accion: () => (rechazoAbierto.value = true) });
    if (['borrador', 'por_aprobar'].includes(c.value.estado_real) && puede('ventascrm.cotizacion.eliminar')) a.push({ etiqueta: 'Eliminar', peligro: true, accion: () => confirm('¿Eliminar esta cotización?') && router.delete(`/ventas/cotizaciones/${c.value.id}`) });
    return a;
});
const cifra = (v: string) => Number(v).toLocaleString('es-CL', { maximumFractionDigits: 6 });
</script>

<template>
    <Head :title="`Cotización N° ${c.numero}`" />
    <Encabezado :titulo="`Cotización N° ${c.numero}`" volver="/ventas/cotizaciones" :bajada="`${c.local ?? ''} · creada por ${c.creador ?? '—'}`">
        <MenuAcciones v-if="secundarias.length" :acciones="secundarias" etiqueta="Más acciones" />
        <Link v-if="editable && puede('ventascrm.cotizacion.editar')" :href="`/ventas/cotizaciones/${c.id}/editar`" class="btn btn-secundario no-underline">{{ vencida ? 'Renovar' : 'Editar' }}</Link>
        <button v-if="c.estado === 'por_aprobar' && puede('ventascrm.cotizacion.aprobar') && !c.propia" class="btn btn-primario" @click="aprobarAbierto = true">Aprobar</button>
        <button v-else-if="c.estado === 'borrador' && puede('ventascrm.cotizacion.editar')" class="btn btn-primario" @click="accion('enviar')">Marcar como enviada</button>
        <template v-else-if="c.estado === 'enviada' && puede('ventascrm.cotizacion.editar')">
            <button v-if="puedeConfirmarOrden" class="btn btn-secundario" @click="ordenAbierto = true">Confirmar orden de venta</button>
            <button v-if="puedeFacturar" class="btn btn-secundario" @click="convertirAbierto = true">Convertir en venta</button>
            <button class="btn btn-primario" @click="accion('aceptar')">Cliente aceptó</button>
        </template>
        <template v-else-if="c.estado === 'aceptada'">
            <button v-if="puedeFacturar && puede('ventascrm.cotizacion.editar')" :class="puedeConfirmarOrden ? 'btn btn-secundario' : 'btn btn-primario'" @click="convertirAbierto = true">Convertir en venta</button>
            <button v-if="puedeConfirmarOrden" class="btn btn-primario" @click="ordenAbierto = true">Confirmar orden de venta</button>
        </template>
        <Link v-if="c.orden" :href="`/ventas/ordenes/${c.orden.id}`" class="btn btn-primario no-underline">Ver orden N° {{ c.orden.numero }}</Link>
        <Link v-if="c.documento" :href="`/caja/documentos/${c.documento.id}`" class="btn btn-primario no-underline">Ver {{ c.documento.nombre }}</Link>
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="estadoCot[c.estado][0]">{{ estadoCot[c.estado][1] }}</Chip>
        <Chip v-if="c.forma_pago === 'credito'" tono="info">Crédito</Chip>
        <Chip v-if="c.aprobado" tono="ok">Aprobada por {{ c.aprobador }}</Chip>
        <Chip v-if="c.orden" :tono="estadoOrden[c.orden.estado][0]">Orden N° {{ c.orden.numero }} · {{ estadoOrden[c.orden.estado][1] }}</Chip>
        <Chip v-if="c.documento" :tono="estadoDoc[c.documento.estado][0]">{{ c.documento.nombre }} · {{ estadoDoc[c.documento.estado][1] }}</Chip>
    </div>

    <div v-if="errores.length && !aprobarAbierto && !rechazoAbierto && !convertirAbierto && !ordenAbierto" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">
        <p class="font-semibold">No se pudo completar la acción:</p>
        <ul class="mt-1 list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul>
    </div>
    <div v-if="vencida" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6] p-5 text-[#8A5A00]">Venció el {{ fecha(c.valida_hasta) }}. Para enviarla o convertirla, renueva la vigencia.</div>
    <div v-if="c.estado === 'por_aprobar'" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6] p-5 text-[#8A5A00]">
        <p class="font-semibold">Requiere aprobación de otra persona antes de enviarla:</p>
        <ul class="mt-1 list-disc pl-5"><li v-for="m in c.motivos" :key="m">{{ m }}</li></ul>
    </div>
    <div v-if="c.motivo_rechazo" class="tarjeta mb-6 p-5"><span class="font-semibold">Motivo del rechazo:</span> {{ c.motivo_rechazo }}</div>

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <div class="space-y-6">
            <section class="tarjeta grid gap-4 p-5 md:grid-cols-3">
                <div class="md:col-span-2">
                    <p class="etiqueta">Cliente</p>
                    <template v-if="c.cliente">
                        <p class="font-semibold">{{ c.cliente.razon_social }}</p>
                        <p class="num text-grafito">{{ rut(c.cliente.rut) }}</p>
                    </template>
                    <p v-else class="font-semibold">{{ c.contacto_nombre }} <span class="font-normal text-grafito">(sin registrar)</span></p>
                    <p class="text-[14px] text-grafito">{{ [c.cliente ? c.contacto_nombre : null, c.contacto_email, c.contacto_telefono].filter(Boolean).join(' · ') }}</p>
                </div>
                <div>
                    <p class="etiqueta">Vigencia</p>
                    <p class="num">{{ fecha(c.fecha) }} al {{ fecha(c.valida_hasta) }}</p>
                </div>
            </section>

            <section class="tarjeta overflow-x-auto">
                <table class="tabla">
                    <thead><tr><th>Descripción</th><th class="text-right">Cant.</th><th class="text-right">Precio neto</th><th class="text-right">Desc.</th><th class="text-right">Monto</th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in c.lineas" :key="i">
                            <td><div>{{ l.descripcion }}</div><div class="flex gap-2 text-[13px] text-grafito"><span v-if="l.codigo" class="num">{{ l.codigo }}</span><Chip v-if="l.exento" chico>Exento</Chip><span v-if="l.precio_lista && l.precio_lista !== l.precio_unitario">Lista {{ pesos(Number(l.precio_lista)) }}</span></div></td>
                            <td class="num text-right">{{ cifra(l.cantidad) }} {{ l.unidad }}</td>
                            <td class="num text-right">{{ pesos(Number(l.precio_unitario)) }}</td>
                            <td class="num text-right">{{ l.descuento_monto ? pesos(-l.descuento_monto) : '—' }}<div v-if="Number(l.descuento_pct)" class="text-[13px] text-grafito">{{ cifra(l.descuento_pct) }} %</div></td>
                            <td class="num text-right font-semibold">{{ pesos(l.monto_item) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <p v-if="c.observaciones" class="text-[15px] text-grafito"><span class="font-semibold text-carbon">Observaciones:</span> {{ c.observaciones }}</p>
        </div>

        <aside class="tarjeta self-start p-5">
            <dl class="space-y-2 text-[15px]">
                <div v-if="c.descuento_global_monto" class="flex justify-between"><dt class="text-grafito">Descuento global {{ cifra(c.descuento_global_pct) }} %</dt><dd class="num">{{ pesos(-c.descuento_global_monto) }}</dd></div>
                <div class="flex justify-between"><dt class="text-grafito">Neto</dt><dd class="num">{{ pesos(c.neto) }}</dd></div>
                <div v-if="c.exento" class="flex justify-between"><dt class="text-grafito">Exento</dt><dd class="num">{{ pesos(c.exento) }}</dd></div>
                <div class="flex justify-between"><dt class="text-grafito">IVA {{ cifra(c.tasa_iva) }} %</dt><dd class="num">{{ pesos(c.iva) }}</dd></div>
                <div class="flex items-baseline justify-between border-t border-linea pt-3"><dt class="font-semibold">Total</dt><dd class="num text-[28px] font-bold text-naranja-profundo">{{ pesos(c.total) }}</dd></div>
            </dl>
        </aside>
    </div>

    <Modal :abierto="aprobarAbierto" titulo="Aprobar cotización" @cerrar="aprobarAbierto = false">
        <ul class="mb-4 list-disc pl-5 text-[15px]"><li v-for="m in c.motivos" :key="m">{{ m }}</li></ul>
        <Campo etiqueta="Comentario (opcional)" id="comentario" :error="aprobacion.errors.comentario || (aprobacion.errors as any).cotizacion"><textarea id="comentario" v-model="aprobacion.comentario" rows="2" class="campo" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="aprobarAbierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="aprobacion.processing" @click="aprobacion.post(`/ventas/cotizaciones/${c.id}/aprobar`, { preserveScroll: true, onSuccess: () => (aprobarAbierto = false) })">Aprobar</button>
        </template>
    </Modal>

    <Modal :abierto="rechazoAbierto" titulo="Cotización rechazada" @cerrar="rechazoAbierto = false">
        <Campo etiqueta="¿Por qué no se concretó?" id="motivo" obligatorio :error="rechazo.errors.motivo"><input id="motivo" v-model="rechazo.motivo" class="campo h-11" maxlength="200" placeholder="Precio, plazo de entrega, compró en otro lado…" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="rechazoAbierto = false">Cancelar</button>
            <button class="btn btn-peligro" :disabled="rechazo.processing" @click="rechazo.post(`/ventas/cotizaciones/${c.id}/rechazar`, { preserveScroll: true, onSuccess: () => (rechazoAbierto = false) })">Cerrar como rechazada</button>
        </template>
    </Modal>

    <Modal :abierto="convertirAbierto" titulo="Convertir en venta" @cerrar="convertirAbierto = false">
        <p class="mb-4 text-[15px] text-grafito">Se crea un borrador con las mismas líneas, precios y descuentos. Si la cotización fue aprobada, la aprobación de descuentos se mantiene; un cupo de crédito excedido se vuelve a revisar.</p>
        <fieldset class="space-y-2">
            <legend class="etiqueta">Documento</legend>
            <label class="flex items-center gap-2"><input v-model.number="conversion.tipo_dte" type="radio" :value="33" /> Factura electrónica</label>
            <label class="flex items-center gap-2"><input v-model.number="conversion.tipo_dte" type="radio" :value="39" /> Boleta electrónica <span class="text-[14px] text-grafito">(precios pasan a IVA incluido)</span></label>
        </fieldset>
        <p v-if="(conversion.errors as any).cotizacion || conversion.errors.tipo_dte" class="error mt-3">{{ (conversion.errors as any).cotizacion || conversion.errors.tipo_dte }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="convertirAbierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="conversion.processing" @click="conversion.post(`/ventas/cotizaciones/${c.id}/convertir`)">Crear borrador</button>
        </template>
    </Modal>

    <Modal :abierto="ordenAbierto" titulo="Confirmar orden de venta" @cerrar="ordenAbierto = false">
        <p class="mb-4 text-[15px] text-grafito">Se verifica el stock disponible y, si es a crédito, el cupo del cliente. El stock queda reservado en el local hasta facturar.</p>
        <p v-if="!c.tiene_cliente" class="mb-4 rounded-boton bg-[#FFF3D6] p-3 text-[#8A5A00]">La cotización es para un contacto sin ficha: registra al cliente y edítala antes de confirmar.</p>
        <Campo etiqueta="Despachar desde" id="orden-local" obligatorio :error="orden.errors.local_id">
            <select id="orden-local" v-model="orden.local_id" class="campo h-11"><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
        </Campo>
        <p v-for="k in ['stock', 'forma_pago', 'cotizacion']" v-show="(orden.errors as any)[k]" :key="k" class="error mt-3 whitespace-pre-line">{{ (orden.errors as any)[k] }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="ordenAbierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="orden.processing || !c.tiene_cliente" @click="orden.post(`/ventas/cotizaciones/${c.id}/orden`)">Confirmar y reservar</button>
        </template>
    </Modal>
</template>
