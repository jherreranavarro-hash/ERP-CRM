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

interface Plantilla { id: number; nombre: string; cuerpo: string; activa: boolean }
defineProps<{ plantillas: Plantilla[]; variables: string[] }>();
const puede = usePermisos();
const editando = ref<Plantilla | null>(null);
const abierto = ref(false);
const form = useForm({ nombre: '', cuerpo: '', activa: true });
function abrir(p: Plantilla | null) {
    editando.value = p;
    form.clearErrors();
    Object.assign(form, p ? { nombre: p.nombre, cuerpo: p.cuerpo, activa: p.activa } : { nombre: '', cuerpo: '', activa: true });
    abierto.value = true;
}
const opts = { preserveScroll: true, onSuccess: () => { abierto.value = false; } };
const guardar = () => (editando.value ? form.put(`/rrhh/contratos/plantillas/${editando.value.id}`, opts) : form.post('/rrhh/contratos/plantillas', opts));
const insertar = (v: string) => { form.cuerpo += `{{${v}}}`; };
</script>

<template>
    <Head title="Plantillas de contrato" />
    <Encabezado titulo="Plantillas de contrato" bajada="Texto con variables entre llaves dobles que se reemplazan con los datos de la persona y del contrato." volver="/rrhh/contratos">
        <button v-if="puede('rrhh.gestion-de-contratos.editar')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nueva plantilla</button>
    </Encabezado>
    <div v-if="plantillas.length" class="grid gap-6 lg:grid-cols-2">
        <section v-for="p in plantillas" :key="p.id" class="tarjeta flex flex-col">
            <div class="flex items-center justify-between border-b border-linea p-4">
                <h2 class="text-[18px] font-bold">{{ p.nombre }} <Chip v-if="!p.activa" chico>Inactiva</Chip></h2>
                <button v-if="puede('rrhh.gestion-de-contratos.editar')" class="btn btn-secundario btn-sm" @click="abrir(p)">Editar</button>
            </div>
            <pre class="max-h-64 overflow-y-auto whitespace-pre-wrap p-4 font-sans text-[14px] text-grafito">{{ p.cuerpo }}</pre>
        </section>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin plantillas" texto="Crea la plantilla de contrato indefinido y de plazo fijo de la empresa." />

    <Modal :abierto="abierto" :titulo="editando ? 'Editar plantilla' : 'Nueva plantilla'" ancho="xl" @cerrar="abierto = false">
        <div class="grid gap-4">
            <Campo etiqueta="Nombre" id="pl-nombre" obligatorio :error="form.errors.nombre"><input id="pl-nombre" v-model="form.nombre" class="campo h-11" maxlength="80" /></Campo>
            <div class="flex flex-wrap gap-2"><button v-for="v in variables" :key="v" type="button" class="chip chip-sm bg-hormigon text-carbon hover:bg-naranja/15" @click="insertar(v)">{{ v }}</button></div>
            <Campo etiqueta="Texto" id="pl-cuerpo" obligatorio :error="form.errors.cuerpo"><textarea id="pl-cuerpo" v-model="form.cuerpo" rows="14" class="campo py-3" /></Campo>
            <Interruptor v-model="form.activa" etiqueta="Activa" />
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
