<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora, haceCuanto } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; nombre: string; cargo: string | null; organizacion: string | null; estado: string; no_leidos: number; asignado_a: number | null; asignado: string | null; ultimo: string | null; con_lead: boolean }
interface Mensaje { id: number; direccion: string; cuerpo: string; estado: string; error: string | null; fecha: string }
interface Actual { id: number; nombre: string; cargo: string | null; organizacion: string | null; perfil_url: string | null; estado: string; asignado_a: number | null; asignado: string | null;
    lead: { id: number; nombre: string; estado: string } | null; mensajes: Mensaje[] }
const props = defineProps<{ activa: boolean; filtro: string; conversaciones: Fila[]; actual: Actual | null; puedeTodas: boolean; campanas: { id: number; nombre: string }[]; usuarios: { id: number; name: string }[] }>();
const puede = usePermisos();
const yo = computed(() => (usePage().props.auth as { user: { id: number } }).user.id);
const puedeEscribir = computed(() => puede('ventascrm.linkedin.crear'));
const FILTROS: Record<string, string> = { abiertas: 'Abiertas', no_leidas: 'Sin leer', mias: 'Mías', todas: 'Todas' };
const ESTADO_MSJ: Record<string, string> = { en_cola: 'En cola', enviado: 'Enviado', fallido: 'No enviado' };

const ir = (q: Record<string, unknown>) => router.get('/ventas/linkedin', { filtro: props.filtro, ...q }, { preserveScroll: true, preserveState: true });
const abrir = (id: number) => ir({ c: id });
const sincronizar = () => router.post('/ventas/linkedin/sincronizar', {}, { preserveScroll: true });

const ft = useForm({ texto: '' });
const enviar = () => ft.post(`/ventas/linkedin/${props.actual!.id}/texto`, { preserveScroll: true, onSuccess: () => ft.reset() });
function teclado(e: KeyboardEvent) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (ft.texto.trim()) enviar();
    }
}
const estado = (accion: string, user_id?: number | null) => router.post(`/ventas/linkedin/${props.actual!.id}/estado`, { accion, user_id }, { preserveScroll: true });

const creandoLead = ref(false);
const fl = useForm({ campana_id: null as number | null, telefono: '', comuna: '' });
const crearLead = () => fl.post(`/ventas/linkedin/${props.actual!.id}/lead`, { preserveScroll: true, onSuccess: () => { creandoLead.value = false; fl.reset(); } });

const hilo = ref<HTMLElement | null>(null);
const bajar = () => nextTick(() => hilo.value && (hilo.value.scrollTop = hilo.value.scrollHeight));
onMounted(bajar);
watch(() => props.actual?.mensajes.length, bajar);
const iniciales = (n: string) => n.split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase();
</script>

<template>
    <Head title="LinkedIn" />
    <Encabezado titulo="LinkedIn" bajada="Mensajes de prospección B2B. La bandeja se sincroniza sola cada 5 minutos; una conversación se puede convertir en prospecto del contact center.">
        <button class="btn btn-secundario" :disabled="!activa" @click="sincronizar">Sincronizar ahora</button>
    </Encabezado>
    <p v-if="!activa" class="tarjeta mb-4 p-4 text-[14px]">La integración de LinkedIn no está activa. Actívala en <Link href="/configuracion/integraciones">Configuración → Integraciones</Link>.</p>

    <div class="grid gap-4 lg:grid-cols-[340px_1fr]">
        <section class="tarjeta flex min-h-[420px] flex-col" :class="{ 'hidden lg:flex': actual }">
            <div class="flex flex-wrap gap-2 border-b border-linea p-3">
                <button v-for="(n, k) in FILTROS" :key="k" class="btn btn-sm" :class="filtro === k ? 'btn-oscuro' : 'btn-secundario'" @click="ir({ filtro: k, c: undefined })">{{ n }}</button>
            </div>
            <ul class="flex-1 divide-y divide-linea overflow-y-auto lg:max-h-[620px]">
                <li v-for="c in conversaciones" :key="c.id">
                    <button class="flex w-full items-start gap-3 p-3 text-left hover:bg-hormigon" :class="{ 'bg-hormigon': actual?.id === c.id }" @click="abrir(c.id)">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-estado-info-bg text-[13px] font-bold text-estado-info">{{ iniciales(c.nombre) }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold" :class="{ 'text-grafito': c.estado === 'cerrada' }">{{ c.nombre }}</p>
                            <p class="truncate text-[13px] text-grafito">{{ [c.cargo, c.organizacion].filter(Boolean).join(' · ') }}</p>
                            <p class="mt-0.5 flex flex-wrap gap-1 text-[12px] text-grafito"><Chip v-if="c.con_lead" chico tono="ok">Prospecto</Chip><span v-if="c.asignado">{{ c.asignado }}</span></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[12px] text-grafito">{{ c.ultimo ? haceCuanto(c.ultimo) : '' }}</p>
                            <span v-if="c.no_leidos" class="num mt-1 inline-block rounded-full bg-naranja px-2 text-[12px] font-bold text-carbon">{{ c.no_leidos }}</span>
                        </div>
                    </button>
                </li>
                <li v-if="!conversaciones.length" class="p-4 text-[14px] text-grafito">No hay conversaciones con este filtro.</li>
            </ul>
        </section>

        <section v-if="actual" class="tarjeta flex min-h-[520px] flex-col">
            <div class="flex flex-wrap items-start gap-3 border-b border-linea p-4">
                <Link href="/ventas/linkedin" class="btn btn-texto btn-sm lg:hidden" aria-label="Volver a la lista"><Icono nombre="flecha-izquierda" :tamano="16" /></Link>
                <div class="min-w-0 flex-1">
                    <h2 class="text-[18px] font-bold">{{ actual.nombre }}</h2>
                    <p class="text-[14px] text-grafito">{{ [actual.cargo, actual.organizacion].filter(Boolean).join(' · ') }}</p>
                    <p class="mt-1 flex flex-wrap gap-3 text-[13px]">
                        <a v-if="actual.perfil_url" :href="actual.perfil_url" target="_blank" rel="noopener noreferrer">Ver perfil</a>
                        <span v-if="actual.lead">Prospecto: <strong>{{ actual.lead.nombre }}</strong></span>
                        <span class="text-grafito">{{ actual.asignado ? `Atiende ${actual.asignado}` : 'Sin asignar' }}</span>
                    </p>
                </div>
                <div v-if="puedeEscribir" class="flex flex-wrap gap-2">
                    <button v-if="!actual.lead" class="btn btn-primario btn-sm" @click="creandoLead = true">Crear prospecto</button>
                    <button v-if="actual.asignado_a !== yo" class="btn btn-secundario btn-sm" @click="estado('tomar')">Tomar</button>
                    <select v-if="puedeTodas" class="campo h-9 w-auto text-[14px]" aria-label="Asignar a" :value="actual.asignado_a ?? ''" @change="estado('asignar', Number(($event.target as HTMLSelectElement).value) || null)">
                        <option value="">Sin asignar</option><option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <button v-if="actual.estado === 'abierta'" class="btn btn-secundario btn-sm" @click="estado('cerrar')">Cerrar</button>
                    <button v-else class="btn btn-secundario btn-sm" @click="estado('abrir')">Reabrir</button>
                </div>
            </div>
            <div ref="hilo" class="flex-1 space-y-2 overflow-y-auto bg-hormigon p-4 lg:max-h-[520px]">
                <div v-for="m in actual.mensajes" :key="m.id" class="flex" :class="m.direccion === 'saliente' ? 'justify-end' : 'justify-start'">
                    <div class="max-w-[80%] rounded-tarjeta px-3 py-2 shadow-sm" :class="m.direccion === 'saliente' ? 'bg-carbon text-hormigon' : 'bg-white'">
                        <p class="whitespace-pre-line break-words">{{ m.cuerpo }}</p>
                        <p class="mt-1 text-right text-[11px]" :class="m.direccion === 'saliente' ? 'text-linea-fuerte' : 'text-grafito'">
                            {{ fechaHora(m.fecha) }}<template v-if="ESTADO_MSJ[m.estado]"> · <span :class="{ 'font-semibold text-[#F1B5AE]': m.estado === 'fallido' }">{{ ESTADO_MSJ[m.estado] }}</span></template>
                        </p>
                        <p v-if="m.estado === 'fallido' && m.error" class="text-[12px] text-[#F1B5AE]">{{ m.error }}</p>
                    </div>
                </div>
            </div>
            <div v-if="puedeEscribir && activa" class="border-t border-linea p-3">
                <div class="flex items-end gap-2">
                    <textarea v-model="ft.texto" rows="2" maxlength="3000" class="campo min-w-0 flex-1" placeholder="Escribe un mensaje (Enter envía, Shift+Enter nueva línea)" aria-label="Mensaje" @keydown="teclado"></textarea>
                    <button class="btn btn-primario" :disabled="ft.processing || !ft.texto.trim()" aria-label="Enviar" @click="enviar"><Icono nombre="enviar" :tamano="18" /></button>
                </div>
                <p v-if="ft.errors.texto" class="error">{{ ft.errors.texto }}</p>
            </div>
        </section>
        <section v-else class="tarjeta hidden items-center justify-center p-10 text-center text-grafito lg:flex">Elige una conversación.</section>
    </div>

    <Modal :abierto="creandoLead" titulo="Crear prospecto" @cerrar="creandoLead = false">
        <p class="mb-4 text-[14px] text-grafito">LinkedIn no comparte el teléfono: pídeselo al contacto antes de crearlo. Se revisa la lista negra.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Campaña" id="li-camp" obligatorio :error="fl.errors.campana_id" class="sm:col-span-2">
                <select id="li-camp" v-model="fl.campana_id" class="campo h-11"><option :value="null" disabled>Elige</option><option v-for="c in campanas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Teléfono" id="li-tel" obligatorio :error="fl.errors.telefono"><input id="li-tel" v-model="fl.telefono" inputmode="tel" class="campo num h-11" maxlength="20" /></Campo>
            <Campo etiqueta="Comuna" id="li-com" :error="fl.errors.comuna"><input id="li-com" v-model="fl.comuna" class="campo h-11" maxlength="60" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="creandoLead = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fl.processing" @click="crearLead">Crear prospecto</button>
        </template>
    </Modal>
</template>
