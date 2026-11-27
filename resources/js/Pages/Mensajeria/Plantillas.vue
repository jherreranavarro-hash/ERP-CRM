<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Vacio from '@/Components/Vacio.vue';
import { fechaHora } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Plantilla { id: number; nombre: string; idioma: string; categoria: string; cuerpo: string; estado: string; motivo_rechazo: string | null; variables: number; usos: number; revisada_at: string | null }
const props = defineProps<{ activa: boolean; plantillas: Plantilla[] }>();
const puede = usePermisos();
const editar = computed(() => puede('ventascrm.whatsapp.editar'));
const ESTADO: Record<string, [string, 'neutro' | 'info' | 'ok' | 'error']> = { borrador: ['Borrador', 'neutro'], pendiente: ['En revisión de Meta', 'info'], aprobada: ['Aprobada', 'ok'], rechazada: ['Rechazada', 'error'] };
const hayPendientes = computed(() => props.plantillas.some((p) => p.estado === 'pendiente'));

const abierto = ref(false);
const editando = ref<Plantilla | null>(null);
const form = useForm({ nombre: '', idioma: 'es', categoria: 'utility', cuerpo: '' });
function nueva() {
    editando.value = null;
    form.reset();
    form.clearErrors();
    abierto.value = true;
}
function abrir(p: Plantilla) {
    editando.value = p;
    form.clearErrors();
    Object.assign(form, { nombre: p.nombre, idioma: p.idioma, categoria: p.categoria, cuerpo: p.cuerpo });
    abierto.value = true;
}
function guardar() {
    const op = { preserveScroll: true, onSuccess: () => (abierto.value = false) };
    editando.value ? form.put(`/ventas/whatsapp-plantillas/${editando.value.id}`, op) : form.post('/ventas/whatsapp-plantillas', op);
}
const revision = (p: Plantilla) => router.post(`/ventas/whatsapp-plantillas/${p.id}/revision`, {}, { preserveScroll: true });
const eliminar = (p: Plantilla) => router.delete(`/ventas/whatsapp-plantillas/${p.id}`, { preserveScroll: true });
const actualizar = () => router.post('/ventas/whatsapp-plantillas/actualizar', {}, { preserveScroll: true });
const vista = computed(() => form.cuerpo.replace(/\{\{(\d+)\}\}/g, (_, n) => `[variable ${n}]`));
const insertar = () => {
    const n = (form.cuerpo.match(/\{\{(\d+)\}\}/g) ?? []).length + 1;
    form.cuerpo = `${form.cuerpo}{{${n}}}`;
};
</script>

<template>
    <Head title="Plantillas de WhatsApp" />
    <Encabezado titulo="Plantillas de WhatsApp" volver="/ventas/whatsapp" bajada="Fuera de la ventana de 24 horas solo se puede escribir con una plantilla aprobada por Meta. Las de categoría utility no pueden ser promocionales.">
        <button v-if="editar && hayPendientes" class="btn btn-secundario" @click="actualizar">Consultar revisión</button>
        <button v-if="editar" class="btn btn-primario" @click="nueva">Nueva plantilla</button>
    </Encabezado>
    <p v-if="!activa" class="tarjeta mb-4 p-4 text-[14px]">La integración de WhatsApp no está activa: puedes preparar borradores, pero no enviarlos a revisión. <Link href="/configuracion/integraciones">Activarla</Link>.</p>

    <div v-if="plantillas.length" class="grid gap-4 lg:grid-cols-2">
        <article v-for="p in plantillas" :key="p.id" class="tarjeta flex flex-col p-5">
            <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="num text-[16px] font-bold">{{ p.nombre }}</h2>
                    <p class="text-[13px] text-grafito">{{ p.categoria === 'marketing' ? 'Marketing' : 'Utility' }} · {{ p.idioma }} · {{ p.variables }} variables · usada {{ p.usos }} {{ p.usos === 1 ? 'vez' : 'veces' }}</p>
                </div>
                <Chip chico :tono="ESTADO[p.estado][1]">{{ ESTADO[p.estado][0] }}</Chip>
            </div>
            <p class="flex-1 whitespace-pre-line rounded-boton bg-hormigon p-3 text-[14px]">{{ p.cuerpo }}</p>
            <p v-if="p.estado === 'rechazada' && p.motivo_rechazo" class="mt-2 rounded-boton bg-estado-error-bg p-2 text-[13px] text-estado-error">Meta: {{ p.motivo_rechazo }}</p>
            <p v-else-if="p.revisada_at" class="mt-2 text-[12px] text-grafito">Revisada el {{ fechaHora(p.revisada_at) }}</p>
            <div v-if="editar" class="mt-3 flex flex-wrap gap-2">
                <button v-if="p.estado === 'borrador'" class="btn btn-primario btn-sm" :disabled="!activa" @click="revision(p)">Enviar a revisión</button>
                <button v-if="['borrador', 'rechazada'].includes(p.estado)" class="btn btn-secundario btn-sm" @click="abrir(p)">{{ p.estado === 'rechazada' ? 'Corregir' : 'Editar' }}</button>
                <MenuAcciones v-if="!p.usos" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(p) }]" />
            </div>
        </article>
    </div>
    <div v-else class="tarjeta"><Vacio titulo="Sin plantillas" texto="Crea la primera, por ejemplo una confirmación de despacho." /></div>

    <Modal :abierto="abierto" :titulo="editando ? `Editar ${editando.nombre}` : 'Nueva plantilla'" ancho="lg" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-3">
            <Campo etiqueta="Nombre" id="pl-nombre" obligatorio :error="form.errors.nombre" ayuda="minúsculas_y_guion_bajo" class="sm:col-span-3"><input id="pl-nombre" v-model="form.nombre" class="campo num h-11" maxlength="60" /></Campo>
            <Campo etiqueta="Categoría" id="pl-cat" obligatorio :error="form.errors.categoria"><select id="pl-cat" v-model="form.categoria" class="campo h-11"><option value="utility">Utility (transaccional)</option><option value="marketing">Marketing</option></select></Campo>
            <Campo etiqueta="Idioma" id="pl-idi" obligatorio :error="form.errors.idioma"><select id="pl-idi" v-model="form.idioma" class="campo h-11"><option value="es">Español</option><option value="es_CL">Español (Chile)</option><option value="en">Inglés</option></select></Campo>
            <div class="flex items-end"><button class="btn btn-secundario w-full" type="button" @click="insertar">Insertar variable</button></div>
            <Campo etiqueta="Texto" id="pl-cuerpo" obligatorio :error="form.errors.cuerpo ?? (form.errors as Record<string, string>).plantilla" ayuda="Variables como {{1}}, {{2}}… consecutivas. Máximo 1.024 caracteres." class="sm:col-span-3">
                <textarea id="pl-cuerpo" v-model="form.cuerpo" rows="5" maxlength="1024" class="campo"></textarea>
            </Campo>
        </div>
        <div v-if="form.cuerpo" class="mt-4">
            <div class="mb-1 text-[14px] font-semibold">Vista previa</div>
            <div class="rounded-tarjeta bg-hormigon p-4"><p class="max-w-[85%] whitespace-pre-line rounded-tarjeta bg-white px-3 py-2 shadow-sm">{{ vista }}</p></div>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar borrador</button>
        </template>
    </Modal>
</template>
