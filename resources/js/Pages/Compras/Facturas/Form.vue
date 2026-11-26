<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import { pesos, rut } from '@/lib/formato';
import { cantidad } from '@/lib/documentos';
import { http } from '@/lib/http';

interface Proveedor { id: number; razon_social: string; rut: string; plazo_pago_dias: number }
interface LineaOc { orden_compra_linea_id: number; producto_id: number; nombre: string | null; sku: string | null; unidad: string | null; costo_oc: string; por_facturar: string }
interface Orden { id: number; numero: number; proveedor: Proveedor; lineas: LineaOc[] }
const props = defineProps<{ orden: Orden | null; tipos: Record<string, string>; tasaIva: number; tolerancia: number; ordenes: { id: number; numero: number; proveedor: string | null }[] }>();

const proveedor = ref<Proveedor | null>(props.orden?.proveedor ?? null);
const hoy = new Date().toISOString().slice(0, 10);
const form = useForm({
    proveedor_id: props.orden?.proveedor.id ?? (null as number | null),
    orden_compra_id: props.orden?.id ?? null,
    tipo_dte: 33,
    folio: '' as string | number,
    fecha_emision: hoy,
    fecha_vencimiento: '',
    monto_neto: 0,
    monto_exento: 0,
    monto_iva: 0,
    monto_total: 0,
    observaciones: '',
    lineas: (props.orden?.lineas.map((l) => ({ orden_compra_linea_id: l.orden_compra_linea_id, producto_id: l.producto_id, cantidad: l.por_facturar, costo_unitario: l.costo_oc })) ?? []) as { orden_compra_linea_id: number; producto_id: number; cantidad: string; costo_unitario: string }[],
});

// Proveedor (solo factura sin OC)
const qProv = ref('');
const provs = ref<Proveedor[]>([]);
let tp: ReturnType<typeof setTimeout>;
function buscarProveedor(q: string) {
    qProv.value = q;
    clearTimeout(tp);
    if (q.trim().length < 2) return void (provs.value = []);
    tp = setTimeout(async () => (provs.value = (await http.get('/buscar/proveedores', { params: { q } })).data), 250);
}
const elegirProveedor = (p: Proveedor) => { proveedor.value = p; form.proveedor_id = p.id; qProv.value = ''; provs.value = []; };
const elegirOrden = (id: string) => router.get('/compras/facturas/crear', id ? { orden_compra_id: id } : {});

// Montos: se sugieren desde las líneas, pero se registran los que trae el documento.
const sumaLineas = computed(() => form.lineas.reduce((s, l) => s + Math.round((Number(l.cantidad) || 0) * (Number(l.costo_unitario) || 0)), 0));
const exenta = computed(() => Number(form.tipo_dte) === 34);
function recalcular() {
    if (form.lineas.length) {
        form.monto_neto = exenta.value ? 0 : sumaLineas.value;
        form.monto_exento = exenta.value ? sumaLineas.value : 0;
    }
    form.monto_iva = exenta.value ? 0 : Math.round((form.monto_neto * props.tasaIva) / 100);
    form.monto_total = form.monto_neto + form.monto_exento + form.monto_iva;
}
watch(() => [sumaLineas.value, form.tipo_dte], recalcular, { immediate: true });
watch(() => [form.monto_neto, form.monto_exento], () => { form.monto_iva = exenta.value ? 0 : Math.round((form.monto_neto * props.tasaIva) / 100); form.monto_total = form.monto_neto + form.monto_exento + form.monto_iva; });

const vencimientoSugerido = computed(() => {
    if (!proveedor.value || !form.fecha_emision) return '';
    const d = new Date(`${form.fecha_emision}T12:00:00`);
    d.setDate(d.getDate() + proveedor.value.plazo_pago_dias);
    return d.toISOString().slice(0, 10);
});
const desvio = (i: number) => {
    const ref = Number(props.orden?.lineas[i].costo_oc);
    return ref ? ((Number(form.lineas[i].costo_unitario) - ref) / ref) * 100 : 0;
};
const err = (k: string) => (form.errors as Record<string, string>)[k];
const guardar = () => form.post('/compras/facturas');
</script>

<template>
    <Head title="Registrar factura de proveedor" />
    <Encabezado titulo="Registrar factura de proveedor" :volver="orden ? `/compras/ordenes/${orden.id}` : '/compras/facturas'" bajada="Copia los datos del documento. Se compara con la orden de compra y lo recibido antes de pasar a pago." />

    <form class="space-y-6" @submit.prevent="guardar">
        <section class="tarjeta grid gap-4 p-5 md:grid-cols-3">
            <Campo etiqueta="Orden de compra" id="oc" :error="form.errors.orden_compra_id" :ayuda="orden ? undefined : 'Sin orden, la factura siempre pasa por revisión.'">
                <select id="oc" :value="orden?.id ?? ''" class="campo h-11" @change="elegirOrden(($event.target as HTMLSelectElement).value)">
                    <option value="">Sin orden de compra</option>
                    <option v-if="orden && !ordenes.some((x) => x.id === orden!.id)" :value="orden.id">N° {{ orden.numero }} · {{ orden.proveedor.razon_social }}</option>
                    <option v-for="x in ordenes" :key="x.id" :value="x.id">N° {{ x.numero }} · {{ x.proveedor }}</option>
                </select>
            </Campo>
            <Campo etiqueta="Proveedor" id="proveedor" obligatorio :error="form.errors.proveedor_id">
                <div v-if="proveedor" class="flex h-11 items-center justify-between gap-2 rounded-boton border border-linea px-3">
                    <span class="truncate"><span class="font-semibold">{{ proveedor.razon_social }}</span> <span class="num text-grafito">{{ rut(proveedor.rut) }}</span></span>
                    <button v-if="!orden" type="button" class="text-[14px] underline" @click="proveedor = null; form.proveedor_id = null">Cambiar</button>
                </div>
                <div v-else class="relative">
                    <input id="proveedor" :value="qProv" class="campo h-11" placeholder="Buscar por razón social o RUT" @input="buscarProveedor(($event.target as HTMLInputElement).value)" />
                    <ul v-if="provs.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                        <li v-for="p in provs" :key="p.id"><button type="button" class="w-full px-3 py-2 text-left hover:bg-hormigon" @click="elegirProveedor(p)">{{ p.razon_social }} <span class="num text-grafito">{{ rut(p.rut) }}</span></button></li>
                    </ul>
                </div>
            </Campo>
            <Campo etiqueta="Tipo de documento" id="tipo" obligatorio :error="form.errors.tipo_dte">
                <select id="tipo" v-model.number="form.tipo_dte" class="campo h-11"><option v-for="(n, k) in tipos" :key="k" :value="Number(k)">{{ n }}</option></select>
            </Campo>
            <Campo etiqueta="Folio" id="folio" obligatorio :error="form.errors.folio"><input id="folio" v-model="form.folio" type="number" min="1" class="campo num h-11" /></Campo>
            <Campo etiqueta="Fecha de emisión" id="emision" obligatorio :error="form.errors.fecha_emision"><input id="emision" v-model="form.fecha_emision" type="date" :max="hoy" class="campo num h-11" /></Campo>
            <Campo etiqueta="Vencimiento" id="vence" :error="form.errors.fecha_vencimiento" :ayuda="vencimientoSugerido ? `Si lo dejas vacío: ${vencimientoSugerido.split('-').reverse().join('-')} (plazo del proveedor).` : undefined">
                <input id="vence" v-model="form.fecha_vencimiento" type="date" :min="form.fecha_emision" class="campo num h-11" />
            </Campo>
        </section>

        <section v-if="orden" class="tarjeta">
            <div class="border-b border-linea p-4">
                <h2 class="text-[18px] font-bold">Lo que factura, contra la orden N° {{ orden.numero }}</h2>
                <p class="text-[15px] text-grafito">Precio tolerado: ±{{ tolerancia.toLocaleString('es-CL') }} % del de la orden. No se factura más de lo recibido sin revisión.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="tabla">
                    <thead><tr><th>Producto</th><th class="text-right">Recibido sin facturar</th><th class="w-36 text-right">Cantidad</th><th class="w-44 text-right">Costo unitario</th><th class="text-right">Subtotal</th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lineas" :key="l.orden_compra_linea_id" class="align-top">
                            <td><div class="font-semibold">{{ orden.lineas[i].nombre }}</div><div class="num text-[13px] text-grafito">{{ orden.lineas[i].sku }} · OC {{ pesos(Number(orden.lineas[i].costo_oc)) }}</div></td>
                            <td class="num pt-5 text-right">{{ cantidad(orden.lineas[i].por_facturar) }} {{ orden.lineas[i].unidad }}</td>
                            <td>
                                <input v-model="l.cantidad" type="number" min="0" step="any" class="campo num h-11 text-right" :aria-label="`Cantidad ${orden.lineas[i].nombre}`" />
                                <p v-if="Number(l.cantidad) > Number(orden.lineas[i].por_facturar)" class="mt-1 text-right text-[13px] text-[#8A5A00]">Más de lo recibido</p>
                            </td>
                            <td>
                                <input v-model="l.costo_unitario" type="number" min="0" step="any" class="campo num h-11 text-right" :aria-label="`Costo ${orden.lineas[i].nombre}`" />
                                <p v-if="Math.abs(desvio(i)) > tolerancia" class="mt-1 text-right text-[13px] text-[#8A5A00]">{{ desvio(i) > 0 ? '+' : '' }}{{ desvio(i).toFixed(1) }} % vs. OC</p>
                            </td>
                            <td class="num pt-5 text-right">{{ pesos(Math.round((Number(l.cantidad) || 0) * (Number(l.costo_unitario) || 0))) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="err('lineas')" class="error p-4">{{ err('lineas') }}</p>
        </section>

        <section class="tarjeta grid gap-4 p-5 md:grid-cols-4">
            <Campo etiqueta="Neto afecto" id="neto" :error="form.errors.monto_neto"><input id="neto" v-model.number="form.monto_neto" type="number" min="0" :disabled="exenta" class="campo num h-11 text-right" /></Campo>
            <Campo etiqueta="Exento" id="exento" :error="form.errors.monto_exento"><input id="exento" v-model.number="form.monto_exento" type="number" min="0" class="campo num h-11 text-right" /></Campo>
            <Campo :etiqueta="`IVA ${tasaIva} %`" id="iva" :error="form.errors.monto_iva"><input id="iva" v-model.number="form.monto_iva" type="number" min="0" :disabled="exenta" class="campo num h-11 text-right" /></Campo>
            <Campo etiqueta="Total del documento" id="total" obligatorio :error="form.errors.monto_total"><input id="total" v-model.number="form.monto_total" type="number" min="1" class="campo num h-11 text-right text-[18px] font-bold" /></Campo>
            <p v-if="orden && sumaLineas !== form.monto_neto + form.monto_exento" class="text-[15px] text-[#8A5A00] md:col-span-4">Las líneas suman {{ pesos(sumaLineas) }} y el documento declara {{ pesos(form.monto_neto + form.monto_exento) }} sin IVA.</p>
        </section>

        <Campo etiqueta="Observaciones" id="obs" :error="form.errors.observaciones"><textarea id="obs" v-model="form.observaciones" rows="2" class="campo" maxlength="300" /></Campo>
        <div v-if="err('factura') || err('documento')" role="alert" class="tarjeta border-[#F1B5AE] bg-[#FDECEA] p-4 text-[#B42318]">{{ err('factura') || err('documento') }}</div>

        <div class="flex justify-end gap-3">
            <button type="submit" class="btn btn-primario" :disabled="form.processing || !form.proveedor_id">Registrar factura</button>
        </div>
    </form>
</template>
