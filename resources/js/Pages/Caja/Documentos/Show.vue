<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos, rut } from '@/lib/formato';
import { estadoDoc, estadoSii } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Linea { descripcion: string; codigo: string | null; cantidad: string; unidad: string; precio_unitario: string; precio_lista: string | null; descuento_pct: string; descuento_monto: number; monto_item: number; exento: boolean }
interface Doc {
    id: number; nombre: string; tipo_largo: string; tipo_dte: number; folio: number | null; fecha: string; vencimiento: string | null; estado: string; estado_sii: string | null; forma_pago: string;
    cliente: { rut: string; razon_social: string; giro: string | null; direccion: string | null; comuna: string | null } | null;
    receptor: { rut: string; razon_social: string; giro: string | null; direccion: string | null; comuna: string | null } | null;
    descuento_global_pct: string; descuento_global_monto: number; neto: number; exento: number; iva: number; total: number; saldo: number; tasa_iva: string; iva_incluido: boolean;
    observaciones: string | null; motivos: string[]; aprobado: boolean; propio: boolean; creador: string | null; local: string | null; acreditable: number;
    sii: { track_id: string | null; intentos: number; pdf: boolean };
    lineas: Linea[]; referencias: { documento_referido_id: number; nombre: string; codigo: string | null; razon: string }[];
    pagos: { id: number; monto: number; metodo: string; fecha: string; usuario: string | null; referencia: string | null }[];
    notas: { id: number; nombre: string; total: number; estado: string; codigo: string | null }[];
}
const props = defineProps<{ documento: Doc; medios: Record<string, string>; codigos: Record<string, string> }>();
const puede = usePermisos();
const page = usePage();
// Errores de emisión, aprobación o cobro que no pertenecen a un modal abierto.
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);
const d = computed(() => props.documento);
const receptor = computed(() => d.value.receptor ?? d.value.cliente);
const borrador = computed(() => ['borrador', 'por_aprobar'].includes(d.value.estado));
const esNota = computed(() => [56, 61].includes(d.value.tipo_dte));

const enviando = ref(false);
function emitir() {
    if (!confirm(`¿Emitir ${d.value.tipo_largo} por ${pesos(d.value.total)}? Se asigna folio y queda inmutable.`)) return;
    router.post(`/caja/documentos/${d.value.id}/emitir`, {}, { preserveScroll: true, onStart: () => (enviando.value = true), onFinish: () => (enviando.value = false) });
}
const aprobacion = useForm({ comentario: '' });
const aprobarAbierto = ref(false);

const pago = useForm({ monto: 0, metodo: Object.keys(props.medios)[0] ?? 'efectivo', referencia: '' });
const pagoAbierto = ref(false);
function abrirPago() {
    pago.monto = d.value.saldo;
    pagoAbierto.value = true;
}

const nota = useForm({ tipo_dte: 61, codigo: 3, razon: '' });
const notaAbierta = ref(false);
function abrirNota(tipo: number, codigo: number) {
    Object.assign(nota, { tipo_dte: tipo, codigo, razon: codigo === 1 ? 'Anula documento' : '' });
    nota.clearErrors();
    notaAbierta.value = true;
}
function eliminar() {
    if (confirm('¿Eliminar este borrador?')) router.delete(`/caja/documentos/${d.value.id}`);
}

const accionesSecundarias = computed(() => {
    const a: { etiqueta: string; peligro?: boolean; accion: () => void }[] = [];
    if (!borrador.value && !esNota.value && d.value.estado !== 'anulado' && puede('caja.facturas-de-ventas.anular')) {
        if (d.value.acreditable === d.value.total) a.push({ etiqueta: 'Anular con nota de crédito', peligro: true, accion: () => abrirNota(61, 1) });
        if (d.value.acreditable > 0) a.push({ etiqueta: 'Nota de crédito por montos', accion: () => abrirNota(61, 3) });
        a.push({ etiqueta: 'Nota de crédito por texto', accion: () => abrirNota(61, 2) });
        a.push({ etiqueta: 'Nota de débito', accion: () => abrirNota(56, 3) });
    }
    if (borrador.value && puede('caja.facturas-de-ventas.eliminar')) a.push({ etiqueta: 'Eliminar borrador', peligro: true, accion: eliminar });
    return a;
});
const cifra = (v: string) => Number(v).toLocaleString('es-CL', { maximumFractionDigits: 6 });
</script>

<template>
    <Head :title="d.nombre" />
    <Encabezado :titulo="d.nombre" volver="/caja/documentos" :bajada="`${d.tipo_largo} · ${d.local ?? ''} · creado por ${d.creador ?? '—'}`">
        <a v-if="d.sii.pdf" :href="`/caja/documentos/${d.id}/pdf`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> PDF</a>
        <MenuAcciones v-if="accionesSecundarias.length" :acciones="accionesSecundarias" etiqueta="Más acciones" />
        <Link v-if="borrador && puede('caja.facturas-de-ventas.editar')" :href="`/caja/documentos/${d.id}/editar`" class="btn btn-secundario no-underline">Editar</Link>
        <!-- Una sola acción principal según el estado -->
        <button v-if="d.estado === 'por_aprobar' && puede('caja.facturas-de-ventas.aprobar') && !d.propio" class="btn btn-primario" @click="aprobarAbierto = true">Aprobar</button>
        <button v-else-if="d.estado === 'borrador' && puede('caja.facturas-de-ventas.emitir')" class="btn btn-primario" :disabled="enviando" @click="emitir">Emitir</button>
        <button v-else-if="d.estado === 'emitido' && d.saldo > 0 && !esNota && puede('caja.caja.crear')" class="btn btn-primario" @click="abrirPago">Registrar pago</button>
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="estadoDoc[d.estado][0]">{{ estadoDoc[d.estado][1] }}</Chip>
        <Chip v-if="d.estado_sii" :tono="estadoSii[d.estado_sii][0]">{{ estadoSii[d.estado_sii][1] }}</Chip>
        <Chip v-if="d.forma_pago === 'credito'" tono="info">Crédito{{ d.vencimiento ? ` · vence ${fecha(d.vencimiento)}` : '' }}</Chip>
        <Chip v-if="d.aprobado" tono="ok">Aprobado</Chip>
    </div>

    <div v-if="errores.length && !pagoAbierto && !notaAbierta && !aprobarAbierto" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">
        <p class="font-semibold">No se pudo completar la acción:</p>
        <ul class="mt-1 list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul>
    </div>
    <div v-if="d.estado === 'por_aprobar'" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6] p-5 text-[#8A5A00]">
        <p class="font-semibold">Requiere aprobación de otra persona antes de emitir:</p>
        <ul class="mt-1 list-disc pl-5"><li v-for="m in d.motivos" :key="m">{{ m }}</li></ul>
        <p v-if="d.propio" class="mt-2 text-[14px]">Tú creaste este documento: debe aprobarlo un jefe.</p>
    </div>
    <div v-if="d.estado_sii === 'error'" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">
        El documento está emitido, pero el proveedor DTE no respondió ({{ d.sii.intentos }} intentos). Se reintenta automáticamente.
    </div>

    <div class="grid gap-6 xl:grid-cols-[1fr_340px]">
        <div class="space-y-6">
            <section class="tarjeta grid gap-4 p-5 md:grid-cols-2">
                <div>
                    <p class="etiqueta">Receptor</p>
                    <template v-if="receptor">
                        <p class="font-semibold">{{ receptor.razon_social }}</p>
                        <p class="num text-grafito">{{ rut(receptor.rut) }}</p>
                        <p class="text-[14px] text-grafito">{{ [receptor.giro, receptor.direccion, receptor.comuna].filter(Boolean).join(' · ') }}</p>
                    </template>
                    <p v-else class="text-grafito">Consumidor final</p>
                </div>
                <div>
                    <p class="etiqueta">Emisión</p>
                    <p class="num">{{ fecha(d.fecha) }}</p>
                    <p v-if="d.sii.track_id" class="num text-[13px] text-grafito">Track SII: {{ d.sii.track_id }}</p>
                </div>
                <div v-if="d.referencias.length" class="md:col-span-2">
                    <p class="etiqueta">Referencia</p>
                    <p v-for="r in d.referencias" :key="r.documento_referido_id"><Link :href="`/caja/documentos/${r.documento_referido_id}`" class="font-semibold">{{ r.nombre }}</Link> · {{ r.codigo }} · {{ r.razon }}</p>
                </div>
            </section>

            <section class="tarjeta overflow-x-auto">
                <table class="tabla">
                    <thead><tr><th>Descripción</th><th class="text-right">Cant.</th><th class="text-right">Precio unit.</th><th class="text-right">Desc.</th><th class="text-right">Monto</th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in d.lineas" :key="i">
                            <td><div>{{ l.descripcion }}</div><div class="flex gap-2 text-[13px] text-grafito"><span v-if="l.codigo" class="num">{{ l.codigo }}</span><Chip v-if="l.exento" chico>Exento</Chip><span v-if="l.precio_lista && l.precio_lista !== l.precio_unitario">Lista {{ pesos(Number(l.precio_lista)) }}</span></div></td>
                            <td class="num text-right">{{ cifra(l.cantidad) }} {{ l.unidad }}</td>
                            <td class="num text-right">{{ pesos(Number(l.precio_unitario)) }}</td>
                            <td class="num text-right">{{ l.descuento_monto ? pesos(-l.descuento_monto) : '—' }}<div v-if="Number(l.descuento_pct)" class="text-[13px] text-grafito">{{ cifra(l.descuento_pct) }} %</div></td>
                            <td class="num text-right font-semibold">{{ pesos(l.monto_item) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section v-if="d.pagos.length" class="tarjeta overflow-x-auto">
                <h2 class="titulo-ancho px-5 pt-5 text-[16px] font-bold">Pagos</h2>
                <table class="tabla">
                    <thead><tr><th>Fecha</th><th>Medio</th><th>Registró</th><th class="text-right">Monto</th></tr></thead>
                    <tbody><tr v-for="p in d.pagos" :key="p.id"><td class="num">{{ fecha(p.fecha) }}</td><td>{{ p.metodo }}<span v-if="p.referencia" class="text-grafito"> · {{ p.referencia }}</span></td><td>{{ p.usuario }}</td><td class="num text-right">{{ pesos(p.monto) }}</td></tr></tbody>
                </table>
            </section>

            <section v-if="d.notas.length" class="tarjeta overflow-x-auto">
                <h2 class="titulo-ancho px-5 pt-5 text-[16px] font-bold">Notas asociadas</h2>
                <table class="tabla">
                    <tbody><tr v-for="n in d.notas" :key="n.id"><td><Link :href="`/caja/documentos/${n.id}`" class="font-semibold">{{ n.nombre }}</Link></td><td>{{ n.codigo }}</td><td><Chip chico :tono="estadoDoc[n.estado][0]">{{ estadoDoc[n.estado][1] }}</Chip></td><td class="num text-right">{{ pesos(n.total) }}</td></tr></tbody>
                </table>
            </section>

            <p v-if="d.observaciones" class="text-[15px] text-grafito"><span class="font-semibold text-carbon">Observaciones:</span> {{ d.observaciones }}</p>
        </div>

        <aside class="tarjeta self-start p-5">
            <dl class="space-y-2 text-[15px]">
                <div v-if="d.descuento_global_monto" class="flex justify-between"><dt class="text-grafito">Descuento global {{ cifra(d.descuento_global_pct) }} %</dt><dd class="num">{{ pesos(-d.descuento_global_monto) }}</dd></div>
                <div class="flex justify-between"><dt class="text-grafito">Neto</dt><dd class="num">{{ pesos(d.neto) }}</dd></div>
                <div v-if="d.exento" class="flex justify-between"><dt class="text-grafito">Exento</dt><dd class="num">{{ pesos(d.exento) }}</dd></div>
                <div class="flex justify-between"><dt class="text-grafito">IVA {{ cifra(d.tasa_iva) }} %</dt><dd class="num">{{ pesos(d.iva) }}</dd></div>
                <div class="flex items-baseline justify-between border-t border-linea pt-3"><dt class="font-semibold">Total</dt><dd class="num text-[28px] font-bold">{{ pesos(d.total) }}</dd></div>
                <div v-if="!borrador && !esNota" class="flex items-baseline justify-between"><dt class="font-semibold">Saldo</dt><dd class="num text-[20px] font-bold" :class="d.saldo ? 'text-naranja-profundo' : 'text-grafito'">{{ pesos(d.saldo) }}</dd></div>
            </dl>
        </aside>
    </div>

    <Modal :abierto="aprobarAbierto" titulo="Aprobar documento" @cerrar="aprobarAbierto = false">
        <ul class="mb-4 list-disc pl-5 text-[15px]"><li v-for="m in d.motivos" :key="m">{{ m }}</li></ul>
        <Campo etiqueta="Comentario (opcional)" id="comentario" :error="aprobacion.errors.comentario || (aprobacion.errors as any).documento"><textarea id="comentario" v-model="aprobacion.comentario" rows="2" class="campo" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="aprobarAbierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="aprobacion.processing" @click="aprobacion.post(`/caja/documentos/${d.id}/aprobar`, { preserveScroll: true, onSuccess: () => (aprobarAbierto = false) })">Aprobar</button>
        </template>
    </Modal>

    <Modal :abierto="pagoAbierto" titulo="Registrar pago" @cerrar="pagoAbierto = false">
        <form id="form-pago" class="grid gap-5 sm:grid-cols-2" @submit.prevent="pago.post(`/caja/documentos/${d.id}/pagos`, { preserveScroll: true, onSuccess: () => (pagoAbierto = false) })">
            <Campo etiqueta="Monto" id="monto" :error="pago.errors.monto" :ayuda="`Saldo ${pesos(d.saldo)}`"><input id="monto" v-model.number="pago.monto" type="number" min="1" :max="d.saldo" step="1" class="campo num h-11" /></Campo>
            <Campo etiqueta="Medio" id="metodo" :error="pago.errors.metodo"><select id="metodo" v-model="pago.metodo" class="campo h-11"><option v-for="(n, k) in medios" :key="k" :value="k">{{ n }}</option></select></Campo>
            <Campo class="sm:col-span-2" etiqueta="Referencia" id="ref" :error="pago.errors.referencia" ayuda="N° de transacción o comprobante (opcional)."><input id="ref" v-model="pago.referencia" class="campo h-11" maxlength="60" /></Campo>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="pagoAbierto = false">Cancelar</button>
            <button type="submit" form="form-pago" class="btn btn-primario" :disabled="pago.processing">Registrar</button>
        </template>
    </Modal>

    <Modal :abierto="notaAbierta" :titulo="nota.tipo_dte === 61 ? 'Nota de crédito' : 'Nota de débito'" @cerrar="notaAbierta = false">
        <form id="form-nota" class="space-y-4" @submit.prevent="nota.post(`/caja/documentos/${d.id}/nota`, { onSuccess: () => (notaAbierta = false) })">
            <p class="text-[15px] text-grafito">
                <template v-if="nota.codigo === 1">Anula {{ d.nombre }} por el total ({{ pesos(d.total) }}). Se prepara la nota en borrador para revisar y emitir.</template>
                <template v-else-if="nota.codigo === 2">Corrige solo texto: la nota va por $0.</template>
                <template v-else>Se copian las líneas para que ajustes cantidades o precios. {{ nota.tipo_dte === 61 ? `Máximo acreditable: ${pesos(d.acreditable)}.` : '' }}</template>
            </p>
            <Campo etiqueta="Razón" id="razon" :error="nota.errors.razon || (nota.errors as any).codigo || (nota.errors as any).documento" obligatorio ayuda="Va en el documento (máx. 90 caracteres)."><input id="razon" v-model="nota.razon" class="campo h-11" maxlength="90" /></Campo>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="notaAbierta = false">Cancelar</button>
            <button type="submit" form="form-nota" class="btn btn-primario" :disabled="nota.processing">Preparar nota</button>
        </template>
    </Modal>
</template>
