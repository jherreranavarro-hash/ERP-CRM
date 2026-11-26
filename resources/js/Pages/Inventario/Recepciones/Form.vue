<script setup lang="ts">
import { computed, reactive } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Icono from '@/Components/Icono.vue';
import { pesos } from '@/lib/formato';
import { http } from '@/lib/http';

interface ProductoBusqueda { id: number; sku: string; nombre: string; unidad: string }
interface Linea { producto_id: number | null; orden_compra_linea_id?: number | null; sku?: string | null; nombre?: string | null; unidad?: string | null; cantidad: string; costo_unitario: string }
interface Recepcion { id: number; numero: number; local_id: number; proveedor_nombre: string; proveedor_rut: string | null; documento_proveedor: string | null; observaciones: string | null; lineas: Linea[] }
interface LineaOc { orden_compra_linea_id: number; producto_id: number; sku: string | null; nombre: string | null; unidad: string | null; pedida: string; pendiente: string; costo_unitario: string }
interface Orden { id: number; numero: number; estado: string; recibible: boolean; proveedor: string | null; rut: string | null; local_id: number; local: string | null; lineas: LineaOc[] }
const props = defineProps<{ recepcion: Recepcion | null; locales: { id: number; nombre: string }[]; orden?: Orden | null }>();
const r = props.recepcion;
const oc = props.orden ?? null;

const nueva = (): Linea => ({ producto_id: null, cantidad: '', costo_unitario: '' });
// Contra una OC: una línea por línea de la orden, con lo pendiente propuesto.
const desdeOrden = (o: Orden): Linea[] => o.lineas.map((l) => {
    const previa = r?.lineas.find((x) => x.orden_compra_linea_id === l.orden_compra_linea_id);
    return { producto_id: l.producto_id, orden_compra_linea_id: l.orden_compra_linea_id, sku: l.sku, nombre: l.nombre, unidad: l.unidad, cantidad: previa?.cantidad ?? l.pendiente, costo_unitario: previa?.costo_unitario ?? l.costo_unitario };
});
const form = useForm({
    orden_compra_id: oc?.id ?? null,
    local_id: oc?.local_id ?? r?.local_id ?? props.locales[0]?.id ?? null,
    proveedor_nombre: oc?.proveedor ?? r?.proveedor_nombre ?? '',
    proveedor_rut: oc?.rut ?? r?.proveedor_rut ?? '',
    documento_proveedor: r?.documento_proveedor ?? '',
    observaciones: r?.observaciones ?? '',
    lineas: (oc ? desdeOrden(oc) : r?.lineas.map((l) => ({ ...l })) ?? [nueva()]) as Linea[],
});

const busqueda = reactive<Record<number, string>>({});
const resultados = reactive<Record<number, ProductoBusqueda[]>>({});
const timers: Record<number, ReturnType<typeof setTimeout>> = {};
function buscar(i: number, q: string) {
    busqueda[i] = q;
    clearTimeout(timers[i]);
    if (q.trim().length < 2) return void (resultados[i] = []);
    timers[i] = setTimeout(async () => (resultados[i] = (await http.get('/buscar/productos', { params: { q } })).data), 250);
}
function elegir(i: number, p: ProductoBusqueda) {
    Object.assign(form.lineas[i], { producto_id: p.id, sku: p.sku, nombre: p.nombre, unidad: p.unidad });
    busqueda[i] = '';
    resultados[i] = [];
}
const agregar = () => form.lineas.push(nueva());
const quitar = (i: number) => form.lineas.length > 1 && form.lineas.splice(i, 1);
const total = computed(() => form.lineas.reduce((s, l) => s + Math.round((Number(l.cantidad) || 0) * (Number(l.costo_unitario) || 0)), 0));
const err = (k: string) => (form.errors as Record<string, string>)[k];
const guardar = () => (r ? form.put(`/inventario/recepciones/${r.id}`) : form.post('/inventario/recepciones'));
</script>

<template>
    <Head :title="r ? `Recepción N° ${r.numero}` : 'Nueva recepción'" />
    <Encabezado :titulo="r ? `Editar recepción N° ${r.numero}` : oc ? `Recibir orden de compra N° ${oc.numero}` : 'Nueva recepción'" :volver="r ? `/inventario/recepciones/${r.id}` : oc ? `/compras/ordenes/${oc.id}` : '/inventario/recepciones'" :bajada="oc ? 'Indica lo que llegó de cada producto (deja en 0 lo que no llegó). No se recibe más de lo pendiente.' : 'Queda en borrador hasta que la confirmes; recién ahí entra al stock.'" />

    <div v-if="oc && !oc.recibible" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">La orden de compra N° {{ oc.numero }} no está aprobada o ya se recibió completa.</div>
    <div v-if="(form.errors as any).orden_compra_id" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">{{ (form.errors as any).orden_compra_id }}</div>

    <form class="space-y-6" @submit.prevent="guardar">
        <section class="tarjeta grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
            <Campo etiqueta="Local que recibe" id="local" obligatorio :error="form.errors.local_id">
                <select id="local" v-model="form.local_id" class="campo h-11" :disabled="!!oc"><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Proveedor" id="proveedor" obligatorio :error="form.errors.proveedor_nombre"><input id="proveedor" v-model="form.proveedor_nombre" class="campo h-11" maxlength="100" :readonly="!!oc" /></Campo>
            <Campo etiqueta="RUT proveedor" id="rut" :error="form.errors.proveedor_rut"><input id="rut" v-model="form.proveedor_rut" class="campo num h-11" maxlength="12" placeholder="76.123.456-7" :readonly="!!oc" /></Campo>
            <Campo etiqueta="Guía o factura del proveedor" id="doc" :error="form.errors.documento_proveedor"><input id="doc" v-model="form.documento_proveedor" class="campo h-11" maxlength="40" /></Campo>
        </section>

        <section class="tarjeta">
            <div class="overflow-x-auto">
                <table class="tabla">
                    <thead><tr><th class="min-w-[280px]">Producto</th><th v-if="oc" class="w-32 text-right">Pendiente</th><th class="w-36 text-right">{{ oc ? 'Llegó' : 'Cantidad' }}</th><th class="w-44 text-right">Costo unitario neto</th><th class="w-36 text-right">Subtotal</th><th class="w-12"><span class="sr-only">Quitar</span></th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lineas" :key="i" class="align-top">
                            <td>
                                <div v-if="l.producto_id" class="flex items-center justify-between gap-2">
                                    <div><div class="font-semibold">{{ l.nombre }}</div><div class="num text-[13px] text-grafito">{{ l.sku }} · {{ l.unidad }}</div></div>
                                    <button v-if="!oc" type="button" class="text-[14px] underline" @click="l.producto_id = null">Cambiar</button>
                                </div>
                                <div v-else class="relative">
                                    <input :value="busqueda[i] ?? ''" class="campo h-11" placeholder="Buscar por SKU o nombre" :aria-label="`Producto línea ${i + 1}`" @input="buscar(i, ($event.target as HTMLInputElement).value)" />
                                    <ul v-if="resultados[i]?.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                                        <li v-for="p in resultados[i]" :key="p.id"><button type="button" class="w-full px-3 py-2 text-left hover:bg-hormigon" @click="elegir(i, p)"><span class="num text-grafito">{{ p.sku }}</span> {{ p.nombre }}</button></li>
                                    </ul>
                                </div>
                                <p v-if="err(`lineas.${i}.producto_id`)" class="error">{{ err(`lineas.${i}.producto_id`) }}</p>
                            </td>
                            <td v-if="oc" class="num pt-5 text-right text-grafito">{{ oc.lineas[i]?.pendiente }} <span class="text-[13px]">de {{ oc.lineas[i]?.pedida }}</span></td>
                            <td><input v-model="l.cantidad" type="number" min="0" step="any" class="campo num h-11 text-right" :aria-label="`Cantidad línea ${i + 1}`" /><p v-if="err(`lineas.${i}.cantidad`)" class="error">{{ err(`lineas.${i}.cantidad`) }}</p></td>
                            <td><input v-model="l.costo_unitario" type="number" min="0" step="any" class="campo num h-11 text-right" :aria-label="`Costo línea ${i + 1}`" /><p v-if="err(`lineas.${i}.costo_unitario`)" class="error">{{ err(`lineas.${i}.costo_unitario`) }}</p></td>
                            <td class="num pt-5 text-right">{{ pesos(Math.round((Number(l.cantidad) || 0) * (Number(l.costo_unitario) || 0))) }}</td>
                            <td class="pt-3"><button v-if="!oc" type="button" class="p-2 text-grafito hover:text-estado-error" :aria-label="`Quitar línea ${i + 1}`" :disabled="form.lineas.length === 1" @click="quitar(i)"><Icono nombre="cerrar" :tamano="18" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between border-t border-linea p-4">
                <button v-if="!oc" type="button" class="btn btn-secundario" @click="agregar"><Icono nombre="mas" :tamano="18" /> Agregar producto</button>
                <span v-else />
                <div class="text-right"><span class="text-grafito">Costo total</span> <span class="num ml-2 text-[22px] font-bold">{{ pesos(total) }}</span></div>
            </div>
            <p v-if="form.errors.lineas" class="error px-4 pb-4">{{ form.errors.lineas }}</p>
        </section>

        <Campo etiqueta="Observaciones" id="obs" :error="form.errors.observaciones"><textarea id="obs" v-model="form.observaciones" rows="2" class="campo" maxlength="500" /></Campo>

        <div class="flex justify-end gap-3">
            <button type="submit" class="btn btn-primario" :disabled="form.processing">Guardar borrador</button>
        </div>
    </form>
</template>
