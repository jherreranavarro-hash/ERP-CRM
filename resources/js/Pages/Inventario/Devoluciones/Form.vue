<script setup lang="ts">
import { computed, reactive } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import { fecha, pesos } from '@/lib/formato';
import { cantidad } from '@/lib/documentos';

interface Linea { id: number; descripcion: string; codigo: string | null; unidad: string; producto_id: number | null; vendida: number; disponible: number; precio: number; monto_item: number }
interface Doc { id: number; nombre: string; fecha: string; cliente: string | null; total: number; acreditable: number; lineas: Linea[] }
const props = defineProps<{ busqueda: { tipo?: string; folio?: string }; error: string | null; documento: Doc | null; motivos: Record<string, string>; umbral: number }>();

const b = reactive({ tipo: props.busqueda.tipo ?? '33', folio: props.busqueda.folio ?? '' });
const buscar = () => router.get('/inventario/devoluciones/crear', b, { preserveState: false });

const form = useForm({
    documento_id: props.documento?.id ?? 0, motivo: 'falla', detalle: '',
    lineas: (props.documento?.lineas ?? []).map((l) => ({ documento_linea_id: l.id, cantidad: '' as string | number, reingresa: true })),
});
const sumaItems = computed(() => props.documento?.lineas.reduce((s, l) => s + l.monto_item, 0) || 1);
const estimado = computed(() => {
    const d = props.documento;
    if (!d) return 0;
    const items = d.lineas.reduce((s, l, i) => s + (l.monto_item * (Number(form.lineas[i].cantidad) || 0)) / l.vendida, 0);
    return Math.min(d.acreditable, Math.round((items * d.total) / sumaItems.value));
});
const err = (k: string) => (form.errors as Record<string, string>)[k];
const erroresLineas = computed(() => Object.entries(form.errors).filter(([k]) => k.startsWith('lineas')).map(([, v]) => v));
</script>

<template>
    <Head title="Nueva devolución" />
    <Encabezado titulo="Nueva devolución" volver="/inventario/devoluciones" bajada="Busca la factura o boleta original y marca lo que el cliente devuelve." />

    <form class="tarjeta mb-6 flex flex-wrap items-end gap-3 p-4" @submit.prevent="buscar">
        <Campo etiqueta="Documento" id="tipo"><select id="tipo" v-model="b.tipo" class="campo h-11"><option value="33">Factura</option><option value="39">Boleta</option><option value="34">Factura exenta</option><option value="41">Boleta exenta</option></select></Campo>
        <Campo etiqueta="Folio" id="folio"><input id="folio" v-model="b.folio" type="number" min="1" class="campo num h-11 w-40" /></Campo>
        <button type="submit" class="btn btn-secundario">Buscar</button>
        <p v-if="error" class="error w-full">{{ error }}</p>
    </form>

    <form v-if="documento" class="space-y-6" @submit.prevent="form.post('/inventario/devoluciones')">
        <section class="tarjeta grid gap-4 p-5 md:grid-cols-3">
            <div><p class="etiqueta">Documento</p><p class="font-semibold">{{ documento.nombre }}</p><p class="num text-grafito">{{ fecha(documento.fecha) }}</p></div>
            <div><p class="etiqueta">Cliente</p><p>{{ documento.cliente ?? 'Consumidor final' }}</p></div>
            <div><p class="etiqueta">Total · saldo acreditable</p><p class="num">{{ pesos(documento.total) }} · {{ pesos(documento.acreditable) }}</p></div>
        </section>

        <section class="tarjeta overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Producto</th><th class="text-right">Vendido</th><th class="text-right">Se puede devolver</th><th class="w-36 text-right">Devuelve</th><th>Vuelve a la venta</th></tr></thead>
                <tbody>
                    <tr v-for="(l, i) in documento.lineas" :key="l.id">
                        <td><div>{{ l.descripcion }}</div><div v-if="l.codigo" class="num text-[13px] text-grafito">{{ l.codigo }}</div></td>
                        <td class="num text-right">{{ cantidad(l.vendida) }} {{ l.unidad }}</td>
                        <td class="num text-right">{{ cantidad(l.disponible) }}</td>
                        <td><input v-model="form.lineas[i].cantidad" type="number" min="0" :max="l.disponible" step="any" class="campo num h-11 text-right" :disabled="l.disponible <= 0" :aria-label="`Cantidad a devolver de ${l.descripcion}`" /></td>
                        <td><label v-if="l.producto_id" class="flex items-center gap-2"><input v-model="form.lineas[i].reingresa" type="checkbox" /> <span class="text-[14px]">{{ form.lineas[i].reingresa ? 'Sí, reingresa al stock' : 'No (dañado)' }}</span></label><span v-else class="text-grafito">—</span></td>
                    </tr>
                </tbody>
            </table>
        </section>
        <p v-for="e in erroresLineas" :key="e" class="error">{{ e }}</p>

        <section class="tarjeta grid gap-4 p-5 md:grid-cols-3">
            <Campo etiqueta="Motivo" id="motivo" obligatorio :error="form.errors.motivo"><select id="motivo" v-model="form.motivo" class="campo h-11"><option v-for="(n, k) in motivos" :key="k" :value="k">{{ n }}</option></select></Campo>
            <div class="md:col-span-2"><Campo etiqueta="Detalle" id="detalle" obligatorio :error="form.errors.detalle"><input id="detalle" v-model="form.detalle" class="campo h-11" maxlength="300" placeholder="Qué pasó con el producto" /></Campo></div>
        </section>
        <p v-if="err('documento')" class="error">{{ err('documento') }}</p>

        <div class="flex items-center justify-end gap-4">
            <span class="text-grafito">Nota de crédito estimada <span class="num ml-1 text-[22px] font-bold text-carbon">{{ pesos(estimado) }}</span></span>
            <button type="submit" class="btn btn-primario" :disabled="form.processing || !estimado">{{ estimado > umbral ? 'Enviar a aprobación' : 'Registrar y emitir NC' }}</button>
        </div>
    </form>
</template>
