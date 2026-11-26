<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Icono from '@/Components/Icono.vue';
import { pesos } from '@/lib/formato';
import { http } from '@/lib/http';

interface ProductoBusqueda { id: number; sku: string; nombre: string; precio_neto: string; precio_mayorista: string | null }
interface Fila { producto: ProductoBusqueda; precio_neto: string; precio_mayorista: string }
const props = defineProps<{ categorias: { id: number; nombre: string }[]; campos: Record<string, string>; umbral: number }>();

const modo = ref<'individual' | 'masivo'>('individual');

// ── Individual ──
const filas = ref<Fila[]>([]);
const q = ref('');
const resultados = ref<ProductoBusqueda[]>([]);
let t: ReturnType<typeof setTimeout>;
watch(q, (v) => {
    clearTimeout(t);
    if (v.trim().length < 2) return void (resultados.value = []);
    t = setTimeout(async () => (resultados.value = (await http.get('/buscar/productos', { params: { q: v } })).data), 250);
});
function agregar(p: ProductoBusqueda) {
    if (!filas.value.some((f) => f.producto.id === p.id)) filas.value.push({ producto: p, precio_neto: String(Number(p.precio_neto)), precio_mayorista: p.precio_mayorista === null ? '' : String(Number(p.precio_mayorista)) });
    q.value = '';
    resultados.value = [];
}
const variacion = (antes: string | null, ahora: string) => (antes !== null && Number(antes) > 0 && ahora !== '' ? ((Number(ahora) - Number(antes)) / Number(antes)) * 100 : null);
const sobre = (v: number | null) => v !== null && Math.abs(v) > props.umbral;
const fmt = (v: number | null) => (v === null ? '—' : `${v.toLocaleString('es-CL', { maximumFractionDigits: 1, signDisplay: 'always' })} %`);

const individual = useForm({ descripcion: '', cambios: [] as { producto_id: number; campo: string; nuevo: string | null }[] });
const requiereAprobacion = computed(() => filas.value.some((f) => sobre(variacion(f.producto.precio_neto, f.precio_neto)) || sobre(variacion(f.producto.precio_mayorista, f.precio_mayorista))));
function guardarIndividual() {
    individual.cambios = filas.value.flatMap((f) => [
        { producto_id: f.producto.id, campo: 'precio_neto', nuevo: f.precio_neto },
        { producto_id: f.producto.id, campo: 'precio_mayorista', nuevo: f.precio_mayorista === '' ? null : f.precio_mayorista },
    ]);
    individual.post('/inventario/precios');
}

// ── Masivo ──
const masivo = useForm({ descripcion: '', categoria_id: '' as number | '', campo: 'precio_neto', porcentaje: '', redondeo: 10 });
const guardarMasivo = () => masivo.transform((d) => ({ ...d, categoria_id: d.categoria_id || null })).post('/inventario/precios/masivo');
const errores = (f: { errors: Record<string, string> }) => Object.entries(f.errors).filter(([k]) => k.startsWith('cambios')).map(([, v]) => v);
</script>

<template>
    <Head title="Nuevo cambio de precios" />
    <Encabezado titulo="Nuevo cambio de precios" volver="/inventario/precios" :bajada="`Variaciones sobre ${umbral.toLocaleString('es-CL')} % o precios bajo el costo quedan por aprobar.`" />

    <div class="mb-6 flex gap-2" role="tablist">
        <button role="tab" :aria-selected="modo === 'individual'" class="btn" :class="modo === 'individual' ? 'btn-primario' : 'btn-secundario'" @click="modo = 'individual'">Por producto</button>
        <button role="tab" :aria-selected="modo === 'masivo'" class="btn" :class="modo === 'masivo' ? 'btn-primario' : 'btn-secundario'" @click="modo = 'masivo'">Ajuste por porcentaje</button>
    </div>

    <form v-if="modo === 'individual'" class="space-y-6" @submit.prevent="guardarIndividual">
        <section class="tarjeta">
            <div class="border-b border-linea p-4">
                <div class="relative max-w-xl">
                    <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                    <input v-model="q" class="campo h-11 pl-10" placeholder="Agregar producto por SKU o nombre" aria-label="Agregar producto" autocomplete="off" />
                    <ul v-if="resultados.length" class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                        <li v-for="p in resultados" :key="p.id"><button type="button" class="w-full px-3 py-2 text-left hover:bg-hormigon" @click="agregar(p)"><span class="num text-grafito">{{ p.sku }}</span> {{ p.nombre }}</button></li>
                    </ul>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table v-if="filas.length" class="tabla">
                    <thead><tr><th>Producto</th><th class="text-right">Mesón actual</th><th class="w-40 text-right">Mesón nuevo</th><th class="text-right">Var.</th><th class="text-right">Mayorista actual</th><th class="w-40 text-right">Mayorista nuevo</th><th class="text-right">Var.</th><th><span class="sr-only">Quitar</span></th></tr></thead>
                    <tbody>
                        <tr v-for="(f, i) in filas" :key="f.producto.id">
                            <td><div class="font-semibold">{{ f.producto.nombre }}</div><div class="num text-[13px] text-grafito">{{ f.producto.sku }}</div></td>
                            <td class="num text-right">{{ pesos(Number(f.producto.precio_neto)) }}</td>
                            <td><input v-model="f.precio_neto" type="number" min="0" step="any" class="campo num h-11 text-right" :aria-label="`Mesón nuevo ${f.producto.sku}`" /></td>
                            <td class="num text-right" :class="sobre(variacion(f.producto.precio_neto, f.precio_neto)) ? 'font-semibold text-[#8A5A00]' : ''">{{ fmt(variacion(f.producto.precio_neto, f.precio_neto)) }}</td>
                            <td class="num text-right">{{ f.producto.precio_mayorista === null ? '—' : pesos(Number(f.producto.precio_mayorista)) }}</td>
                            <td><input v-model="f.precio_mayorista" type="number" min="0" step="any" class="campo num h-11 text-right" :aria-label="`Mayorista nuevo ${f.producto.sku}`" /></td>
                            <td class="num text-right" :class="sobre(variacion(f.producto.precio_mayorista, f.precio_mayorista)) ? 'font-semibold text-[#8A5A00]' : ''">{{ fmt(variacion(f.producto.precio_mayorista, f.precio_mayorista)) }}</td>
                            <td><button type="button" class="p-2 text-grafito hover:text-estado-error" :aria-label="`Quitar ${f.producto.sku}`" @click="filas.splice(i, 1)"><Icono nombre="cerrar" :tamano="18" /></button></td>
                        </tr>
                    </tbody>
                </table>
                <p v-else class="p-6 text-grafito">Busca y agrega los productos cuyo precio quieres cambiar.</p>
            </div>
        </section>
        <Campo etiqueta="Motivo del cambio" id="desc-ind" obligatorio :error="individual.errors.descripcion"><input id="desc-ind" v-model="individual.descripcion" class="campo h-11" maxlength="150" placeholder="Alza de proveedor, liquidación, ajuste de margen…" /></Campo>
        <p v-for="e in errores(individual)" :key="e" class="error">{{ e }}</p>
        <div class="flex items-center justify-end gap-4">
            <span v-if="requiereAprobacion" class="text-[14px] text-[#8A5A00]">Hay variaciones sobre {{ umbral.toLocaleString('es-CL') }} %: quedará por aprobar.</span>
            <button type="submit" class="btn btn-primario" :disabled="individual.processing || !filas.length">{{ requiereAprobacion ? 'Enviar a aprobación' : 'Aplicar cambios' }}</button>
        </div>
    </form>

    <form v-else class="tarjeta grid max-w-3xl gap-4 p-5 md:grid-cols-2" @submit.prevent="guardarMasivo">
        <Campo etiqueta="Categoría" id="cat" :error="masivo.errors.categoria_id">
            <select id="cat" v-model="masivo.categoria_id" class="campo h-11"><option value="">Todo el catálogo</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
        </Campo>
        <Campo etiqueta="Precio" id="campo" :error="masivo.errors.campo">
            <select id="campo" v-model="masivo.campo" class="campo h-11"><option v-for="(n, k) in campos" :key="k" :value="k">{{ n }}</option></select>
        </Campo>
        <Campo etiqueta="Porcentaje" id="pct" obligatorio ayuda="Negativo para rebajar." :error="masivo.errors.porcentaje"><input id="pct" v-model="masivo.porcentaje" type="number" step="0.01" class="campo num h-11" placeholder="5" /></Campo>
        <Campo etiqueta="Redondear a" id="red" :error="masivo.errors.redondeo">
            <select id="red" v-model.number="masivo.redondeo" class="campo h-11"><option :value="1">$1</option><option :value="10">$10</option><option :value="50">$50</option><option :value="100">$100</option></select>
        </Campo>
        <div class="md:col-span-2"><Campo etiqueta="Motivo del cambio" id="desc-mas" obligatorio :error="masivo.errors.descripcion"><input id="desc-mas" v-model="masivo.descripcion" class="campo h-11" maxlength="150" placeholder="Reajuste IPC noviembre" /></Campo></div>
        <p v-if="Math.abs(Number(masivo.porcentaje)) > umbral" class="text-[14px] text-[#8A5A00] md:col-span-2">Supera {{ umbral.toLocaleString('es-CL') }} %: quedará por aprobar.</p>
        <p v-for="e in errores(masivo)" :key="e" class="error md:col-span-2">{{ e }}</p>
        <div class="flex justify-end md:col-span-2"><button type="submit" class="btn btn-primario" :disabled="masivo.processing">Calcular y {{ Math.abs(Number(masivo.porcentaje)) > umbral ? 'enviar a aprobación' : 'aplicar' }}</button></div>
    </form>
</template>
