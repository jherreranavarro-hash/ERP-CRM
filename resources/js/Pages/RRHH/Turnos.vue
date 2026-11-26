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
import { usePermisos } from '@/lib/permisos';

interface Turno { id: number; nombre: string; hora_inicio: string; hora_fin: string; colacion_min: number; dias: number[]; activo: boolean; personas: number; semanal: number }
defineProps<{ turnos: Turno[]; sinTurno: { id: number; nombre: string; cargo: string }[]; jornadaMaxima: number }>();
const puede = usePermisos();
const DIAS = ['', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];
const editando = ref<Turno | null>(null);
const abierto = ref(false);
const form = useForm({ nombre: '', hora_inicio: '09:00', hora_fin: '18:00', colacion_min: 60, dias: [1, 2, 3, 4, 5] as number[], activo: true });
function abrir(t: Turno | null) {
    editando.value = t;
    form.clearErrors();
    Object.assign(form, t ? { nombre: t.nombre, hora_inicio: t.hora_inicio, hora_fin: t.hora_fin, colacion_min: t.colacion_min, dias: [...t.dias], activo: t.activo }
        : { nombre: '', hora_inicio: '09:00', hora_fin: '18:00', colacion_min: 60, dias: [1, 2, 3, 4, 5], activo: true });
    abierto.value = true;
}
const alternar = (d: number) => { form.dias = form.dias.includes(d) ? form.dias.filter((x) => x !== d) : [...form.dias, d].sort(); };
const opts = { preserveScroll: true, onSuccess: () => { abierto.value = false; } };
const guardar = () => (editando.value ? form.put(`/rrhh/turnos/${editando.value.id}`, opts) : form.post('/rrhh/turnos', opts));

const asignando = ref<Turno | null>(null);
const fa = useForm({ empleados: [] as number[] });
const asignar = () => fa.post(`/rrhh/turnos/${asignando.value!.id}/asignar`, { preserveScroll: true, onSuccess: () => { asignando.value = null; fa.reset(); } });
</script>

<template>
    <Head title="Turnos" />
    <Encabezado titulo="Gestión de turnos" :bajada="`Horarios de trabajo. La asistencia y las horas extra se calculan contra el turno de cada persona. Jornada máxima: ${jornadaMaxima} horas semanales.`">
        <button v-if="puede('rrhh.gestion-de-turnos.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo turno</button>
    </Encabezado>

    <p v-if="sinTurno.length" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6] p-4 text-[#8A5A00]">{{ sinTurno.length === 1 ? '1 persona activa no tiene' : `${sinTurno.length} personas activas no tienen` }} turno: sin turno no se calculan atrasos ni horas extra.</p>

    <div v-if="turnos.length" class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
        <section v-for="t in turnos" :key="t.id" class="tarjeta p-5">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div><h2 class="text-[18px] font-bold">{{ t.nombre }}</h2><p class="num text-[22px] font-bold">{{ t.hora_inicio }} – {{ t.hora_fin }}</p></div>
                <Chip v-if="!t.activo" chico>Inactivo</Chip>
            </div>
            <div class="mb-3 flex gap-1"><span v-for="d in 7" :key="d" class="chip chip-sm" :class="t.dias.includes(d) ? 'bg-carbon text-hormigon' : 'bg-hormigon text-grafito'">{{ DIAS[d] }}</span></div>
            <p class="text-[14px] text-grafito">Colación {{ t.colacion_min }} min · {{ t.semanal.toLocaleString('es-CL') }} h semanales · {{ t.personas }} {{ t.personas === 1 ? 'persona' : 'personas' }}</p>
            <div class="mt-4 flex gap-2">
                <button v-if="puede('rrhh.gestion-de-turnos.editar')" class="btn btn-secundario btn-sm" @click="abrir(t)">Editar</button>
                <button v-if="puede('rrhh.gestion-de-turnos.editar') && sinTurno.length" class="btn btn-secundario btn-sm" @click="asignando = t">Asignar personas</button>
            </div>
        </section>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin turnos" texto="Crea los horarios de la empresa para controlar asistencia y horas extra." />

    <Modal :abierto="abierto" :titulo="editando ? 'Editar turno' : 'Nuevo turno'" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-3">
            <Campo etiqueta="Nombre" id="t-nombre" obligatorio :error="form.errors.nombre" class="sm:col-span-3"><input id="t-nombre" v-model="form.nombre" class="campo h-11" maxlength="60" /></Campo>
            <Campo etiqueta="Entrada" id="t-ini" obligatorio :error="form.errors.hora_inicio"><input id="t-ini" v-model="form.hora_inicio" type="time" class="campo h-11" /></Campo>
            <Campo etiqueta="Salida" id="t-fin" obligatorio :error="form.errors.hora_fin"><input id="t-fin" v-model="form.hora_fin" type="time" class="campo h-11" /></Campo>
            <Campo etiqueta="Colación (min)" id="t-col" obligatorio :error="form.errors.colacion_min"><input id="t-col" v-model.number="form.colacion_min" type="number" min="0" max="120" class="campo h-11" /></Campo>
        </div>
        <p class="etiqueta mt-4">Días</p>
        <div class="flex flex-wrap gap-2"><button v-for="d in 7" :key="d" type="button" class="btn btn-sm" :class="form.dias.includes(d) ? 'btn-oscuro' : 'btn-secundario'" :aria-pressed="form.dias.includes(d)" @click="alternar(d)">{{ DIAS[d] }}</button></div>
        <p v-if="form.errors.dias" class="error">{{ form.errors.dias }}</p>
        <div class="mt-4"><Interruptor v-model="form.activo" etiqueta="Activo" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>

    <Modal :abierto="asignando !== null" :titulo="`Asignar a ${asignando?.nombre ?? ''}`" @cerrar="asignando = null">
        <ul class="max-h-80 divide-y divide-linea overflow-y-auto rounded-boton border border-linea">
            <li v-for="e in sinTurno" :key="e.id"><label class="flex cursor-pointer items-center gap-3 p-3"><input v-model="fa.empleados" type="checkbox" :value="e.id" class="h-5 w-5 accent-naranja" /><span>{{ e.nombre }} <span class="text-grafito">· {{ e.cargo }}</span></span></label></li>
        </ul>
        <p v-if="fa.errors.empleados" class="error">{{ fa.errors.empleados }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="asignando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="fa.processing || !fa.empleados.length" @click="asignar">Asignar</button>
        </template>
    </Modal>
</template>
