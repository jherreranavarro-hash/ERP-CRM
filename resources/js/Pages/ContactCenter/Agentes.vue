<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { estadoAgente } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Agente {
    id: number; user_id: number; nombre: string; email: string | null; extension: string; habilidades: string[]; zonas: string[]; capacidad: number; cola_id: number | null;
    cola: string | null; estado: string; activo: boolean; campanas: number[]; campanas_nombres: string[]; abiertos: number;
}
defineProps<{ agentes: Agente[]; usuarios: { id: number; name: string }[]; colas: { id: number; nombre: string }[]; campanas: { id: number; nombre: string }[]; estados: Record<string, string>; soyAgente: boolean }>();
const puede = usePermisos();
const editando = ref<Agente | null>(null);
const abierto = ref(false);
const vacio = () => ({ user_id: null as number | null, extension: '', habilidades: '', zonas: '', capacidad: 50, cola_id: null as number | null, campanas: [] as number[], activo: true });
const form = useForm(vacio());
function abrir(a: Agente | null) {
    editando.value = a;
    form.clearErrors();
    Object.assign(form, a ? { user_id: a.user_id, extension: a.extension, habilidades: a.habilidades.join(', '), zonas: a.zonas.join(', '), capacidad: a.capacidad, cola_id: a.cola_id, campanas: [...a.campanas], activo: a.activo } : vacio());
    abierto.value = true;
}
const lista = (s: string) => s.split(',').map((x) => x.trim()).filter(Boolean);
const guardar = () => {
    const opts = { preserveScroll: true, onSuccess: () => { abierto.value = false; } };
    const f = form.transform((d) => ({ ...d, habilidades: lista(d.habilidades), zonas: lista(d.zonas), user_id: editando.value ? undefined : d.user_id }));
    editando.value ? f.put(`/ventas/agentes/${editando.value.id}`, opts) : f.post('/ventas/agentes', opts);
};
const alternarCampana = (id: number) => { form.campanas = form.campanas.includes(id) ? form.campanas.filter((x) => x !== id) : [...form.campanas, id]; };
</script>

<template>
    <Head title="Agentes" />
    <Encabezado titulo="Agentes" bajada="Personas que atienden el contact center: extensión, habilidades, comunas que cubren (para la asignación por zona), capacidad de prospectos, cola y campañas.">
        <Link v-if="soyAgente" href="/ventas/consola" class="btn btn-secundario no-underline">Mi consola</Link>
        <button v-if="puede('ventascrm.agente.crear') && usuarios.length" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo agente</button>
    </Encabezado>

    <div v-if="agentes.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Agente</th><th>Ext.</th><th>Estado</th><th>Habilidades y zonas</th><th>Cola y campañas</th><th class="text-right">Prospectos</th><th></th></tr></thead>
            <tbody>
                <tr v-for="a in agentes" :key="a.id" :class="{ 'text-grafito': !a.activo }">
                    <td><p class="font-semibold">{{ a.nombre }}</p><p class="text-[13px] text-grafito">{{ a.email }}</p></td>
                    <td class="num">{{ a.extension }}</td>
                    <td><Chip chico :tono="a.activo ? estadoAgente[a.estado][0] : 'neutro'">{{ a.activo ? estadoAgente[a.estado][1] : 'Inactivo' }}</Chip></td>
                    <td class="text-[14px]">
                        <p v-if="a.habilidades.length">{{ a.habilidades.join(', ') }}</p>
                        <p class="text-grafito">{{ a.zonas.length ? a.zonas.join(', ') : 'Todas las zonas' }}</p>
                    </td>
                    <td class="text-[14px]"><p>{{ a.cola ?? 'Sin cola' }}</p><p class="text-grafito">{{ a.campanas_nombres.join(', ') || 'Sin campañas' }}</p></td>
                    <td class="num text-right" :class="{ 'font-semibold text-estado-error': a.abiertos >= a.capacidad }">{{ a.abiertos }} / {{ a.capacidad }}</td>
                    <td class="text-right"><button v-if="puede('ventascrm.agente.editar')" class="btn btn-secundario btn-sm" @click="abrir(a)">Editar</button></td>
                </tr>
            </tbody>
        </table>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin agentes" texto="Registra como agentes a las personas que atienden llamadas o gestionan prospectos." />

    <Modal :abierto="abierto" :titulo="editando ? `Editar ${editando.nombre}` : 'Nuevo agente'" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo v-if="!editando" etiqueta="Persona" id="ag-user" obligatorio :error="form.errors.user_id" class="sm:col-span-2">
                <select id="ag-user" v-model="form.user_id" class="campo h-11"><option :value="null" disabled>Selecciona…</option><option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option></select>
            </Campo>
            <Campo etiqueta="Extensión" id="ag-ext" obligatorio :error="form.errors.extension"><input id="ag-ext" v-model="form.extension" class="campo num h-11" inputmode="numeric" maxlength="6" /></Campo>
            <Campo etiqueta="Capacidad de prospectos" id="ag-cap" obligatorio :error="form.errors.capacidad"><input id="ag-cap" v-model.number="form.capacidad" type="number" min="1" class="campo num h-11" /></Campo>
            <Campo etiqueta="Habilidades" id="ag-hab" ayuda="Separadas por coma: obra gruesa, empresas, inglés…" :error="form.errors.habilidades" class="sm:col-span-2"><input id="ag-hab" v-model="form.habilidades" class="campo h-11" /></Campo>
            <Campo etiqueta="Comunas que atiende" id="ag-zon" ayuda="Para la asignación por zona. Vacío: todas." :error="form.errors.zonas" class="sm:col-span-2"><input id="ag-zon" v-model="form.zonas" class="campo h-11" placeholder="Maipú, Pudahuel" /></Campo>
            <Campo etiqueta="Cola de llamadas entrantes" id="ag-cola" :error="form.errors.cola_id" class="sm:col-span-2">
                <select id="ag-cola" v-model="form.cola_id" class="campo h-11"><option :value="null">No atiende entrantes</option><option v-for="c in colas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
            </Campo>
        </div>
        <p class="etiqueta mt-4">Campañas</p>
        <div v-if="campanas.length" class="flex flex-wrap gap-2">
            <button v-for="c in campanas" :key="c.id" type="button" class="btn btn-sm" :class="form.campanas.includes(c.id) ? 'btn-oscuro' : 'btn-secundario'" :aria-pressed="form.campanas.includes(c.id)" @click="alternarCampana(c.id)">{{ c.nombre }}</button>
        </div>
        <p v-else class="text-[14px] text-grafito">No hay campañas abiertas.</p>
        <div class="mt-4"><Interruptor v-model="form.activo" etiqueta="Activo" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
