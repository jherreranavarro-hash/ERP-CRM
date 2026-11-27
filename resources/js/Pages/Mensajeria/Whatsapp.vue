<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import type { PageProps } from '@/types';
import { fechaHora, haceCuanto } from '@/lib/formato';
import { estadoLead } from '@/lib/documentos';
import { http } from '@/lib/http';
import { conectar, type Conexion } from '@/lib/tiempoReal';

interface Fila { id: number; nombre: string | null; telefono: string; estado: string; no_leidos: number; ultimo: string | null; asignado: string | null; en_ventana: boolean }
interface Mensaje { id: number; direccion: 'entrante' | 'saliente'; tipo: string; cuerpo: string; estado: string; error: string | null; autor: string | null; fecha: string }
interface Actual { id: number; nombre: string | null; telefono: string; estado: string; en_ventana: boolean; ventana_hasta: string | null; asignado_a: number | null; asignado: string | null;
    cliente: { id: number; nombre: string } | null; lead: { id: number; nombre: string; estado: string; campana_id: number } | null; mensajes: Mensaje[] }
interface Plantilla { id: number; nombre: string; idioma: string; cuerpo: string; variables: number }
const props = defineProps<{
    conversaciones: Fila[]; actual: Actual | null; filtros: { filtro: string; buscar: string }; plantillas: Plantilla[];
    activa: boolean; iaActiva: boolean; simulado: boolean; puedeEscribir: boolean; puedeTodas: boolean; canal: string; conexion: Conexion | null;
}>();
const yo = usePage<PageProps>().props.auth.user?.id;
const FILTROS: Record<string, string> = { abiertas: 'Abiertas', no_leidas: 'Sin leer', mias: 'Mías', todas: 'Todas' };
const buscar = ref(props.filtros.buscar);
const ir = (q: Record<string, unknown>) => router.get('/ventas/whatsapp', { filtro: props.filtros.filtro, ...(buscar.value ? { buscar: buscar.value } : {}), ...(props.actual ? { c: props.actual.id } : {}), ...q },
    { preserveState: true, preserveScroll: true, replace: true });
const abrir = (id: number) => ir({ c: id });
const formatoTel = (t: string) => (t.startsWith('56') ? `+56 ${t.slice(2, 3)} ${t.slice(3, 7)} ${t.slice(7)}` : `+${t}`);
const ESTADO_MSJ: Record<string, string> = { en_cola: 'En cola', enviado: 'Enviado', entregado: 'Entregado', leido: 'Leído', fallido: 'No enviado', recibido: '' };

// Mensajes: bajar al último al abrir o recibir.
const hilo = ref<HTMLElement | null>(null);
const alFinal = () => nextTick(() => hilo.value?.scrollTo({ top: hilo.value.scrollHeight }));
watch(() => props.actual?.mensajes.length, alFinal);
onMounted(alFinal);

const ft = useForm({ texto: '' });
const enviar = () => ft.post(`/ventas/whatsapp/${props.actual!.id}/texto`, { preserveScroll: true, onSuccess: () => { ft.reset(); resumen.value = ''; } });
const teclado = (e: KeyboardEvent) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); if (ft.texto.trim()) enviar(); } };

const usandoPlantilla = ref(false);
const fp = useForm({ plantilla_id: null as number | null, variables: [] as string[] });
const plantilla = computed(() => props.plantillas.find((p) => p.id === fp.plantilla_id) ?? null);
watch(() => fp.plantilla_id, () => { fp.variables = Array.from({ length: plantilla.value?.variables ?? 0 }, (_, i) => (i === 0 ? props.actual?.nombre ?? '' : '')); });
const vista = computed(() => plantilla.value?.cuerpo.replace(/\{\{(\d+)\}\}/g, (m, n) => fp.variables[Number(n) - 1] || m) ?? '');
const enviarPlantilla = () => fp.post(`/ventas/whatsapp/${props.actual!.id}/plantilla`, { preserveScroll: true, onSuccess: () => { usandoPlantilla.value = false; fp.reset(); } });

const estado = (d: Record<string, string | number | null | undefined>) => router.post(`/ventas/whatsapp/${props.actual!.id}/estado`, d, { preserveScroll: true });

// Asistente de IA: el servidor oculta los datos personales antes de consultar al proveedor.
const iaCargando = ref<string | null>(null);
const iaError = ref('');
const resumen = ref('');
async function ia(accion: 'sugerir' | 'resumir') {
    iaCargando.value = accion;
    iaError.value = '';
    try {
        const { data } = await http.post<{ texto: string }>(`/ventas/whatsapp/${props.actual!.id}/ia/${accion}`);
        if (accion === 'sugerir') ft.texto = data.texto; else resumen.value = data.texto;
    } catch (e) {
        const r = (e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data;
        iaError.value = r?.errors ? Object.values(r.errors)[0][0] : r?.message ?? 'El asistente no respondió.';
    } finally {
        iaCargando.value = null;
    }
}
watch(() => props.actual?.id, () => { resumen.value = ''; iaError.value = ''; ft.reset(); ft.clearErrors(); });

const nueva = ref(false);
const fn = useForm({ telefono: '', nombre: '' });
const iniciar = () => fn.post('/ventas/whatsapp/iniciar', { onSuccess: () => { nueva.value = false; fn.reset(); } });

// Avisos en vivo: solo dicen qué conversación cambió; los datos se vuelven a pedir con permisos.
let echo: ReturnType<typeof conectar> | null = null;
let espera: ReturnType<typeof setTimeout> | undefined;
onMounted(() => {
    if (!props.conexion) return;
    try {
        echo = conectar(props.conexion);
        echo.private(props.canal).listen('.actualizado', () => { clearTimeout(espera); espera = setTimeout(() => router.reload({ only: ['conversaciones', 'actual'] }), 400); });
    } catch { /* sin tiempo real: la bandeja se actualiza al navegar */ }
});
onBeforeUnmount(() => { clearTimeout(espera); echo?.leave(props.canal); echo?.disconnect(); });
</script>

<template>
    <Head title="WhatsApp" />
    <Encabezado titulo="WhatsApp" bajada="Conversaciones con clientes y prospectos. Fuera de las 24 horas desde el último mensaje del cliente, WhatsApp solo permite plantillas aprobadas.">
        <button v-if="puedeEscribir" class="btn btn-primario" :disabled="!activa" @click="nueva = true"><Icono nombre="mas" :tamano="18" /> Nueva conversación</button>
    </Encabezado>
    <p v-if="!activa" class="tarjeta mb-4 p-4 text-[14px]">La integración de WhatsApp no está activa. Actívala en <Link href="/configuracion/integraciones">Configuración → Integraciones</Link> para enviar mensajes.</p>
    <p v-else-if="simulado" class="mb-4 text-[13px] text-grafito">Envío simulado: los mensajes no salen a WhatsApp hasta conectar la cuenta de Meta.</p>

    <div class="grid gap-4 lg:grid-cols-[340px_1fr]">
        <section class="tarjeta flex min-h-[420px] flex-col" :class="{ 'hidden lg:flex': actual }">
            <div class="flex flex-wrap gap-2 border-b border-linea p-3">
                <button v-for="(n, k) in FILTROS" :key="k" class="btn btn-sm" :class="filtros.filtro === k ? 'btn-oscuro' : 'btn-secundario'" @click="ir({ filtro: k, c: undefined })">{{ n }}</button>
            </div>
            <form class="border-b border-linea p-3" @submit.prevent="ir({ c: undefined })"><input v-model="buscar" class="campo h-9 w-full" placeholder="Nombre o número" aria-label="Buscar conversación" /></form>
            <ul class="flex-1 divide-y divide-linea overflow-y-auto lg:max-h-[620px]">
                <li v-for="c in conversaciones" :key="c.id">
                    <button class="flex w-full items-start gap-3 p-3 text-left hover:bg-hormigon" :class="{ 'bg-hormigon': actual?.id === c.id }" @click="abrir(c.id)">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold" :class="{ 'text-grafito': c.estado === 'cerrada' }">{{ c.nombre ?? formatoTel(c.telefono) }}</p>
                            <p class="num truncate text-[13px] text-grafito">{{ formatoTel(c.telefono) }}<template v-if="c.asignado"> · {{ c.asignado }}</template></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[12px] text-grafito">{{ c.ultimo ? haceCuanto(c.ultimo) : '' }}</p>
                            <span v-if="c.no_leidos" class="num mt-1 inline-block rounded-full bg-naranja px-2 text-[12px] font-bold text-white">{{ c.no_leidos }}</span>
                        </div>
                    </button>
                </li>
                <li v-if="!conversaciones.length" class="p-4 text-[14px] text-grafito">No hay conversaciones con este filtro.</li>
            </ul>
        </section>

        <section v-if="actual" class="tarjeta flex min-h-[520px] flex-col">
            <div class="flex flex-wrap items-start gap-3 border-b border-linea p-4">
                <Link href="/ventas/whatsapp" class="btn btn-texto btn-sm lg:hidden" aria-label="Volver a la lista"><Icono nombre="flecha-izquierda" :tamano="16" /></Link>
                <div class="min-w-0 flex-1">
                    <h2 class="text-[18px] font-bold">{{ actual.nombre ?? formatoTel(actual.telefono) }}</h2>
                    <p class="num text-[14px] text-grafito">{{ formatoTel(actual.telefono) }}</p>
                    <p class="mt-1 flex flex-wrap gap-2 text-[13px]">
                        <Link v-if="actual.cliente" :href="`/ventas/cuentas-corrientes/${actual.cliente.id}`">Cliente: {{ actual.cliente.nombre }}</Link>
                        <span v-if="actual.lead">Prospecto: <Link :href="`/ventas/campanas/${actual.lead.campana_id}`">{{ actual.lead.nombre }}</Link> <Chip chico :tono="estadoLead[actual.lead.estado][0]">{{ estadoLead[actual.lead.estado][1] }}</Chip></span>
                        <span class="text-grafito">{{ actual.asignado ? `Atiende ${actual.asignado}` : 'Sin asignar' }}</span>
                    </p>
                </div>
                <div v-if="puedeEscribir" class="flex flex-wrap gap-2">
                    <button v-if="actual.asignado_a !== yo" class="btn btn-secundario btn-sm" @click="estado({ asignado_a: yo })">Tomar</button>
                    <button v-if="actual.estado === 'abierta'" class="btn btn-secundario btn-sm" @click="estado({ estado: 'cerrada' })">Cerrar</button>
                    <button v-else class="btn btn-secundario btn-sm" @click="estado({ estado: 'abierta' })">Reabrir</button>
                </div>
            </div>

            <div ref="hilo" class="flex-1 space-y-2 overflow-y-auto bg-hormigon p-4 lg:max-h-[520px]">
                <div v-for="m in actual.mensajes" :key="m.id" class="flex" :class="m.direccion === 'saliente' ? 'justify-end' : 'justify-start'">
                    <div class="max-w-[80%] rounded-tarjeta px-3 py-2 shadow-sm" :class="m.direccion === 'saliente' ? 'bg-carbon text-hormigon' : 'bg-white'">
                        <p v-if="m.tipo === 'plantilla'" class="text-[11px] font-semibold uppercase tracking-wide text-naranja">Plantilla</p>
                        <p class="whitespace-pre-line break-words">{{ m.cuerpo }}</p>
                        <p class="mt-1 text-right text-[11px]" :class="m.direccion === 'saliente' ? 'text-linea-fuerte' : 'text-grafito'">
                            <template v-if="m.autor">{{ m.autor }} · </template>{{ fechaHora(m.fecha) }}<template v-if="ESTADO_MSJ[m.estado]"> · <span :class="{ 'font-semibold text-[#F1B5AE]': m.estado === 'fallido' }">{{ ESTADO_MSJ[m.estado] }}</span></template>
                        </p>
                        <p v-if="m.estado === 'fallido' && m.error" class="text-[12px] text-[#F1B5AE]">{{ m.error }}</p>
                    </div>
                </div>
                <p v-if="!actual.mensajes.length" class="text-center text-[14px] text-grafito">Aún no hay mensajes. Inicia la conversación con una plantilla.</p>
            </div>

            <div v-if="resumen" class="border-t border-linea bg-estado-info-bg p-3 text-[14px] text-estado-info"><strong>Resumen:</strong> {{ resumen }} <button class="btn btn-texto btn-sm" @click="resumen = ''">Ocultar</button></div>
            <div v-if="puedeEscribir && activa" class="border-t border-linea p-3">
                <div v-if="iaActiva" class="mb-2 flex flex-wrap items-center gap-2">
                    <button class="btn btn-texto btn-sm" :disabled="!!iaCargando || !actual.mensajes.length" @click="ia('sugerir')"><Icono nombre="chispa" :tamano="16" /> {{ iaCargando === 'sugerir' ? 'Pensando…' : 'Sugerir respuesta' }}</button>
                    <button class="btn btn-texto btn-sm" :disabled="!!iaCargando || !actual.mensajes.length" @click="ia('resumir')">{{ iaCargando === 'resumir' ? 'Resumiendo…' : 'Resumir conversación' }}</button>
                    <span class="text-[12px] text-grafito">El asistente recibe el texto sin RUT, teléfonos, correos ni nombres.</span>
                    <p v-if="iaError" class="error w-full">{{ iaError }}</p>
                </div>
                <template v-if="actual.en_ventana">
                    <div class="flex items-end gap-2">
                        <textarea v-model="ft.texto" rows="2" maxlength="4096" class="campo min-w-0 flex-1" placeholder="Escribe un mensaje (Enter envía, Shift+Enter nueva línea)" aria-label="Mensaje" @keydown="teclado"></textarea>
                        <button class="btn btn-primario" :disabled="ft.processing || !ft.texto.trim()" aria-label="Enviar" @click="enviar"><Icono nombre="enviar" :tamano="18" /></button>
                    </div>
                    <p v-if="ft.errors.texto" class="error">{{ ft.errors.texto }}</p>
                    <button class="btn btn-texto btn-sm mt-1" @click="usandoPlantilla = true">Usar plantilla</button>
                </template>
                <div v-else class="flex flex-wrap items-center gap-3 rounded-lg bg-estado-alerta-bg p-3 text-[14px] text-estado-alerta">
                    <span class="flex-1">Pasaron más de 24 horas desde el último mensaje del cliente: solo puedes enviar una plantilla aprobada.</span>
                    <button class="btn btn-primario btn-sm" @click="usandoPlantilla = true">Enviar plantilla</button>
                </div>
            </div>
        </section>
        <Vacio v-else class="tarjeta hidden lg:block" titulo="Elige una conversación" texto="Los mensajes nuevos llegan aquí en cuanto el cliente escribe." />
    </div>

    <Modal :abierto="usandoPlantilla" titulo="Enviar plantilla" @cerrar="usandoPlantilla = false">
        <Campo etiqueta="Plantilla" id="wp-pl" obligatorio :error="fp.errors.plantilla_id">
            <select id="wp-pl" v-model="fp.plantilla_id" class="campo h-11"><option :value="null" disabled>Selecciona…</option><option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.nombre }} ({{ p.idioma }})</option></select>
        </Campo>
        <div v-for="(v, i) in fp.variables" :key="i" class="mt-3">
            <Campo :etiqueta="`Variable {{${i + 1}}}`" :id="`wp-v${i}`" obligatorio><input :id="`wp-v${i}`" v-model="fp.variables[i]" class="campo h-10" maxlength="200" /></Campo>
        </div>
        <p v-if="(fp.errors as Record<string, string>).variables" class="error">{{ (fp.errors as Record<string, string>).variables }}</p>
        <p v-if="ft.errors.texto" class="error">{{ ft.errors.texto }}</p>
        <div v-if="plantilla" class="mt-4 rounded-lg bg-hormigon p-3 text-[14px]"><p class="etiqueta">Vista previa</p><p class="whitespace-pre-line">{{ vista }}</p></div>
        <template #pie>
            <button class="btn btn-secundario" @click="usandoPlantilla = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fp.processing || !fp.plantilla_id" @click="enviarPlantilla">Enviar</button>
        </template>
    </Modal>

    <Modal :abierto="nueva" titulo="Nueva conversación" @cerrar="nueva = false">
        <p class="mb-3 text-[14px] text-grafito">Para escribir primero a un número hay que usar una plantilla aprobada. Si el número es de un cliente o prospecto, la conversación se vincula sola.</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <Campo etiqueta="Número" id="wn-tel" obligatorio :error="fn.errors.telefono"><input id="wn-tel" v-model="fn.telefono" class="campo num h-11" inputmode="tel" maxlength="20" placeholder="+56 9 1234 5678" /></Campo>
            <Campo etiqueta="Nombre" id="wn-nom" :error="fn.errors.nombre"><input id="wn-nom" v-model="fn.nombre" class="campo h-11" maxlength="120" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="nueva = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fn.processing || !fn.telefono" @click="iniciar">Abrir conversación</button>
        </template>
    </Modal>
</template>
