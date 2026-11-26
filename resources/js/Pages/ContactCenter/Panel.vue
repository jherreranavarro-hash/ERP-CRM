<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Icono from '@/Components/Icono.vue';
import { DIAS_SEMANA, estadoCampana } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Cola { id: number; nombre: string; estrategia: string; espera_max_seg: number; nivel_servicio_seg: number; ivr_desborde_id: number | null; activo: boolean; agentes: number; desborde: string | null }
interface Campana { id: number; nombre: string; estado: string; horario: { dias: number[]; desde: string; hasta: string }; max_intentos: number; en_horario: boolean }
defineProps<{ colas: Cola[]; campanas: Campana[]; ivrs: { id: number; nombre: string; activo: boolean }[]; estrategias: Record<string, string> }>();
const puede = usePermisos();

const colaEditando = ref<Cola | null>(null);
const colaAbierta = ref(false);
const fc = useForm({ nombre: '', estrategia: 'menos_reciente', espera_max_seg: 120, nivel_servicio_seg: 20, ivr_desborde_id: null as number | null, activo: true });
function abrirCola(c: Cola | null) {
    colaEditando.value = c;
    fc.clearErrors();
    Object.assign(fc, c ? { nombre: c.nombre, estrategia: c.estrategia, espera_max_seg: c.espera_max_seg, nivel_servicio_seg: c.nivel_servicio_seg, ivr_desborde_id: c.ivr_desborde_id, activo: c.activo }
        : { nombre: '', estrategia: 'menos_reciente', espera_max_seg: 120, nivel_servicio_seg: 20, ivr_desborde_id: null, activo: true });
    colaAbierta.value = true;
}
const guardarCola = () => {
    const o = { preserveScroll: true, onSuccess: () => { colaAbierta.value = false; } };
    colaEditando.value ? fc.put(`/ventas/panel/colas/${colaEditando.value.id}`, o) : fc.post('/ventas/panel/colas', o);
};

const campEditando = ref<Campana | null>(null);
const fh = useForm({ horario: { dias: [] as number[], desde: '09:00', hasta: '18:00' }, max_intentos: 3 });
const errH = fh.errors as Record<string, string>;
function abrirHorario(c: Campana) {
    campEditando.value = c;
    fh.clearErrors();
    fh.horario = { dias: [...c.horario.dias], desde: c.horario.desde, hasta: c.horario.hasta };
    fh.max_intentos = c.max_intentos;
}
const alternarDia = (d: number) => { fh.horario.dias = fh.horario.dias.includes(d) ? fh.horario.dias.filter((x) => x !== d) : [...fh.horario.dias, d]; };
const guardarHorario = () => fh.put(`/ventas/panel/campanas/${campEditando.value!.id}/horario`, { preserveScroll: true, onSuccess: () => { campEditando.value = null; } });
</script>

<template>
    <Head title="Panel de control" />
    <Encabezado titulo="Panel de control" bajada="Colas de llamadas entrantes, horario de discado de las campañas y menús de voz.">
        <Link href="/ventas/tiempo-real" class="btn btn-secundario no-underline">Tiempo real</Link>
    </Encabezado>

    <section class="tarjeta mb-6">
        <div class="flex items-center justify-between gap-3 border-b border-linea p-4">
            <h2 class="text-[18px] font-bold">Colas</h2>
            <button v-if="puede('ventascrm.panel-de-control.editar')" class="btn btn-primario btn-sm" @click="abrirCola(null)"><Icono nombre="mas" :tamano="16" /> Nueva cola</button>
        </div>
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Cola</th><th>Estrategia</th><th class="text-right">Espera máx.</th><th class="text-right">Meta de atención</th><th>Desborde</th><th class="text-right">Agentes</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="c in colas" :key="c.id" :class="{ 'text-grafito': !c.activo }">
                        <td class="font-semibold">{{ c.nombre }} <Chip v-if="!c.activo" chico tono="neutro">Inactiva</Chip></td>
                        <td>{{ estrategias[c.estrategia] }}</td>
                        <td class="num text-right">{{ c.espera_max_seg }} s</td>
                        <td class="num text-right">{{ c.nivel_servicio_seg }} s</td>
                        <td>{{ c.desborde ?? '—' }}</td>
                        <td class="num text-right">{{ c.agentes }}</td>
                        <td class="text-right"><button v-if="puede('ventascrm.panel-de-control.editar')" class="btn btn-secundario btn-sm" @click="abrirCola(c)">Editar</button></td>
                    </tr>
                    <tr v-if="!colas.length"><td colspan="7" class="text-center text-grafito">Sin colas. Crea una para recibir llamadas entrantes.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="tarjeta lg:col-span-2">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Horario de discado</h2>
            <ul class="divide-y divide-linea">
                <li v-for="c in campanas" :key="c.id" class="flex flex-wrap items-center justify-between gap-3 p-4">
                    <div>
                        <p class="font-semibold"><Link :href="`/ventas/campanas/${c.id}`">{{ c.nombre }}</Link> <Chip chico :tono="estadoCampana[c.estado][0]">{{ estadoCampana[c.estado][1] }}</Chip></p>
                        <p class="text-[14px] text-grafito">{{ c.horario.dias.map((d) => DIAS_SEMANA[d]).join(' ') }} · {{ c.horario.desde }} a {{ c.horario.hasta }} · {{ c.max_intentos }} intentos · <span :class="c.en_horario ? 'text-estado-ok' : ''">{{ c.en_horario ? 'en horario ahora' : 'fuera de horario' }}</span></p>
                    </div>
                    <button v-if="puede('ventascrm.panel-de-control.editar')" class="btn btn-secundario btn-sm" @click="abrirHorario(c)">Cambiar horario</button>
                </li>
                <li v-if="!campanas.length" class="p-4 text-grafito">Sin campañas abiertas.</li>
            </ul>
        </section>
        <section class="tarjeta self-start">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Menús de voz (IVR)</h2>
            <ul class="divide-y divide-linea">
                <li v-for="i in ivrs" :key="i.id" class="flex items-center justify-between p-4"><span>{{ i.nombre }}</span><Chip chico :tono="i.activo ? 'ok' : 'neutro'">{{ i.activo ? 'Activo' : 'Inactivo' }}</Chip></li>
                <li v-if="!ivrs.length" class="p-4 text-grafito">Sin menús de voz.</li>
            </ul>
            <div v-if="puede('configuracion.ivr.ver')" class="p-4"><Link href="/configuracion/telefonia/ivr" class="btn btn-secundario btn-sm no-underline">Administrar menús</Link></div>
        </section>
    </div>

    <Modal :abierto="colaAbierta" :titulo="colaEditando ? `Editar ${colaEditando.nombre}` : 'Nueva cola'" @cerrar="colaAbierta = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Nombre" id="q-nom" obligatorio :error="fc.errors.nombre" class="sm:col-span-2"><input id="q-nom" v-model="fc.nombre" class="campo h-11" maxlength="60" /></Campo>
            <Campo etiqueta="Estrategia de reparto" id="q-est" obligatorio :error="fc.errors.estrategia" class="sm:col-span-2"><select id="q-est" v-model="fc.estrategia" class="campo h-11"><option v-for="(n, k) in estrategias" :key="k" :value="k">{{ n }}</option></select></Campo>
            <Campo etiqueta="Espera máxima (s)" id="q-esp" obligatorio :error="fc.errors.espera_max_seg"><input id="q-esp" v-model.number="fc.espera_max_seg" type="number" min="10" max="3600" class="campo num h-11" /></Campo>
            <Campo etiqueta="Meta de atención (s)" id="q-ns" obligatorio :error="fc.errors.nivel_servicio_seg"><input id="q-ns" v-model.number="fc.nivel_servicio_seg" type="number" min="5" max="600" class="campo num h-11" /></Campo>
            <Campo etiqueta="Si se supera la espera, derivar a" id="q-ivr" :error="fc.errors.ivr_desborde_id" class="sm:col-span-2"><select id="q-ivr" v-model="fc.ivr_desborde_id" class="campo h-11"><option :value="null">Seguir esperando</option><option v-for="i in ivrs" :key="i.id" :value="i.id">{{ i.nombre }}</option></select></Campo>
        </div>
        <div class="mt-4"><Interruptor v-model="fc.activo" etiqueta="Activa" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="colaAbierta = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fc.processing" @click="guardarCola">Guardar</button>
        </template>
    </Modal>

    <Modal :abierto="!!campEditando" :titulo="`Horario · ${campEditando?.nombre ?? ''}`" @cerrar="campEditando = null">
        <p class="etiqueta">Días</p>
        <div class="flex flex-wrap gap-2">
            <button v-for="d in 7" :key="d" type="button" class="btn btn-sm" :class="fh.horario.dias.includes(d) ? 'btn-oscuro' : 'btn-secundario'" :aria-pressed="fh.horario.dias.includes(d)" @click="alternarDia(d)">{{ DIAS_SEMANA[d] }}</button>
        </div>
        <p v-if="errH['horario.dias']" class="error">{{ errH['horario.dias'] }}</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <Campo etiqueta="Desde" id="h-d" obligatorio :error="errH['horario.desde']"><input id="h-d" v-model="fh.horario.desde" type="time" class="campo h-11" /></Campo>
            <Campo etiqueta="Hasta" id="h-h" obligatorio :error="errH['horario.hasta']"><input id="h-h" v-model="fh.horario.hasta" type="time" class="campo h-11" /></Campo>
            <Campo etiqueta="Intentos máx." id="h-i" obligatorio :error="fh.errors.max_intentos"><input id="h-i" v-model.number="fh.max_intentos" type="number" min="1" max="20" class="campo num h-11" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="campEditando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="fh.processing" @click="guardarHorario">Guardar</button>
        </template>
    </Modal>
</template>
