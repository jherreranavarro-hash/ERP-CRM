<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Icono from '@/Components/Icono.vue';
import { usePermisos } from '@/lib/permisos';

interface Local { id: number; codigo: string; nombre: string; direccion: string | null; comuna: string | null; telefono: string | null; es_bodega: boolean; activo: boolean; eliminado: boolean }
const props = defineProps<{ locales: Paginado<Local>; filtros: { buscar?: string; estado?: string } }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/configuracion/locales', { ...f }, { preserveState: true, replace: true }), 300); });

const editando = ref<Local | null>(null);
const abierto = ref(false);
const form = useForm({ codigo: '', nombre: '', direccion: '', comuna: '', telefono: '', es_bodega: false, activo: true });

function abrir(l: Local | null) {
    editando.value = l;
    form.clearErrors();
    Object.assign(form, l ? { codigo: l.codigo, nombre: l.nombre, direccion: l.direccion ?? '', comuna: l.comuna ?? '', telefono: l.telefono ?? '', es_bodega: l.es_bodega, activo: l.activo } : { codigo: '', nombre: '', direccion: '', comuna: '', telefono: '', es_bodega: false, activo: true });
    abierto.value = true;
}
function guardar() {
    const op = { preserveScroll: true, onSuccess: () => (abierto.value = false) };
    editando.value ? form.put(`/configuracion/locales/${editando.value.id}`, op) : form.post('/configuracion/locales', op);
}
function eliminar(l: Local) {
    if (confirm(`¿Eliminar el local ${l.nombre}?`)) router.delete(`/configuracion/locales/${l.id}`, { preserveScroll: true });
}
const exportar = () => (window.location.href = `/configuracion/locales?${new URLSearchParams({ ...f, exportar: '1' })}`);
</script>

<template>
    <Head title="Locales" />
    <Encabezado titulo="Locales" bajada="Sucursales y bodegas de la empresa activa.">
        <button v-if="puede('configuracion.local.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <button v-if="puede('configuracion.local.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo local</button>
    </Encabezado>

    <div class="tarjeta">
        <div class="flex flex-wrap gap-3 border-b border-linea p-4">
            <div class="relative min-w-[240px] flex-1">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Buscar por código, nombre o comuna" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11 w-auto" aria-label="Estado">
                <option value="">Vigentes</option>
                <option value="eliminados">Eliminados</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="locales.data.length" class="tabla">
                <thead><tr><th>Código</th><th>Nombre</th><th>Dirección</th><th>Tipo</th><th>Estado</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="l in locales.data" :key="l.id">
                        <td class="font-semibold">{{ l.codigo }}</td>
                        <td>{{ l.nombre }}</td>
                        <td class="text-grafito">{{ [l.direccion, l.comuna].filter(Boolean).join(', ') || '—' }}</td>
                        <td>{{ l.es_bodega ? 'Bodega' : 'Local de venta' }}</td>
                        <td><Chip chico :tono="l.eliminado ? 'error' : l.activo ? 'ok' : 'neutro'">{{ l.eliminado ? 'Eliminado' : l.activo ? 'Activo' : 'Inactivo' }}</Chip></td>
                        <td class="whitespace-nowrap text-right">
                            <button v-if="l.eliminado && puede('configuracion.local.eliminar')" class="btn btn-secundario btn-sm" @click="router.post(`/configuracion/locales/${l.id}/restaurar`, {}, { preserveScroll: true })">Restaurar</button>
                            <div v-else class="flex justify-end gap-2">
                                <button v-if="puede('configuracion.local.editar')" class="btn btn-secundario btn-sm" @click="abrir(l)">Editar</button>
                                <MenuAcciones v-if="puede('configuracion.local.eliminar')" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(l) }]" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin locales" texto="Crea el primer local o bodega de esta empresa." />
        </div>
        <Paginacion :datos="locales" nombre="locales" />
    </div>

    <Modal :abierto="abierto" :titulo="editando ? `Editar ${editando.nombre}` : 'Nuevo local'" @cerrar="abierto = false">
        <form id="form-local" class="grid gap-4 sm:grid-cols-2" @submit.prevent="guardar">
            <Campo etiqueta="Código" id="codigo" :error="form.errors.codigo" obligatorio><input id="codigo" v-model="form.codigo" class="campo h-11 uppercase" /></Campo>
            <Campo etiqueta="Nombre" id="nombre" :error="form.errors.nombre" obligatorio><input id="nombre" v-model="form.nombre" class="campo h-11" /></Campo>
            <Campo etiqueta="Dirección" id="direccion" :error="form.errors.direccion" class="sm:col-span-2"><input id="direccion" v-model="form.direccion" class="campo h-11" /></Campo>
            <Campo etiqueta="Comuna" id="comuna" :error="form.errors.comuna"><input id="comuna" v-model="form.comuna" class="campo h-11" /></Campo>
            <Campo etiqueta="Teléfono" id="telefono" :error="form.errors.telefono"><input id="telefono" v-model="form.telefono" class="campo h-11" /></Campo>
            <div class="flex flex-col gap-3 sm:col-span-2">
                <Interruptor v-model="form.es_bodega" etiqueta="Es bodega (no vende en mesón)" />
                <Interruptor v-model="form.activo" etiqueta="Activo" />
            </div>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button type="submit" form="form-local" class="btn btn-primario" :disabled="form.processing">Guardar</button>
        </template>
    </Modal>
</template>
