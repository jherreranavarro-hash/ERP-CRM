<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import { fecha, fechaHora, pesos, rut } from '@/lib/formato';
import { estadoDespacho } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Evento { de: string | null; a: string; nota: string | null; usuario: string | null; fecha: string }
interface Desp {
    id: number; numero: number; estado: string; fecha: string; ventana: string | null; documento: { id: number; nombre: string; total: number } | null;
    cliente: string | null; rut: string | null; local: string | null; direccion: string; comuna: string; contacto: string | null; telefono: string | null;
    transportista: string; tracking: string | null; cobro: number; motivos: string[]; observaciones: string | null; intentos: number; motivo_fallo: string | null;
    recibido_nombre: string | null; recibido_rut: string | null; entregado_at: string | null; tiene_firma: boolean; creador: string | null; aprobador: string | null; propio: boolean;
    siguientes: string[]; eventos: Evento[];
}
const props = defineProps<{ despacho: Desp; estados: Record<string, string> }>();
const puede = usePermisos();
const page = usePage();
const d = computed(() => props.despacho);
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);
const modal = ref<'' | 'entregar' | 'fallar' | 'reprogramar' | 'anular'>('');
const accion = (ruta: string, msg?: string) => (!msg || confirm(msg)) && router.post(`/inventario/despachos/${d.value.id}/${ruta}`, {}, { preserveScroll: true });
const cerrar = () => (modal.value = '');

// ── Firma en pantalla (mouse, lápiz o dedo) ──
const lienzo = ref<HTMLCanvasElement | null>(null);
const firmado = ref(false);
let dibujando = false;
function abrirEntrega() {
    modal.value = 'entregar';
    firmado.value = false;
    nextTick(() => {
        const c = lienzo.value!;
        const r = c.getBoundingClientRect();
        c.width = r.width * 2; c.height = r.height * 2;
        const ctx = c.getContext('2d')!;
        ctx.scale(2, 2); ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1E1E1E';
    });
}
const punto = (e: PointerEvent) => { const r = lienzo.value!.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
function empezar(e: PointerEvent) { dibujando = true; lienzo.value!.setPointerCapture(e.pointerId); const ctx = lienzo.value!.getContext('2d')!; const [x, y] = punto(e); ctx.beginPath(); ctx.moveTo(x, y); }
function mover(e: PointerEvent) { if (!dibujando) return; const ctx = lienzo.value!.getContext('2d')!; const [x, y] = punto(e); ctx.lineTo(x, y); ctx.stroke(); firmado.value = true; }
const terminar = () => (dibujando = false);
function limpiar() { const c = lienzo.value!; c.getContext('2d')!.clearRect(0, 0, c.width, c.height); firmado.value = false; }

const entrega = useForm({ recibido_nombre: '', recibido_rut: '', firma: '' });
function entregar() {
    entrega.firma = firmado.value ? lienzo.value!.toDataURL('image/png') : '';
    entrega.post(`/inventario/despachos/${d.value.id}/entregar`, { preserveScroll: true, onSuccess: cerrar });
}
const fallo = useForm({ motivo: '' });
const reprog = useForm({ fecha_programada: new Date(Date.now() + 864e5).toLocaleDateString('sv-SE') });
const anulacion = useForm({ motivo: '' });
</script>

<template>
    <Head :title="`Despacho N° ${d.numero}`" />
    <Encabezado :titulo="`Despacho N° ${d.numero}`" volver="/inventario/despachos" :bajada="`${d.local ?? ''} · programado por ${d.creador ?? '—'}`">
        <template v-if="d.estado === 'por_aprobar'">
            <button v-if="puede('inventario.despachos.eliminar')" class="btn btn-secundario" @click="modal = 'anular'">Anular</button>
            <button v-if="puede('inventario.despachos.aprobar') && !d.propio" class="btn btn-primario" @click="accion('aprobar', '¿Aprobar el despacho gratis?')">Aprobar despacho gratis</button>
        </template>
        <template v-else-if="d.estado === 'programado' && puede('inventario.despachos.editar')">
            <button v-if="puede('inventario.despachos.eliminar')" class="btn btn-secundario" @click="modal = 'anular'">Anular</button>
            <button class="btn btn-primario" @click="accion('despachar')">Salir a ruta</button>
        </template>
        <template v-else-if="d.estado === 'en_ruta' && puede('inventario.despachos.editar')">
            <button class="btn btn-secundario" @click="modal = 'fallar'">No se pudo entregar</button>
            <button class="btn btn-primario" @click="abrirEntrega">Registrar entrega</button>
        </template>
        <template v-else-if="d.estado === 'fallido' && puede('inventario.despachos.editar')">
            <button v-if="puede('inventario.despachos.eliminar')" class="btn btn-secundario" @click="modal = 'anular'">Anular</button>
            <button class="btn btn-primario" @click="modal = 'reprogramar'">Reprogramar</button>
        </template>
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="estadoDespacho[d.estado][0]">{{ estadoDespacho[d.estado][1] }}</Chip>
        <Chip v-if="d.tracking" tono="info">Seguimiento {{ d.tracking }}</Chip>
        <Chip v-if="d.intentos" tono="alerta">{{ d.intentos }} reintento{{ d.intentos > 1 ? 's' : '' }}</Chip>
    </div>
    <div v-if="errores.length && !modal" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]"><ul class="list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul></div>
    <div v-if="d.estado === 'por_aprobar'" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6] p-5 text-[#8A5A00]">
        <p class="font-semibold">{{ d.propio ? 'Debe aprobarlo otra persona:' : 'Requiere aprobación:' }}</p>
        <ul class="mt-1 list-disc pl-5"><li v-for="m in d.motivos" :key="m">{{ m }}</li></ul>
    </div>
    <div v-if="d.estado === 'fallido'" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]"><span class="font-semibold">No se entregó:</span> {{ d.motivo_fallo }}</div>

    <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
        <div class="space-y-6">
            <section class="tarjeta grid gap-4 p-5 md:grid-cols-2">
                <div><p class="etiqueta">Destino</p><p class="font-semibold">{{ d.direccion }}</p><p class="text-grafito">{{ d.comuna }}</p>
                    <p v-if="d.contacto || d.telefono" class="mt-1 text-[14px] text-grafito">{{ [d.contacto, d.telefono].filter(Boolean).join(' · ') }}</p></div>
                <div><p class="etiqueta">Cliente</p><p class="font-semibold">{{ d.cliente ?? 'Consumidor final' }}</p><p v-if="d.rut" class="num text-grafito">{{ rut(d.rut) }}</p></div>
                <div><p class="etiqueta">Fecha</p><p class="num">{{ fecha(d.fecha) }} <span v-if="d.ventana" class="uppercase text-grafito">· {{ d.ventana }}</span></p></div>
                <div><p class="etiqueta">Transportista</p><p>{{ d.transportista }}</p></div>
                <div><p class="etiqueta">Documento</p><Link v-if="d.documento" :href="`/caja/documentos/${d.documento.id}`">{{ d.documento.nombre }}</Link> <span v-if="d.documento" class="num text-grafito">· {{ pesos(d.documento.total) }}</span></div>
                <div><p class="etiqueta">Cargo de despacho</p><p class="num">{{ d.cobro ? pesos(d.cobro) : 'Gratis' }}<span v-if="d.aprobador" class="text-[14px] text-grafito"> · aprobó {{ d.aprobador }}</span></p></div>
                <p v-if="d.observaciones" class="text-[15px] text-grafito md:col-span-2"><span class="font-semibold text-carbon">Observaciones:</span> {{ d.observaciones }}</p>
            </section>
            <section v-if="d.estado === 'entregado'" class="tarjeta p-5">
                <p class="etiqueta">Recepción</p>
                <p class="font-semibold">{{ d.recibido_nombre }} <span v-if="d.recibido_rut" class="num font-normal text-grafito">· {{ rut(d.recibido_rut) }}</span></p>
                <p class="num text-grafito">{{ fechaHora(d.entregado_at) }}</p>
                <img v-if="d.tiene_firma" :src="`/inventario/despachos/${d.id}/firma`" alt="Firma de quien recibió" class="mt-3 h-32 rounded-boton border border-linea bg-white object-contain" />
            </section>
        </div>
        <aside class="tarjeta self-start p-5">
            <h2 class="mb-3 font-semibold">Seguimiento</h2>
            <ol class="space-y-3 border-l-2 border-linea pl-4">
                <li v-for="(e, i) in d.eventos" :key="i" class="relative">
                    <span class="absolute -left-[23px] top-1.5 h-3 w-3 rounded-full" :class="i === d.eventos.length - 1 ? 'bg-naranja' : 'bg-linea'" aria-hidden="true" />
                    <p class="font-semibold">{{ estados[e.a] }}</p>
                    <p class="text-[13px] text-grafito">{{ fechaHora(e.fecha) }} · {{ e.usuario }}</p>
                    <p v-if="e.nota" class="text-[14px]">{{ e.nota }}</p>
                </li>
            </ol>
        </aside>
    </div>

    <Modal :abierto="modal === 'entregar'" titulo="Registrar entrega" @cerrar="cerrar">
        <div class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <Campo etiqueta="Recibe" id="rec-nombre" obligatorio :error="entrega.errors.recibido_nombre"><input id="rec-nombre" v-model="entrega.recibido_nombre" class="campo h-11" maxlength="100" /></Campo>
                <Campo etiqueta="RUT (opcional)" id="rec-rut" :error="entrega.errors.recibido_rut"><input id="rec-rut" v-model="entrega.recibido_rut" class="campo num h-11" maxlength="12" /></Campo>
            </div>
            <div>
                <div class="flex items-center justify-between"><span id="firma-etiqueta" class="etiqueta">Firma de quien recibe<span class="text-naranja-profundo"> *</span></span><button type="button" class="text-[14px] underline" @click="limpiar">Limpiar</button></div>
                <canvas ref="lienzo" aria-labelledby="firma-etiqueta" role="img" class="h-40 w-full touch-none rounded-boton border-2 border-dashed border-linea bg-white" @pointerdown="empezar" @pointermove="mover" @pointerup="terminar" @pointerleave="terminar" />
                <p v-if="entrega.errors.firma" class="error" role="alert">{{ entrega.errors.firma }}</p>
            </div>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="cerrar">Cancelar</button>
            <button class="btn btn-primario" :disabled="entrega.processing" @click="entregar">Confirmar entrega</button>
        </template>
    </Modal>

    <Modal :abierto="modal === 'fallar'" titulo="Entrega fallida" @cerrar="cerrar">
        <Campo etiqueta="¿Qué pasó?" id="fallo" obligatorio :error="fallo.errors.motivo"><input id="fallo" v-model="fallo.motivo" class="campo h-11" maxlength="200" placeholder="Cliente ausente, dirección errónea…" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="cerrar">Cancelar</button>
            <button class="btn btn-peligro" :disabled="fallo.processing" @click="fallo.post(`/inventario/despachos/${d.id}/fallar`, { preserveScroll: true, onSuccess: cerrar })">Registrar</button>
        </template>
    </Modal>

    <Modal :abierto="modal === 'reprogramar'" titulo="Reprogramar despacho" @cerrar="cerrar">
        <Campo etiqueta="Nueva fecha" id="nueva" obligatorio :error="reprog.errors.fecha_programada"><input id="nueva" v-model="reprog.fecha_programada" type="date" class="campo h-11" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="cerrar">Cancelar</button>
            <button class="btn btn-primario" :disabled="reprog.processing" @click="reprog.post(`/inventario/despachos/${d.id}/reprogramar`, { preserveScroll: true, onSuccess: cerrar })">Reprogramar</button>
        </template>
    </Modal>

    <Modal :abierto="modal === 'anular'" titulo="Anular despacho" @cerrar="cerrar">
        <Campo etiqueta="Motivo" id="anular" obligatorio :error="anulacion.errors.motivo"><input id="anular" v-model="anulacion.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="cerrar">Cancelar</button>
            <button class="btn btn-peligro" :disabled="anulacion.processing" @click="anulacion.post(`/inventario/despachos/${d.id}/anular`, { preserveScroll: true, onSuccess: cerrar })">Anular despacho</button>
        </template>
    </Modal>
</template>
