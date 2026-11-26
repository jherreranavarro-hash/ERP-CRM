<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Icono from '@/Components/Icono.vue';
import { pesos, rut } from '@/lib/formato';
import { http } from '@/lib/http';

interface ProductoBusqueda { id: number; sku: string; nombre: string; unidad: string; costo?: number | string | null }
interface Proveedor { id: number; razon_social: string; rut: string; plazo_pago_dias: number }
interface Linea { producto_id: number | null; sku?: string | null; nombre?: string | null; unidad?: string | null; cantidad: string; costo_unitario: string }
interface Cotizacion { proveedor_nombre: string; monto_neto: number | null; plazo_entrega_dias: number | null; observacion: string; elegida: boolean }
interface Orden { id: number; numero: number; proveedor: Proveedor | null; local_id: number; fecha_entrega: string | null; observaciones: string | null; lineas: Linea[]; cotizaciones: (Omit<Cotizacion, 'observacion'> & { observacion: string | null })[] }
const props = defineProps<{ orden: Orden | null; locales: { id: number; nombre: string }[]; reglas: { aprobacion: number; cotizaciones_desde: number; cotizaciones_minimas: number; tasa_iva: number } }>();
const o = props.orden;

const nueva = (): Linea => ({ producto_id: null, cantidad: '', costo_unitario: '' });
const proveedor = ref<Proveedor | null>(o?.proveedor ?? null);
const form = useForm({
    proveedor_id: o?.proveedor?.id ?? (null as number | null),
    local_id: o?.local_id ?? props.locales[0]?.id ?? null,
    fecha_entrega: o?.fecha_entrega ?? '',
    observaciones: o?.observaciones ?? '',
    lineas: (o?.lineas.map((l) => ({ ...l })) ?? [nueva()]) as Linea[],
    cotizaciones: (o?.cotizaciones.map((c) => ({ ...c, observacion: c.observacion ?? '' })) ?? []) as Cotizacion[],
});

// Proveedor
const qProv = ref('');
const provs = ref<Proveedor[]>([]);
let tp: ReturnType<typeof setTimeout>;
function buscarProveedor(q: string) {
    qProv.value = q;
    clearTimeout(tp);
    if (q.trim().length < 2) return void (provs.value = []);
    tp = setTimeout(async () => (provs.value = (await http.get('/buscar/proveedores', { params: { q } })).data), 250);
}
function elegirProveedor(p: Proveedor) {
    proveedor.value = p;
    form.proveedor_id = p.id;
    qProv.value = '';
    provs.value = [];
}

// Productos
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
    if (!form.lineas[i].costo_unitario && p.costo) form.lineas[i].costo_unitario = String(Math.round(Number(p.costo)));
    busqueda[i] = '';
    resultados[i] = [];
}
const agregar = () => form.lineas.push(nueva());
const quitar = (i: number) => form.lineas.length > 1 && form.lineas.splice(i, 1);

const neto = computed(() => form.lineas.reduce((s, l) => s + Math.round((Number(l.cantidad) || 0) * (Number(l.costo_unitario) || 0)), 0));
const iva = computed(() => Math.round((neto.value * props.reglas.tasa_iva) / 100));
const pideCotizaciones = computed(() => neto.value >= props.reglas.cotizaciones_desde);
const pideAprobacion = computed(() => neto.value > props.reglas.aprobacion);

// Cotizaciones
const agregarCotizacion = () => form.cotizaciones.push({ proveedor_nombre: form.cotizaciones.length ? '' : proveedor.value?.razon_social ?? '', monto_neto: form.cotizaciones.length ? null : neto.value || null, plazo_entrega_dias: null, observacion: '', elegida: !form.cotizaciones.length });
const marcar = (i: number) => form.cotizaciones.forEach((c, j) => (c.elegida = i === j));
const masBarata = computed(() => Math.min(...form.cotizaciones.map((c) => c.monto_neto || Infinity)));

const err = (k: string) => (form.errors as Record<string, string>)[k];
const guardar = () => (o ? form.put(`/compras/ordenes/${o.id}`) : form.post('/compras/ordenes'));
</script>

<template>
    <Head :title="o ? `Orden de compra N° ${o.numero}` : 'Nueva orden de compra'" />
    <Encabezado :titulo="o ? `Editar orden de compra N° ${o.numero}` : 'Nueva orden de compra'" :volver="o ? `/compras/ordenes/${o.id}` : '/compras/ordenes'" bajada="Queda en borrador hasta que la envíes. Al enviarla se aprueba sola o pasa a aprobación según su monto." />

    <form class="space-y-6" @submit.prevent="guardar">
        <section class="tarjeta grid gap-4 p-5 md:grid-cols-3">
            <Campo etiqueta="Proveedor" id="proveedor" obligatorio :error="form.errors.proveedor_id">
                <div v-if="proveedor" class="flex h-11 items-center justify-between gap-2 rounded-boton border border-linea px-3">
                    <span class="truncate"><span class="font-semibold">{{ proveedor.razon_social }}</span> <span class="num text-grafito">{{ rut(proveedor.rut) }}</span></span>
                    <button type="button" class="text-[14px] underline" @click="proveedor = null; form.proveedor_id = null">Cambiar</button>
                </div>
                <div v-else class="relative">
                    <input id="proveedor" :value="qProv" class="campo h-11" placeholder="Buscar por razón social o RUT" @input="buscarProveedor(($event.target as HTMLInputElement).value)" />
                    <ul v-if="provs.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                        <li v-for="p in provs" :key="p.id"><button type="button" class="w-full px-3 py-2 text-left hover:bg-hormigon" @click="elegirProveedor(p)">{{ p.razon_social }} <span class="num text-grafito">{{ rut(p.rut) }}</span></button></li>
                    </ul>
                </div>
            </Campo>
            <Campo etiqueta="Local que recibe" id="local" obligatorio :error="form.errors.local_id">
                <select id="local" v-model="form.local_id" class="campo h-11"><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Entrega comprometida" id="entrega" :error="form.errors.fecha_entrega"><input id="entrega" v-model="form.fecha_entrega" type="date" class="campo num h-11" /></Campo>
        </section>

        <section class="tarjeta">
            <div class="overflow-x-auto">
                <table class="tabla">
                    <thead><tr><th class="min-w-[280px]">Producto</th><th class="w-36 text-right">Cantidad</th><th class="w-44 text-right">Costo unitario neto</th><th class="w-36 text-right">Subtotal</th><th class="w-12"><span class="sr-only">Quitar</span></th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lineas" :key="i" class="align-top">
                            <td>
                                <div v-if="l.producto_id" class="flex items-center justify-between gap-2">
                                    <div><div class="font-semibold">{{ l.nombre }}</div><div class="num text-[13px] text-grafito">{{ l.sku }} · {{ l.unidad }}</div></div>
                                    <button type="button" class="text-[14px] underline" @click="l.producto_id = null">Cambiar</button>
                                </div>
                                <div v-else class="relative">
                                    <input :value="busqueda[i] ?? ''" class="campo h-11" placeholder="Buscar por SKU o nombre" :aria-label="`Producto línea ${i + 1}`" @input="buscar(i, ($event.target as HTMLInputElement).value)" />
                                    <ul v-if="resultados[i]?.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                                        <li v-for="p in resultados[i]" :key="p.id"><button type="button" class="w-full px-3 py-2 text-left hover:bg-hormigon" @click="elegir(i, p)"><span class="num text-grafito">{{ p.sku }}</span> {{ p.nombre }}</button></li>
                                    </ul>
                                </div>
                                <p v-if="err(`lineas.${i}.producto_id`) || err(`lineas.${i}`)" class="error">{{ err(`lineas.${i}.producto_id`) || err(`lineas.${i}`) }}</p>
                            </td>
                            <td><input v-model="l.cantidad" type="number" min="0" step="any" class="campo num h-11 text-right" :aria-label="`Cantidad línea ${i + 1}`" /><p v-if="err(`lineas.${i}.cantidad`)" class="error">{{ err(`lineas.${i}.cantidad`) }}</p></td>
                            <td><input v-model="l.costo_unitario" type="number" min="0" step="any" class="campo num h-11 text-right" :aria-label="`Costo línea ${i + 1}`" /><p v-if="err(`lineas.${i}.costo_unitario`)" class="error">{{ err(`lineas.${i}.costo_unitario`) }}</p></td>
                            <td class="num pt-5 text-right">{{ pesos(Math.round((Number(l.cantidad) || 0) * (Number(l.costo_unitario) || 0))) }}</td>
                            <td class="pt-3"><button type="button" class="p-2 text-grafito hover:text-estado-error" :aria-label="`Quitar línea ${i + 1}`" :disabled="form.lineas.length === 1" @click="quitar(i)"><Icono nombre="cerrar" :tamano="18" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-start justify-between gap-4 border-t border-linea p-4">
                <button type="button" class="btn btn-secundario" @click="agregar"><Icono nombre="mas" :tamano="18" /> Agregar producto</button>
                <dl class="grid grid-cols-[auto_auto] gap-x-6 gap-y-1 text-right">
                    <dt class="text-grafito">Neto</dt><dd class="num">{{ pesos(neto) }}</dd>
                    <dt class="text-grafito">IVA {{ reglas.tasa_iva }} %</dt><dd class="num">{{ pesos(iva) }}</dd>
                    <dt class="font-semibold">Total</dt><dd class="num text-[22px] font-bold">{{ pesos(neto + iva) }}</dd>
                </dl>
            </div>
            <p v-if="form.errors.lineas" class="error px-4 pb-4">{{ form.errors.lineas }}</p>
            <p v-if="pideAprobacion" class="border-t border-linea bg-[#FFF3D6] px-4 py-3 text-[15px] text-[#8A5A00]">Sobre {{ pesos(reglas.aprobacion) }} neto, la orden la aprueba otra persona antes de enviarla al proveedor.</p>
        </section>

        <section class="tarjeta">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-linea p-4">
                <div>
                    <h2 class="text-[18px] font-bold">Cotizaciones comparadas</h2>
                    <p class="text-[15px] text-grafito">
                        <template v-if="pideCotizaciones">Desde {{ pesos(reglas.cotizaciones_desde) }} neto se exigen al menos {{ reglas.cotizaciones_minimas }}. Elegir una que no es la más barata pide aprobación.</template>
                        <template v-else>Opcionales para este monto.</template>
                    </p>
                </div>
                <button type="button" class="btn btn-secundario" :disabled="form.cotizaciones.length >= 5" @click="agregarCotizacion"><Icono nombre="mas" :tamano="18" /> Agregar cotización</button>
            </div>
            <div v-if="form.cotizaciones.length" class="overflow-x-auto">
                <table class="tabla">
                    <thead><tr><th class="w-24">Elegida</th><th>Proveedor</th><th class="w-44 text-right">Monto neto</th><th class="w-32 text-right">Plazo (días)</th><th>Observación</th><th class="w-12"><span class="sr-only">Quitar</span></th></tr></thead>
                    <tbody>
                        <tr v-for="(c, i) in form.cotizaciones" :key="i">
                            <td><input type="radio" name="elegida" :checked="c.elegida" class="h-5 w-5 accent-naranja" :aria-label="`Elegir cotización ${i + 1}`" @change="marcar(i)" /></td>
                            <td><input v-model="c.proveedor_nombre" class="campo h-11" maxlength="100" :aria-label="`Proveedor cotización ${i + 1}`" /></td>
                            <td>
                                <input v-model.number="c.monto_neto" type="number" min="0" class="campo num h-11 text-right" :aria-label="`Monto cotización ${i + 1}`" />
                                <p v-if="c.monto_neto && c.monto_neto === masBarata && form.cotizaciones.length > 1" class="mt-1 text-right text-[13px] text-estado-ok">La más barata</p>
                            </td>
                            <td><input v-model.number="c.plazo_entrega_dias" type="number" min="0" class="campo num h-11 text-right" :aria-label="`Plazo cotización ${i + 1}`" /></td>
                            <td><input v-model="c.observacion" class="campo h-11" maxlength="200" :aria-label="`Observación cotización ${i + 1}`" /></td>
                            <td><button type="button" class="p-2 text-grafito hover:text-estado-error" :aria-label="`Quitar cotización ${i + 1}`" @click="form.cotizaciones.splice(i, 1)"><Icono nombre="cerrar" :tamano="18" /></button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="err('cotizaciones')" class="error p-4">{{ err('cotizaciones') }}</p>
        </section>

        <Campo etiqueta="Observaciones para el proveedor" id="obs" :error="form.errors.observaciones"><textarea id="obs" v-model="form.observaciones" rows="2" class="campo" maxlength="500" /></Campo>

        <div class="flex justify-end gap-3">
            <button type="submit" class="btn btn-primario" :disabled="form.processing">Guardar borrador</button>
        </div>
    </form>
</template>
