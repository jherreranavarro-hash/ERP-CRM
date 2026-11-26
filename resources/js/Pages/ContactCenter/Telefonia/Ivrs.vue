<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { DIAS_SEMANA } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Opcion { tecla: string; etiqueta: string; accion: string; destino: number | null }
interface Horario { dias: number[]; desde: string; hasta: string }
interface Ivr { id: number; nombre: string; bienvenida: string; fuera_de_horario: string | null; opciones: Opcion[]; horario: Horario | null; activo: boolean }
const props = defineProps<{ ivrs: Ivr[]; colas: { id: number; nombre: string }[]; acciones: Record<string, string> }>();
const puede = usePermisos();
const editando = ref<Ivr | null>(null);
const abierto = ref(false);
const conHorario = ref(false);
const form = useForm({ nombre: '', bienvenida: '', fuera_de_horario: '', opciones: [] as Opcion[], horario: { dias: [1, 2, 3, 4, 5], desde: '09:00', hasta: '18:00' } as Horario, activo: true });
const errores = form.errors as Record<string, string>;
function abrir(i: Ivr | null) {
    editando.value = i;
    form.clearErrors();
    form.nombre = i?.nombre ?? '';
    form.bienvenida = i?.bienvenida ?? '';
    form.fuera_de_horario = i?.fuera_de_horario ?? '';
    form.opciones = i ? i.opciones.map((o) => ({ ...o })) : [{ tecla: '1', etiqueta: 'Ventas', accion: 'cola', destino: props.colas[0]?.id ?? null }];
    conHorario.value = !!i?.horario?.dias?.length;
    form.horario = i?.horario?.dias?.length ? { ...i.horario, dias: [...i.horario.dias] } : { dias: [1, 2, 3, 4, 5], desde: '09:00', hasta: '18:00' };
    form.activo = i?.activo ?? true;
    abierto.value = true;
}
const otros = () => props.ivrs.filter((i) => i.id !== editando.value?.id);
const destinos = (accion: string) => (accion === 'cola' ? props.colas : accion === 'ivr' ? otros() : []);
const nombreDestino = (o: Opcion) => destinos(o.accion).find((d) => d.id === o.destino)?.nombre ?? props.ivrs.find((d) => d.id === o.destino)?.nombre;
const alternarDia = (d: number) => { form.horario.dias = form.horario.dias.includes(d) ? form.horario.dias.filter((x) => x !== d) : [...form.horario.dias, d]; };
const guardar = () => {
    const o = { preserveScroll: true, onSuccess: () => { abierto.value = false; } };
    const f = form.transform((d) => ({ ...d, fuera_de_horario: d.fuera_de_horario || null, horario: conHorario.value ? d.horario : null,
        opciones: d.opciones.map((x) => ({ ...x, destino: x.accion === 'colgar' ? null : x.destino })) }));
    editando.value ? f.put(`/configuracion/telefonia/ivr/${editando.value.id}`, o) : f.post('/configuracion/telefonia/ivr', o);
};
</script>

<template>
    <Head title="Menús de voz" />
    <Encabezado titulo="Menús de voz (IVR)" bajada="Lo que escucha quien llama y adónde lo lleva cada tecla.">
        <button v-if="puede('configuracion.ivr.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo menú</button>
    </Encabezado>

    <div v-if="ivrs.length" class="grid gap-4 lg:grid-cols-2">
        <article v-for="i in ivrs" :key="i.id" class="tarjeta p-5">
            <div class="mb-2 flex items-start justify-between gap-3">
                <h2 class="text-[18px] font-bold">{{ i.nombre }}</h2>
                <Chip chico :tono="i.activo ? 'ok' : 'neutro'">{{ i.activo ? 'Activo' : 'Inactivo' }}</Chip>
            </div>
            <p class="whitespace-pre-line text-[14px] italic">«{{ i.bienvenida }}»</p>
            <ul class="mt-3 space-y-1 text-[14px]">
                <li v-for="o in i.opciones" :key="o.tecla"><span class="num inline-block w-6 rounded bg-linea text-center font-bold">{{ o.tecla }}</span> {{ o.etiqueta }} <span class="text-grafito">→ {{ acciones[o.accion] }}<template v-if="nombreDestino(o)">: {{ nombreDestino(o) }}</template></span></li>
            </ul>
            <p v-if="i.horario?.dias?.length" class="mt-2 text-[13px] text-grafito">Atiende {{ i.horario.dias.map((d) => DIAS_SEMANA[d]).join(' ') }} de {{ i.horario.desde }} a {{ i.horario.hasta }}</p>
            <button v-if="puede('configuracion.ivr.editar')" class="btn btn-secundario btn-sm mt-3" @click="abrir(i)">Editar</button>
        </article>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin menús de voz" texto="Crea un menú para recibir las llamadas entrantes y derivarlas a la cola que corresponda." />

    <Modal :abierto="abierto" :titulo="editando ? `Editar ${editando.nombre}` : 'Nuevo menú de voz'" @cerrar="abierto = false">
        <Campo etiqueta="Nombre" id="iv-nom" obligatorio :error="form.errors.nombre"><input id="iv-nom" v-model="form.nombre" class="campo h-11" maxlength="80" /></Campo>
        <Campo etiqueta="Mensaje de bienvenida" id="iv-bien" obligatorio :error="form.errors.bienvenida" class="mt-3"><textarea id="iv-bien" v-model="form.bienvenida" rows="3" class="campo" maxlength="1000"></textarea></Campo>
        <p class="etiqueta mt-4">Opciones</p>
        <p v-if="errores.opciones" class="error">{{ errores.opciones }}</p>
        <div v-for="(o, j) in form.opciones" :key="j" class="mb-2">
            <div class="flex flex-wrap gap-2">
                <input v-model="o.tecla" class="campo num h-9 w-14 text-center" maxlength="1" :aria-label="`Tecla de la opción ${j + 1}`" />
                <input v-model="o.etiqueta" class="campo h-9 min-w-0 flex-1" maxlength="60" placeholder="Etiqueta" :aria-label="`Etiqueta de la opción ${j + 1}`" />
                <select v-model="o.accion" class="campo h-9 w-40" :aria-label="`Acción de la opción ${j + 1}`" @change="o.destino = null"><option v-for="(n, k) in acciones" :key="k" :value="k">{{ n }}</option></select>
                <select v-if="o.accion !== 'colgar'" v-model="o.destino" class="campo h-9 w-40" :aria-label="`Destino de la opción ${j + 1}`"><option :value="null" disabled>Destino…</option><option v-for="d in destinos(o.accion)" :key="d.id" :value="d.id">{{ d.nombre }}</option></select>
                <button type="button" class="btn btn-texto btn-sm" :disabled="form.opciones.length === 1" @click="form.opciones.splice(j, 1)">Quitar</button>
            </div>
            <p v-for="c in ['tecla', 'etiqueta', 'accion', 'destino']" v-show="errores[`opciones.${j}.${c}`]" :key="c" class="error">{{ errores[`opciones.${j}.${c}`] }}</p>
        </div>
        <button v-if="form.opciones.length < 10" type="button" class="btn btn-texto btn-sm" @click="form.opciones.push({ tecla: String(form.opciones.length + 1), etiqueta: '', accion: 'cola', destino: null })">Agregar opción</button>

        <div class="mt-4"><Interruptor v-model="conHorario" etiqueta="Atiende solo en horario" /></div>
        <template v-if="conHorario">
            <div class="mt-3 flex flex-wrap gap-2">
                <button v-for="d in 7" :key="d" type="button" class="btn btn-sm" :class="form.horario.dias.includes(d) ? 'btn-oscuro' : 'btn-secundario'" :aria-pressed="form.horario.dias.includes(d)" @click="alternarDia(d)">{{ DIAS_SEMANA[d] }}</button>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <Campo etiqueta="Desde" id="iv-hd" :error="errores['horario.desde']"><input id="iv-hd" v-model="form.horario.desde" type="time" class="campo h-11" /></Campo>
                <Campo etiqueta="Hasta" id="iv-hh" :error="errores['horario.hasta']"><input id="iv-hh" v-model="form.horario.hasta" type="time" class="campo h-11" /></Campo>
            </div>
            <Campo etiqueta="Mensaje fuera de horario" id="iv-fdh" :error="form.errors.fuera_de_horario" class="mt-3"><textarea id="iv-fdh" v-model="form.fuera_de_horario" rows="2" class="campo" maxlength="1000"></textarea></Campo>
        </template>
        <div class="mt-4"><Interruptor v-model="form.activo" etiqueta="Activo" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
