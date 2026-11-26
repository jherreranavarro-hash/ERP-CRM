<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Icono from '@/Components/Icono.vue';
import type { PageProps, Paginado } from '@/types';
import { fechaHora } from '@/lib/formato';
import { duracion } from '@/lib/documentos';
import { http } from '@/lib/http';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; inicio: string; agente: string | null; agente_user_id: number | null; campana: string | null; contacto: string | null; numero: string; direccion: string; duracion: number | null; resultado: string | null; grabacion: boolean; puntaje: number | null; auditoria_id: number | null }
interface Criterio { id: number; nombre: string; peso: number; critico: boolean; activo: boolean; orden: number }
interface Detalle { checklist: { criterio: string; peso: number; critico: boolean; cumple: boolean }[]; puntaje: number; comentario: string | null; auditor: string | null; fecha: string }
const props = defineProps<{
    llamadas: Paginado<Fila>; criterios: Criterio[]; promedios: { agente: string; promedio: number; evaluaciones: number }[];
    agentes: { id: number; nombre: string }[]; campanas: { id: number; nombre: string }[]; resultados: Record<string, string>;
    filtrosActivos: { agente?: string; campana?: string; desde?: string; hasta?: string };
}>();
const puede = usePermisos();
const yo = usePage<PageProps>().props.auth.user?.id;

const f = reactive({ agente: props.filtrosActivos.agente ?? '', campana: props.filtrosActivos.campana ?? '', desde: props.filtrosActivos.desde ?? '', hasta: props.filtrosActivos.hasta ?? '' });
const filtrar = () => router.get('/ventas/auditoria', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });
const tono = (p: number) => (p >= 85 ? 'ok' : p >= 60 ? 'alerta' : 'error');

const activos = computed(() => props.criterios.filter((c) => c.activo));
const evaluando = ref<Fila | null>(null);
const fe = useForm({ cumple: {} as Record<number, boolean>, comentario: '' });
const errE = fe.errors as Record<string, string>;
function evaluar(l: Fila) {
    evaluando.value = l;
    fe.clearErrors();
    fe.cumple = Object.fromEntries(activos.value.map((c) => [c.id, true]));
    fe.comentario = '';
}
const previo = computed(() => {
    const total = activos.value.reduce((s, c) => s + c.peso, 0);
    if (activos.value.some((c) => c.critico && !fe.cumple[c.id])) return 0;
    return total ? Math.round((activos.value.filter((c) => fe.cumple[c.id]).reduce((s, c) => s + c.peso, 0) / total) * 1000) / 10 : 0;
});
const registrar = () => fe.post(`/ventas/auditoria/${evaluando.value!.id}`, { preserveScroll: true, onSuccess: () => { evaluando.value = null; } });

const detalle = ref<Detalle | null>(null);
const ver = async (l: Fila) => { detalle.value = (await http.get<Detalle>(`/ventas/auditoria/evaluaciones/${l.auditoria_id}`)).data; };

const critEditando = ref<Criterio | null>(null);
const critAbierto = ref(false);
const fc = useForm({ nombre: '', peso: 10, critico: false, activo: true, orden: 0 });
function abrirCriterio(c: Criterio | null) {
    critEditando.value = c;
    fc.clearErrors();
    Object.assign(fc, c ? { nombre: c.nombre, peso: c.peso, critico: c.critico, activo: c.activo, orden: c.orden } : { nombre: '', peso: 10, critico: false, activo: true, orden: props.criterios.length });
    critAbierto.value = true;
}
const guardarCriterio = () => {
    const o = { preserveScroll: true, onSuccess: () => { critAbierto.value = false; } };
    critEditando.value ? fc.put(`/ventas/auditoria/criterios/${critEditando.value.id}`, o) : fc.post('/ventas/auditoria/criterios', o);
};
</script>

<template>
    <Head title="Auditoría de calidad" />
    <Encabezado titulo="Auditoría de calidad" bajada="Evalúa llamadas contestadas con el checklist. Un criterio crítico no cumplido deja la evaluación en 0. Nadie evalúa sus propias llamadas." />

    <div class="mb-6 grid gap-6 lg:grid-cols-3">
        <section class="tarjeta lg:col-span-2">
            <div class="flex items-center justify-between gap-3 border-b border-linea p-4">
                <h2 class="text-[18px] font-bold">Checklist</h2>
                <button v-if="puede('ventascrm.auditoria.editar')" class="btn btn-secundario btn-sm" @click="abrirCriterio(null)"><Icono nombre="mas" :tamano="16" /> Criterio</button>
            </div>
            <ul class="divide-y divide-linea">
                <li v-for="c in criterios" :key="c.id" class="flex items-center justify-between gap-3 p-3 px-4" :class="{ 'text-grafito': !c.activo }">
                    <span>{{ c.nombre }} <Chip v-if="c.critico" chico tono="error">Crítico</Chip> <Chip v-if="!c.activo" chico tono="neutro">Inactivo</Chip></span>
                    <span class="flex items-center gap-3"><span class="num text-[14px]">{{ c.peso }} pts</span><button v-if="puede('ventascrm.auditoria.editar')" class="btn btn-texto btn-sm" @click="abrirCriterio(c)">Editar</button></span>
                </li>
                <li v-if="!criterios.length" class="p-4 text-grafito">Define los criterios antes de evaluar.</li>
            </ul>
        </section>
        <section class="tarjeta self-start">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Promedio por agente</h2>
            <ul class="divide-y divide-linea">
                <li v-for="p in promedios" :key="p.agente" class="flex items-center justify-between p-3 px-4"><span>{{ p.agente }} <span class="text-[13px] text-grafito">({{ p.evaluaciones }})</span></span><Chip chico :tono="tono(p.promedio)">{{ p.promedio }}</Chip></li>
                <li v-if="!promedios.length" class="p-4 text-grafito">Aún no hay evaluaciones.</li>
            </ul>
        </section>
    </div>

    <form class="tarjeta mb-4 flex flex-wrap items-end gap-3 p-4" @submit.prevent="filtrar">
        <Campo etiqueta="Agente" id="fa-ag"><select id="fa-ag" v-model="f.agente" class="campo h-10 w-48"><option value="">Todos</option><option v-for="a in agentes" :key="a.id" :value="String(a.id)">{{ a.nombre }}</option></select></Campo>
        <Campo etiqueta="Campaña" id="fa-ca"><select id="fa-ca" v-model="f.campana" class="campo h-10 w-48"><option value="">Todas</option><option v-for="c in campanas" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option></select></Campo>
        <Campo etiqueta="Desde" id="fa-de"><input id="fa-de" v-model="f.desde" type="date" class="campo h-10" /></Campo>
        <Campo etiqueta="Hasta" id="fa-ha"><input id="fa-ha" v-model="f.hasta" type="date" class="campo h-10" /></Campo>
        <button class="btn btn-secundario">Filtrar</button>
    </form>

    <div class="tarjeta">
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Fecha</th><th>Agente</th><th>Contacto</th><th>Campaña</th><th class="text-right">Duración</th><th>Resultado</th><th>Puntaje</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="l in llamadas.data" :key="l.id">
                        <td class="num text-[14px]">{{ fechaHora(l.inicio) }}</td>
                        <td>{{ l.agente ?? '—' }}</td>
                        <td>{{ l.contacto ?? '' }} <span class="num text-[13px] text-grafito">{{ l.numero }}</span></td>
                        <td>{{ l.campana ?? '—' }}</td>
                        <td class="num text-right">{{ duracion(l.duracion ?? 0) }}</td>
                        <td>{{ l.resultado ? resultados[l.resultado] : '—' }}</td>
                        <td><Chip v-if="l.puntaje !== null" chico :tono="tono(l.puntaje)">{{ l.puntaje }}</Chip><span v-else class="text-grafito">—</span></td>
                        <td class="text-right">
                            <button v-if="l.auditoria_id" class="btn btn-texto btn-sm" @click="ver(l)">Ver</button>
                            <button v-else-if="puede('ventascrm.auditoria.crear') && l.agente_user_id !== yo" class="btn btn-secundario btn-sm" :disabled="!activos.length" @click="evaluar(l)">Evaluar</button>
                        </td>
                    </tr>
                    <tr v-if="!llamadas.data.length"><td colspan="8" class="text-center text-grafito">Sin llamadas contestadas en el filtro.</td></tr>
                </tbody>
            </table>
        </div>
        <Paginacion :datos="llamadas" nombre="llamadas" />
    </div>

    <Modal :abierto="!!evaluando" :titulo="`Evaluar llamada de ${evaluando?.agente ?? ''}`" @cerrar="evaluando = null">
        <ul class="space-y-2">
            <li v-for="c in activos" :key="c.id" class="flex items-center justify-between gap-3">
                <label :for="`ev-${c.id}`" class="flex items-center gap-2"><input :id="`ev-${c.id}`" v-model="fe.cumple[c.id]" type="checkbox" /> {{ c.nombre }} <Chip v-if="c.critico" chico tono="error">Crítico</Chip></label>
                <span class="num text-[14px] text-grafito">{{ c.peso }} pts</span>
            </li>
        </ul>
        <Campo etiqueta="Comentario" id="ev-com" :error="fe.errors.comentario" class="mt-4"><textarea id="ev-com" v-model="fe.comentario" rows="3" maxlength="1000" class="campo"></textarea></Campo>
        <p class="mt-3 font-semibold">Puntaje: <span class="num">{{ previo }}</span></p>
        <p v-if="errE.llamada" class="error">{{ errE.llamada }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="evaluando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="fe.processing" @click="registrar">Registrar evaluación</button>
        </template>
    </Modal>

    <Modal :abierto="!!detalle" :titulo="`Evaluación · ${detalle?.puntaje ?? ''} puntos`" @cerrar="detalle = null">
        <template v-if="detalle">
            <p class="mb-3 text-[14px] text-grafito">Por {{ detalle.auditor }} el {{ fechaHora(detalle.fecha) }}</p>
            <ul class="space-y-1">
                <li v-for="(c, i) in detalle.checklist" :key="i" class="flex justify-between gap-3"><span>{{ c.cumple ? '✓' : '✗' }} {{ c.criterio }}<template v-if="c.critico"> (crítico)</template></span><span class="num text-grafito">{{ c.peso }} pts</span></li>
            </ul>
            <p v-if="detalle.comentario" class="mt-3 whitespace-pre-line">{{ detalle.comentario }}</p>
        </template>
        <template #pie><button class="btn btn-secundario" @click="detalle = null">Cerrar</button></template>
    </Modal>

    <Modal :abierto="critAbierto" :titulo="critEditando ? 'Editar criterio' : 'Nuevo criterio'" @cerrar="critAbierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Criterio" id="cr-nom" obligatorio :error="fc.errors.nombre" class="sm:col-span-2"><input id="cr-nom" v-model="fc.nombre" class="campo h-11" maxlength="120" /></Campo>
            <Campo etiqueta="Peso (puntos)" id="cr-peso" obligatorio :error="fc.errors.peso"><input id="cr-peso" v-model.number="fc.peso" type="number" min="1" max="100" class="campo num h-11" /></Campo>
            <Campo etiqueta="Orden" id="cr-ord" :error="fc.errors.orden"><input id="cr-ord" v-model.number="fc.orden" type="number" min="0" max="999" class="campo num h-11" /></Campo>
        </div>
        <div class="mt-4 flex flex-wrap gap-6"><Interruptor v-model="fc.critico" etiqueta="Crítico (si no se cumple, la nota es 0)" /><Interruptor v-model="fc.activo" etiqueta="Activo" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="critAbierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fc.processing" @click="guardarCriterio">Guardar</button>
        </template>
    </Modal>
</template>
