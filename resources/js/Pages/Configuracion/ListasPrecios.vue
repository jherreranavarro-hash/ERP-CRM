<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import { fecha, pesos } from '@/lib/formato';
import { http } from '@/lib/http';
import { usePermisos } from '@/lib/permisos';

interface Lista { id: number; codigo: string; nombre: string; regla: 'base' | 'porcentaje' | 'propio'; porcentaje: string | null; activa: boolean; sistema: boolean;
    vigente_desde: string | null; vigente_hasta: string | null; vigente: boolean; clientes: number; productos: number }
interface Item { id: number; producto_id: number; sku: string; nombre: string; meson: string; precio: string }
interface ProductoBusqueda { id: number; sku: string; nombre: string; precio_neto: string }
const props = defineProps<{ listas: Lista[]; reglas: Record<string, string>; seleccionada: { id: number; nombre: string; items: Item[] } | null }>();
const puede = usePermisos();

const editando = ref<Lista | null>(null);
const abierto = ref(false);
const form = useForm({ codigo: '', nombre: '', regla: 'porcentaje', porcentaje: '' as string | number, vigente_desde: '', vigente_hasta: '', activa: true });
function nueva() {
    editando.value = null;
    form.reset();
    form.clearErrors();
    abierto.value = true;
}
function abrir(l: Lista) {
    editando.value = l;
    form.clearErrors();
    Object.assign(form, { codigo: l.codigo, nombre: l.nombre, regla: l.regla === 'base' ? 'porcentaje' : l.regla, porcentaje: l.porcentaje === null ? '' : Number(l.porcentaje),
        vigente_desde: l.vigente_desde ?? '', vigente_hasta: l.vigente_hasta ?? '', activa: l.activa });
    abierto.value = true;
}
function guardar() {
    const f = form.transform((d) => ({ ...d, porcentaje: d.regla === 'porcentaje' ? d.porcentaje : null, vigente_desde: d.vigente_desde || null, vigente_hasta: d.vigente_hasta || null }));
    const op = { preserveScroll: true, onSuccess: () => (abierto.value = false) };
    editando.value ? f.put(`/configuracion/listas-precios/${editando.value.id}`, op) : f.post('/configuracion/listas-precios', op);
}
const eliminar = (l: Lista) => router.delete(`/configuracion/listas-precios/${l.id}`, { preserveScroll: true });
const verPrecios = (l: Lista) => router.get('/configuracion/listas-precios', { lista: l.id }, { preserveScroll: true, preserveState: true });
const esMeson = computed(() => editando.value?.codigo === 'meson');

function regla(l: Lista): string {
    if (l.regla === 'base') return 'Precio neto del producto';
    if (l.regla === 'porcentaje') return `${Number(l.porcentaje)} % bajo mesón`;
    return l.codigo === 'mayorista' ? 'Precio mayorista del producto' : 'Precio propio por producto';
}
function vigencia(l: Lista): string {
    if (!l.vigente_desde && !l.vigente_hasta) return 'Sin límite';
    return `${l.vigente_desde ? fecha(l.vigente_desde) : '…'} al ${l.vigente_hasta ? fecha(l.vigente_hasta) : '…'}`;
}

// Precios propios de la lista seleccionada
const buscando = ref('');
const resultados = ref<ProductoBusqueda[]>([]);
let t: ReturnType<typeof setTimeout>;
function buscar() {
    clearTimeout(t);
    const q = buscando.value.trim();
    if (q.length < 2) return void (resultados.value = []);
    t = setTimeout(async () => (resultados.value = (await http.get('/buscar/productos', { params: { q } })).data), 250);
}
const itemForm = useForm({ producto_id: null as number | null, precio: '' as string | number });
const elegido = ref<ProductoBusqueda | null>(null);
function elegir(p: ProductoBusqueda) {
    elegido.value = p;
    itemForm.producto_id = p.id;
    itemForm.precio = Math.round(Number(p.precio_neto));
    resultados.value = [];
    buscando.value = '';
}
const guardarItem = () => itemForm.post(`/configuracion/listas-precios/${props.seleccionada!.id}/items`, { preserveScroll: true, onSuccess: () => { elegido.value = null; itemForm.reset(); } });
const editarItem = (i: Item) => { elegido.value = { id: i.producto_id, sku: i.sku, nombre: i.nombre, precio_neto: i.meson }; itemForm.producto_id = i.producto_id; itemForm.precio = Number(i.precio); };
const quitarItem = (i: Item) => router.delete(`/configuracion/listas-precios/${props.seleccionada!.id}/items/${i.id}`, { preserveScroll: true });
const variacion = (i: Item) => (Number(i.meson) > 0 ? Math.round(((Number(i.precio) - Number(i.meson)) / Number(i.meson)) * 1000) / 10 : 0);

// Comparador
const compBuscar = ref('');
const compResultados = ref<ProductoBusqueda[]>([]);
const comparacion = ref<{ producto: { sku: string; nombre: string }; precios: { codigo: string; nombre: string; vigente: boolean; precio: string }[] } | null>(null);
let t2: ReturnType<typeof setTimeout>;
function buscarComp() {
    clearTimeout(t2);
    const q = compBuscar.value.trim();
    if (q.length < 2) return void (compResultados.value = []);
    t2 = setTimeout(async () => (compResultados.value = (await http.get('/buscar/productos', { params: { q } })).data), 250);
}
async function comparar(p: ProductoBusqueda) {
    compResultados.value = [];
    compBuscar.value = '';
    comparacion.value = (await http.get('/configuracion/listas-precios/simular', { params: { producto_id: p.id } })).data;
}
</script>

<template>
    <Head title="Listas de precios" />
    <Encabezado titulo="Listas de precios" bajada="El precio de cada línea lo decide el sistema según la lista del cliente. Mesón es la base; las demás se calculan con un porcentaje bajo mesón o con precio propio. Fuera de vigencia, el cliente paga mesón.">
        <button v-if="puede('configuracion.listas-de-precios.crear')" class="btn btn-primario" @click="nueva">Nueva lista</button>
    </Encabezado>

    <section class="tarjeta mb-6 overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Lista</th><th>Regla</th><th>Vigencia</th><th class="text-right">Clientes</th><th class="text-right">Productos con precio</th><th>Estado</th><th class="w-px"></th></tr></thead>
            <tbody>
                <tr v-for="l in listas" :key="l.id">
                    <td><div class="font-semibold">{{ l.nombre }}</div><div class="num text-[13px] text-grafito">{{ l.codigo }}<span v-if="l.sistema"> · del sistema</span></div></td>
                    <td>{{ regla(l) }}</td>
                    <td class="whitespace-nowrap">{{ vigencia(l) }}</td>
                    <td class="num text-right">{{ l.clientes }}</td>
                    <td class="num text-right">{{ l.regla === 'propio' ? l.productos : '—' }}</td>
                    <td><Chip chico :tono="!l.activa ? 'neutro' : l.vigente ? 'ok' : 'alerta'">{{ !l.activa ? 'Inactiva' : l.vigente ? 'Vigente' : 'Fuera de vigencia' }}</Chip></td>
                    <td class="whitespace-nowrap text-right">
                        <div class="flex justify-end gap-2">
                            <button v-if="l.regla === 'propio' && l.codigo !== 'mayorista'" class="btn btn-texto btn-sm" @click="verPrecios(l)">Precios</button>
                            <button v-if="puede('configuracion.listas-de-precios.editar')" class="btn btn-secundario btn-sm" @click="abrir(l)">Editar</button>
                            <MenuAcciones v-if="!l.sistema && puede('configuracion.listas-de-precios.eliminar')" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(l) }]" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="border-t border-linea px-5 py-3 text-[13px] text-grafito">El precio mayorista de cada producto se cambia en Inventario → Actualización de precios, con su flujo de aprobación.</p>
    </section>

    <div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <section v-if="seleccionada" class="tarjeta p-5">
            <h2 class="mb-1 text-[18px] font-bold">Precios propios · {{ seleccionada.nombre }}</h2>
            <p class="mb-4 text-[14px] text-grafito">Productos sin precio aquí se venden a precio mesón en esta lista.</p>
            <div v-if="puede('configuracion.listas-de-precios.editar')" class="mb-4 rounded-boton bg-hormigon p-4">
                <div v-if="!elegido" class="relative">
                    <label for="lp-buscar" class="mb-1 block text-[14px] font-semibold">Agregar producto</label>
                    <input id="lp-buscar" v-model="buscando" class="campo h-11" placeholder="Busca por nombre o SKU" autocomplete="off" @input="buscar" />
                    <ul v-if="resultados.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                        <li v-for="p in resultados" :key="p.id"><button class="flex w-full justify-between gap-3 px-3 py-2 text-left hover:bg-hormigon" @click="elegir(p)"><span><span class="num text-grafito">{{ p.sku }}</span> {{ p.nombre }}</span><span class="num">{{ pesos(Number(p.precio_neto)) }}</span></button></li>
                    </ul>
                </div>
                <div v-else class="flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1"><div class="text-[14px] text-grafito">Producto</div><div class="font-semibold">{{ elegido.nombre }}</div><div class="num text-[13px] text-grafito">Mesón {{ pesos(Number(elegido.precio_neto)) }}</div></div>
                    <Campo etiqueta="Precio neto en la lista" id="lp-precio" :error="itemForm.errors.precio"><input id="lp-precio" v-model="itemForm.precio" inputmode="decimal" class="campo num h-11 w-40 text-right" /></Campo>
                    <button class="btn btn-primario" :disabled="itemForm.processing" @click="guardarItem">Guardar</button>
                    <button class="btn btn-texto" @click="elegido = null">Cancelar</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table v-if="seleccionada.items.length" class="tabla">
                    <thead><tr><th>Producto</th><th class="text-right">Mesón</th><th class="text-right">En la lista</th><th class="text-right">Diferencia</th><th class="w-px"></th></tr></thead>
                    <tbody>
                        <tr v-for="i in seleccionada.items" :key="i.id">
                            <td><div>{{ i.nombre }}</div><div class="num text-[13px] text-grafito">{{ i.sku }}</div></td>
                            <td class="num text-right text-grafito">{{ pesos(Number(i.meson)) }}</td>
                            <td class="num text-right font-semibold">{{ pesos(Number(i.precio)) }}</td>
                            <td class="num text-right" :class="variacion(i) < 0 ? 'text-naranja-profundo' : ''">{{ variacion(i) > 0 ? '+' : '' }}{{ variacion(i).toLocaleString('es-CL') }} %</td>
                            <td class="whitespace-nowrap text-right">
                                <div v-if="puede('configuracion.listas-de-precios.editar')" class="flex justify-end gap-2">
                                    <button class="btn btn-secundario btn-sm" @click="editarItem(i)">Cambiar</button>
                                    <MenuAcciones :acciones="[{ etiqueta: 'Quitar (vuelve a mesón)', peligro: true, accion: () => quitarItem(i) }]" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="py-6 text-center text-grafito">Aún no hay precios propios en esta lista.</p>
            </div>
        </section>
        <section v-else class="tarjeta p-5 text-grafito">
            <h2 class="mb-1 text-[18px] font-bold text-carbon">Precios propios</h2>
            <p>Crea una lista con regla “Precio propio por producto” y pulsa <strong>Precios</strong> para cargarlos.</p>
        </section>

        <section class="tarjeta p-5">
            <h2 class="mb-1 text-[18px] font-bold">Comparar un producto</h2>
            <p class="mb-3 text-[14px] text-grafito">Precio neto que pagaría cada lista hoy.</p>
            <div class="relative">
                <input v-model="compBuscar" class="campo h-11" placeholder="Busca por nombre o SKU" aria-label="Producto a comparar" autocomplete="off" @input="buscarComp" />
                <ul v-if="compResultados.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                    <li v-for="p in compResultados" :key="p.id"><button class="w-full px-3 py-2 text-left hover:bg-hormigon" @click="comparar(p)"><span class="num text-grafito">{{ p.sku }}</span> {{ p.nombre }}</button></li>
                </ul>
            </div>
            <dl v-if="comparacion" class="mt-4">
                <div class="mb-2 font-semibold">{{ comparacion.producto.nombre }}</div>
                <div v-for="p in comparacion.precios" :key="p.codigo" class="flex justify-between border-b border-linea py-2 last:border-0">
                    <dt>{{ p.nombre }} <span v-if="!p.vigente" class="text-[13px] text-grafito">(fuera de vigencia: mesón)</span></dt>
                    <dd class="num font-semibold">{{ pesos(Number(p.precio)) }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <Modal :abierto="abierto" :titulo="editando ? `Editar ${editando.nombre}` : 'Nueva lista de precios'" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Nombre" id="lp-nombre" obligatorio :error="form.errors.nombre"><input id="lp-nombre" v-model="form.nombre" class="campo h-11" maxlength="60" /></Campo>
            <Campo etiqueta="Código" id="lp-codigo" obligatorio :error="form.errors.codigo" ayuda="Minúsculas, sin espacios. No cambia después."><input id="lp-codigo" v-model="form.codigo" class="campo num h-11" maxlength="12" :disabled="!!editando" /></Campo>
            <template v-if="!esMeson">
                <Campo etiqueta="Regla" id="lp-regla" obligatorio :error="form.errors.regla">
                    <select id="lp-regla" v-model="form.regla" class="campo h-11"><option value="porcentaje">% bajo mesón</option><option value="propio">{{ editando?.codigo === 'mayorista' ? 'Precio mayorista del producto' : 'Precio propio por producto' }}</option></select>
                </Campo>
                <Campo v-if="form.regla === 'porcentaje'" etiqueta="% bajo mesón" id="lp-pct" obligatorio :error="form.errors.porcentaje"><input id="lp-pct" v-model="form.porcentaje" type="number" min="0" max="90" step="0.01" class="campo num h-11" /></Campo>
                <Campo etiqueta="Vigente desde" id="lp-desde" :error="form.errors.vigente_desde"><input id="lp-desde" v-model="form.vigente_desde" type="date" class="campo h-11" /></Campo>
                <Campo etiqueta="Vigente hasta" id="lp-hasta" :error="form.errors.vigente_hasta"><input id="lp-hasta" v-model="form.vigente_hasta" type="date" class="campo h-11" /></Campo>
            </template>
        </div>
        <div v-if="!editando?.sistema" class="mt-4"><Interruptor v-model="form.activa" etiqueta="Activa" /></div>
        <p v-if="form.errors.activa" class="mt-2 text-[14px] text-estado-error">{{ form.errors.activa }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
