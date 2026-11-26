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

interface Numero { id: number; numero: string; descripcion: string | null; proveedor_ip_id: number | null; destino_tipo: 'ivr' | 'cola'; destino_id: number; activo: boolean; proveedor: string | null }
type Op = { id: number; nombre: string };
const props = defineProps<{ numeros: Numero[]; proveedores: Op[]; ivrs: Op[]; colas: Op[] }>();
const puede = usePermisos();
const editando = ref<Numero | null>(null);
const abierto = ref(false);
const vacio = () => ({ numero: '', descripcion: '', proveedor_ip_id: null as number | null, destino_tipo: 'cola' as 'ivr' | 'cola', destino_id: null as number | null, activo: true });
const form = useForm(vacio());
function abrir(n: Numero | null) {
    editando.value = n;
    form.clearErrors();
    Object.assign(form, n ? { numero: n.numero, descripcion: n.descripcion ?? '', proveedor_ip_id: n.proveedor_ip_id, destino_tipo: n.destino_tipo, destino_id: n.destino_id, activo: n.activo } : vacio());
    abierto.value = true;
}
const destinos = (t: string) => (t === 'ivr' ? props.ivrs : props.colas);
const nombreDestino = (n: Numero) => destinos(n.destino_tipo).find((d) => d.id === n.destino_id)?.nombre ?? '—';
const guardar = () => {
    const o = { preserveScroll: true, onSuccess: () => { abierto.value = false; } };
    const f = form.transform((d) => ({ ...d, descripcion: d.descripcion || null }));
    editando.value ? f.put(`/configuracion/telefonia/numeros/${editando.value.id}`, o) : f.post('/configuracion/telefonia/numeros', o);
};
</script>

<template>
    <Head title="Números entrantes" />
    <Encabezado titulo="Números entrantes" bajada="Números que reciben llamadas y adónde van: a un menú de voz o directo a una cola.">
        <button v-if="puede('configuracion.numero-entrante.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo número</button>
    </Encabezado>

    <div v-if="numeros.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Número</th><th>Descripción</th><th>Proveedor</th><th>Destino</th><th>Estado</th><th></th></tr></thead>
            <tbody>
                <tr v-for="n in numeros" :key="n.id">
                    <td class="num font-semibold">{{ n.numero }}</td>
                    <td>{{ n.descripcion ?? '—' }}</td>
                    <td>{{ n.proveedor ?? 'Central simulada' }}</td>
                    <td>{{ n.destino_tipo === 'ivr' ? 'Menú' : 'Cola' }}: {{ nombreDestino(n) }}</td>
                    <td><Chip chico :tono="n.activo ? 'ok' : 'neutro'">{{ n.activo ? 'Activo' : 'Inactivo' }}</Chip></td>
                    <td class="text-right"><button v-if="puede('configuracion.numero-entrante.editar')" class="btn btn-secundario btn-sm" @click="abrir(n)">Editar</button></td>
                </tr>
            </tbody>
        </table>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin números" texto="Registra los números de la empresa para enrutar las llamadas entrantes." />

    <Modal :abierto="abierto" :titulo="editando ? `Editar ${editando.numero}` : 'Nuevo número'" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Número" id="ne-num" obligatorio :error="form.errors.numero" ayuda="Formato internacional: +56222345678."><input id="ne-num" v-model="form.numero" class="campo num h-11" inputmode="tel" maxlength="16" /></Campo>
            <Campo etiqueta="Proveedor" id="ne-prov" :error="form.errors.proveedor_ip_id"><select id="ne-prov" v-model="form.proveedor_ip_id" class="campo h-11"><option :value="null">Central simulada</option><option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option></select></Campo>
            <Campo etiqueta="Descripción" id="ne-desc" :error="form.errors.descripcion" class="sm:col-span-2"><input id="ne-desc" v-model="form.descripcion" class="campo h-11" maxlength="120" placeholder="Ej.: Línea de ventas" /></Campo>
            <Campo etiqueta="Destino" id="ne-dt" obligatorio :error="form.errors.destino_tipo"><select id="ne-dt" v-model="form.destino_tipo" class="campo h-11" @change="form.destino_id = null"><option value="cola">Cola</option><option value="ivr">Menú de voz</option></select></Campo>
            <Campo :etiqueta="form.destino_tipo === 'ivr' ? 'Menú' : 'Cola'" id="ne-did" obligatorio :error="form.errors.destino_id"><select id="ne-did" v-model="form.destino_id" class="campo h-11"><option :value="null" disabled>Selecciona…</option><option v-for="d in destinos(form.destino_tipo)" :key="d.id" :value="d.id">{{ d.nombre }}</option></select></Campo>
        </div>
        <div class="mt-4"><Interruptor v-model="form.activo" etiqueta="Activo" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
