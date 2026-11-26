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
import { fechaHora } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Respuesta { texto: string; siguiente: number | null }
interface Paso { titulo: string; texto: string; respuestas: Respuesta[] }
interface Guion { id: number; nombre: string; categoria_id: number | null; categoria: string | null; pasos: Paso[]; version: number; activo: boolean; campanas: number; actualizado: string | null }
defineProps<{ guiones: Guion[]; categorias: { id: number; nombre: string }[] }>();
const puede = usePermisos();
const editando = ref<Guion | null>(null);
const abierto = ref(false);
const nuevoPaso = (): Paso => ({ titulo: '', texto: '', respuestas: [] });
const form = useForm({ nombre: '', categoria_id: null as number | null, pasos: [nuevoPaso()], activo: true });
const errores = form.errors as Record<string, string>;
function abrir(g: Guion | null) {
    editando.value = g;
    form.clearErrors();
    form.nombre = g?.nombre ?? '';
    form.categoria_id = g?.categoria_id ?? null;
    form.pasos = g ? g.pasos.map((p) => ({ titulo: p.titulo, texto: p.texto, respuestas: (p.respuestas ?? []).map((r) => ({ ...r })) })) : [nuevoPaso()];
    form.activo = g?.activo ?? true;
    abierto.value = true;
}
const mover = (i: number, d: number) => { const p = [...form.pasos]; [p[i], p[i + d]] = [p[i + d], p[i]]; form.pasos = p; };
const quitar = (i: number) => { form.pasos = form.pasos.filter((_, j) => j !== i); };
const guardar = () => {
    const opts = { preserveScroll: true, onSuccess: () => { abierto.value = false; } };
    editando.value ? form.put(`/ventas/guiones/${editando.value.id}`, opts) : form.post('/ventas/guiones', opts);
};
</script>

<template>
    <Head title="Guiones" />
    <Encabezado titulo="Guiones" bajada="Guiones de llamada paso a paso, con respuestas que llevan al paso siguiente. Cada cambio en los pasos crea una nueva versión.">
        <button v-if="puede('ventascrm.script.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo guion</button>
    </Encabezado>

    <div v-if="guiones.length" class="grid gap-4 lg:grid-cols-2">
        <article v-for="g in guiones" :key="g.id" class="tarjeta p-5" :class="{ 'opacity-70': !g.activo }">
            <div class="mb-2 flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-[18px] font-bold">{{ g.nombre }}</h2>
                    <p class="text-[13px] text-grafito">Versión {{ g.version }}<template v-if="g.categoria"> · {{ g.categoria }}</template> · {{ g.campanas }} campañas<template v-if="g.actualizado"> · {{ fechaHora(g.actualizado) }}</template></p>
                </div>
                <Chip chico :tono="g.activo ? 'ok' : 'neutro'">{{ g.activo ? 'Activo' : 'Inactivo' }}</Chip>
            </div>
            <ol class="list-decimal space-y-1 pl-5 text-[14px]">
                <li v-for="(p, i) in g.pasos" :key="i">{{ p.titulo }}<span v-if="p.respuestas?.length" class="text-grafito"> · {{ p.respuestas.length }} respuestas</span></li>
            </ol>
            <button v-if="puede('ventascrm.script.editar')" class="btn btn-secundario btn-sm mt-3" @click="abrir(g)">Editar</button>
        </article>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin guiones" texto="Escribe el guion que los agentes leerán durante la llamada." />

    <Modal :abierto="abierto" :titulo="editando ? `Editar ${editando.nombre}` : 'Nuevo guion'" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Nombre" id="g-nom" obligatorio :error="form.errors.nombre"><input id="g-nom" v-model="form.nombre" class="campo h-11" maxlength="100" /></Campo>
            <Campo etiqueta="Categoría de productos" id="g-cat" :error="form.errors.categoria_id"><select id="g-cat" v-model="form.categoria_id" class="campo h-11"><option :value="null">Ninguna</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></Campo>
        </div>
        <p v-if="errores.pasos" class="error mt-2">{{ errores.pasos }}</p>
        <div v-for="(p, i) in form.pasos" :key="i" class="mt-4 rounded-lg border border-linea p-3">
            <div class="mb-2 flex items-center gap-2">
                <strong class="mr-auto">Paso {{ i + 1 }}</strong>
                <button type="button" class="btn btn-texto btn-sm" :disabled="i === 0" @click="mover(i, -1)">Subir</button>
                <button type="button" class="btn btn-texto btn-sm" :disabled="i === form.pasos.length - 1" @click="mover(i, 1)">Bajar</button>
                <button type="button" class="btn btn-texto btn-sm" :disabled="form.pasos.length === 1" @click="quitar(i)">Quitar</button>
            </div>
            <Campo etiqueta="Título" :id="`g-t${i}`" obligatorio :error="errores[`pasos.${i}.titulo`]"><input :id="`g-t${i}`" v-model="p.titulo" class="campo h-10" maxlength="80" /></Campo>
            <Campo etiqueta="Texto" :id="`g-x${i}`" obligatorio :error="errores[`pasos.${i}.texto`]" class="mt-2"><textarea :id="`g-x${i}`" v-model="p.texto" rows="3" class="campo" maxlength="3000"></textarea></Campo>
            <p class="etiqueta mt-2">Respuestas (opcional)</p>
            <div v-for="(r, j) in p.respuestas" :key="j" class="mb-2 flex flex-wrap gap-2">
                <input v-model="r.texto" class="campo h-9 min-w-0 flex-1" placeholder="Ej.: Sí, le interesa" maxlength="80" :aria-label="`Respuesta ${j + 1} del paso ${i + 1}`" />
                <select v-model="r.siguiente" class="campo h-9 w-40" :aria-label="`Destino de la respuesta ${j + 1}`"><option :value="null">Paso siguiente</option><option v-for="(q, k) in form.pasos" :key="k" :value="k">Ir a {{ k + 1 }}. {{ q.titulo || '…' }}</option></select>
                <button type="button" class="btn btn-texto btn-sm" @click="p.respuestas.splice(j, 1)">Quitar</button>
            </div>
            <button v-if="p.respuestas.length < 6" type="button" class="btn btn-texto btn-sm" @click="p.respuestas.push({ texto: '', siguiente: null })">Agregar respuesta</button>
        </div>
        <button v-if="form.pasos.length < 30" type="button" class="btn btn-secundario btn-sm mt-3" @click="form.pasos.push(nuevoPaso())">Agregar paso</button>
        <div class="mt-4"><Interruptor v-model="form.activo" etiqueta="Activo" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
