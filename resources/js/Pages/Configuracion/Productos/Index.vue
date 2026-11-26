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
import { pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Producto { id: number; sku: string; nombre: string; categoria_id: number | null; categoria: string | null; unidad: string; precio_neto: string; precio_mayorista: string | null; costo: string | null; exento: boolean; activo: boolean }
const props = defineProps<{ productos: Paginado<Producto>; categorias: { id: number; nombre: string }[]; filtros: { buscar?: string; categoria?: string }; verCosto: boolean }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', categoria: props.filtros.categoria ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/configuracion/productos', { ...f }, { preserveState: true, replace: true }), 300); });

const vacio = { sku: '', nombre: '', categoria_id: null as number | null, unidad: 'UN', precio_neto: '', precio_mayorista: '', costo: '', exento: false, activo: true };
const editando = ref<Producto | null>(null);
const abierto = ref(false);
const form = useForm({ ...vacio });
function abrir(p: Producto | null) {
    editando.value = p;
    form.clearErrors();
    Object.assign(form, p ? { ...vacio, ...p, precio_mayorista: p.precio_mayorista ?? '', costo: p.costo ?? '' } : vacio);
    abierto.value = true;
}
function guardar() {
    const op = { preserveScroll: true, onSuccess: () => (abierto.value = false) };
    form.transform((d) => ({ ...d, precio_mayorista: d.precio_mayorista === '' ? null : d.precio_mayorista, costo: d.costo === '' ? null : d.costo }));
    editando.value ? form.put(`/configuracion/productos/${editando.value.id}`, op) : form.post('/configuracion/productos', op);
}
function eliminar(p: Producto) {
    if (confirm(`¿Eliminar ${p.nombre}?`)) router.delete(`/configuracion/productos/${p.id}`, { preserveScroll: true });
}
const categoria = useForm({ nombre: '' });
const catAbierto = ref(false);
const exportar = () => (window.location.href = `/configuracion/productos?${new URLSearchParams({ ...f, exportar: '1' })}`);
const precio = (v: string | null) => (v === null ? '—' : pesos(Number(v)));
</script>

<template>
    <Head title="Productos" />
    <Encabezado titulo="Productos" bajada="Catálogo único con precio neto de mesón y mayorista. El precio de boleta se calcula con IVA al vender.">
        <button v-if="puede('configuracion.producto.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <button v-if="puede('configuracion.categoria.crear')" class="btn btn-secundario" @click="catAbierto = true">Nueva categoría</button>
        <button v-if="puede('configuracion.producto.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo producto</button>
    </Encabezado>

    <div class="tarjeta">
        <div class="flex flex-wrap gap-3 border-b border-linea p-4">
            <div class="relative min-w-[240px] flex-1">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Buscar por nombre o SKU" aria-label="Buscar" />
            </div>
            <select v-model="f.categoria" class="campo h-11 w-auto" aria-label="Categoría">
                <option value="">Todas las categorías</option>
                <option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="productos.data.length" class="tabla">
                <thead><tr><th>SKU</th><th>Producto</th><th>Categoría</th><th class="text-right">Neto mesón</th><th class="text-right">Neto mayorista</th><th v-if="verCosto" class="text-right">Costo</th><th>Estado</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="p in productos.data" :key="p.id">
                        <td class="num whitespace-nowrap font-semibold">{{ p.sku }}</td>
                        <td>{{ p.nombre }} <Chip v-if="p.exento" chico tono="info">Exento</Chip></td>
                        <td class="text-grafito">{{ p.categoria ?? '—' }}</td>
                        <td class="num text-right">{{ precio(p.precio_neto) }}</td>
                        <td class="num text-right">{{ precio(p.precio_mayorista) }}</td>
                        <td v-if="verCosto" class="num text-right text-grafito">{{ precio(p.costo) }}</td>
                        <td><Chip chico :tono="p.activo ? 'ok' : 'neutro'">{{ p.activo ? 'Activo' : 'Inactivo' }}</Chip></td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex justify-end gap-2">
                                <button v-if="puede('configuracion.producto.editar')" class="btn btn-secundario btn-sm" @click="abrir(p)">Editar</button>
                                <MenuAcciones v-if="puede('configuracion.producto.eliminar')" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(p) }]" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin productos" texto="Crea el primero o ajusta la búsqueda." />
        </div>
        <Paginacion :datos="productos" nombre="productos" />
    </div>

    <Modal :abierto="abierto" :titulo="editando ? 'Editar producto' : 'Nuevo producto'" ancho="lg" @cerrar="abierto = false">
        <form id="form-producto" class="grid gap-5 sm:grid-cols-2" @submit.prevent="guardar">
            <Campo etiqueta="SKU" id="sku" :error="form.errors.sku" obligatorio><input id="sku" v-model="form.sku" class="campo num h-11" maxlength="40" /></Campo>
            <Campo etiqueta="Nombre" id="nombre" :error="form.errors.nombre" obligatorio ayuda="Máximo 80 caracteres (va en el documento)."><input id="nombre" v-model="form.nombre" class="campo h-11" maxlength="80" /></Campo>
            <Campo etiqueta="Categoría" id="categoria_id" :error="form.errors.categoria_id">
                <select id="categoria_id" v-model="form.categoria_id" class="campo h-11"><option :value="null">Sin categoría</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Unidad" id="unidad" :error="form.errors.unidad"><input id="unidad" v-model="form.unidad" class="campo h-11" maxlength="10" /></Campo>
            <Campo etiqueta="Precio neto mesón" id="precio_neto" :error="form.errors.precio_neto" obligatorio ayuda="Sin IVA."><input id="precio_neto" v-model="form.precio_neto" inputmode="decimal" class="campo num h-11" /></Campo>
            <Campo etiqueta="Precio neto mayorista" id="precio_mayorista" :error="form.errors.precio_mayorista" ayuda="Vacío = mismo de mesón."><input id="precio_mayorista" v-model="form.precio_mayorista" inputmode="decimal" class="campo num h-11" /></Campo>
            <Campo v-if="verCosto" etiqueta="Costo" id="costo" :error="form.errors.costo"><input id="costo" v-model="form.costo" inputmode="decimal" class="campo num h-11" /></Campo>
            <div class="flex flex-col justify-end gap-3 pb-2">
                <Interruptor v-model="form.exento" etiqueta="Exento de IVA" />
                <Interruptor v-model="form.activo" etiqueta="Producto activo" />
            </div>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button type="submit" form="form-producto" class="btn btn-primario" :disabled="form.processing">Guardar</button>
        </template>
    </Modal>

    <Modal :abierto="catAbierto" titulo="Nueva categoría" @cerrar="catAbierto = false">
        <form id="form-cat" @submit.prevent="categoria.post('/configuracion/categorias', { preserveScroll: true, onSuccess: () => { catAbierto = false; categoria.reset(); } })">
            <Campo etiqueta="Nombre" id="cat_nombre" :error="categoria.errors.nombre" obligatorio><input id="cat_nombre" v-model="categoria.nombre" class="campo h-11" maxlength="80" /></Campo>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="catAbierto = false">Cancelar</button>
            <button type="submit" form="form-cat" class="btn btn-primario" :disabled="categoria.processing">Crear</button>
        </template>
    </Modal>
</template>
