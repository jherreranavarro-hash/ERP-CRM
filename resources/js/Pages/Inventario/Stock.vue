<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { pesos } from '@/lib/formato';
import { cantidad } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; sku: string; nombre: string; unidad: string; fisico: number; reservado: number; disponible: number; costo: number; valorizado: number; minimo: number | null }
const props = defineProps<{ productos: Paginado<Fila>; locales: { id: number; nombre: string }[]; filtros: { local_id: number | null; buscar?: string; filtro?: string }; resumen: { valorizado: number; bajo_minimo: number } }>();
const puede = usePermisos();

const f = reactive({ local_id: String(props.filtros.local_id ?? ''), buscar: props.filtros.buscar ?? '', filtro: props.filtros.filtro ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/inventario/stock', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
const exportar = () => (window.location.href = `/inventario/stock?${new URLSearchParams({ ...f, exportar: '1' })}`);

const editando = ref<Fila | null>(null);
const minimo = useForm({ local_id: 0, minimo: '' as string | number });
function editarMinimo(p: Fila) {
    editando.value = p;
    minimo.local_id = Number(f.local_id);
    minimo.minimo = p.minimo ?? '';
}
const guardarMinimo = () => minimo.transform((d) => ({ ...d, minimo: d.minimo === '' ? null : d.minimo })).post(`/inventario/stock/${editando.value!.id}/minimo`, { preserveScroll: true, onSuccess: () => (editando.value = null) });
const bajo = (p: Fila) => p.minimo !== null && p.fisico <= p.minimo;
</script>

<template>
    <Head title="Stock" />
    <Encabezado titulo="Stock" bajada="Existencias por local. Disponible = físico menos lo reservado por órdenes de venta vigentes.">
        <button v-if="puede('inventario.stock.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <Link v-if="puede('inventario.recepcion-de-mercaderia.crear')" href="/inventario/recepciones/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Recibir mercadería</Link>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2">
        <Kpi etiqueta="Inventario valorizado" :valor="pesos(resumen.valorizado)" tono="naranja">A costo promedio ponderado</Kpi>
        <Kpi etiqueta="Bajo el mínimo" :valor="resumen.bajo_minimo" :tono="resumen.bajo_minimo ? 'error' : 'ok'">
            <button v-if="resumen.bajo_minimo" class="underline" @click="f.filtro = 'bajo'">Ver productos</button><span v-else>Todo sobre el mínimo</span>
        </Kpi>
    </div>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-2 xl:grid-cols-4">
            <select v-model="f.local_id" class="campo h-11" aria-label="Local"><option v-for="l in locales" :key="l.id" :value="String(l.id)">{{ l.nombre }}</option></select>
            <div class="relative xl:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="SKU o nombre" aria-label="Buscar" />
            </div>
            <select v-model="f.filtro" class="campo h-11" aria-label="Filtro"><option value="">Todos los productos</option><option value="bajo">Bajo el mínimo</option><option value="sin">Sin disponible</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="productos.data.length" class="tabla">
                <thead><tr><th>Producto</th><th class="text-right">Físico</th><th class="text-right">Reservado</th><th class="text-right">Disponible</th><th class="text-right">Mínimo</th><th class="text-right">Costo prom.</th><th class="text-right">Valorizado</th></tr></thead>
                <tbody>
                    <tr v-for="p in productos.data" :key="p.id" class="hover:bg-hormigon">
                        <td>
                            <Link :href="`/inventario/stock/${p.id}/kardex?local_id=${f.local_id}`" class="font-semibold">{{ p.nombre }}</Link>
                            <div class="num text-[13px] text-grafito">{{ p.sku }}</div>
                        </td>
                        <td class="num text-right">{{ cantidad(p.fisico) }} {{ p.unidad }}</td>
                        <td class="num text-right">{{ p.reservado ? cantidad(p.reservado) : '—' }}</td>
                        <td class="num text-right font-semibold" :class="p.disponible <= 0 ? 'text-estado-error' : ''">{{ cantidad(p.disponible) }}</td>
                        <td class="num text-right">
                            <button v-if="puede('inventario.stock.editar')" class="underline decoration-dotted" :aria-label="`Mínimo de ${p.nombre}`" @click="editarMinimo(p)">{{ p.minimo === null ? 'Definir' : cantidad(p.minimo) }}</button>
                            <span v-else>{{ p.minimo === null ? '—' : cantidad(p.minimo) }}</span>
                            <Chip v-if="bajo(p)" chico tono="error" class="ml-1">Bajo</Chip>
                        </td>
                        <td class="num text-right">{{ pesos(Math.round(p.costo)) }}</td>
                        <td class="num text-right">{{ pesos(p.valorizado) }}</td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin productos" texto="No hay productos con estos filtros." />
        </div>
        <Paginacion :datos="productos" nombre="productos" />
    </div>

    <Modal :abierto="!!editando" :titulo="`Stock mínimo · ${editando?.nombre ?? ''}`" @cerrar="editando = null">
        <Campo etiqueta="Mínimo en este local" id="minimo" ayuda="Déjalo vacío para no controlar el mínimo." :error="minimo.errors.minimo">
            <input id="minimo" v-model="minimo.minimo" type="number" min="0" step="any" class="campo h-11" />
        </Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="editando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="minimo.processing" @click="guardarMinimo">Guardar</button>
        </template>
    </Modal>
</template>
