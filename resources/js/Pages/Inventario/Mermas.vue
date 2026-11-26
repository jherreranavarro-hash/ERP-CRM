<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { cantidad, estadoMerma } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';
import { http } from '@/lib/http';

interface Fila { id: number; fecha: string; producto: string | null; sku: string | null; unidad: string | null; local: string | null; cantidad: number; motivo: string; detalle: string; costo_total: number; estado: string; responsable: string | null; aprobador: string | null; motivo_rechazo: string | null; propia: boolean }
interface ProductoBusqueda { id: number; sku: string; nombre: string; unidad: string }
const props = defineProps<{ mermas: Paginado<Fila>; filtros: { estado?: string }; motivos: Record<string, string>; locales: { id: number; nombre: string }[]; umbral: number; pendientes: number }>();
const puede = usePermisos();
const page = usePage();
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);

const f = reactive({ estado: props.filtros.estado ?? '' });
watch(f, () => router.get('/inventario/mermas', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }));

// ── Registro ──
const abierto = ref(false);
const form = useForm({ local_id: props.locales[0]?.id ?? null, producto_id: null as number | null, cantidad: '', motivo: 'rotura', detalle: '' });
const producto = ref<ProductoBusqueda | null>(null);
const q = ref('');
const resultados = ref<ProductoBusqueda[]>([]);
let t: ReturnType<typeof setTimeout>;
watch(q, (v) => {
    clearTimeout(t);
    if (v.trim().length < 2) return void (resultados.value = []);
    t = setTimeout(async () => (resultados.value = (await http.get('/buscar/productos', { params: { q: v } })).data), 250);
});
function elegir(p: ProductoBusqueda | null) {
    producto.value = p;
    form.producto_id = p?.id ?? null;
    q.value = '';
    resultados.value = [];
}
const registrar = () => form.post('/inventario/mermas', { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset('cantidad', 'detalle', 'producto_id'); producto.value = null; } });

// ── Aprobación ──
const aprobar = (m: Fila) => confirm(`¿Aprobar la merma de ${pesos(m.costo_total)}? El stock se descuenta.`) && router.post(`/inventario/mermas/${m.id}/aprobar`, {}, { preserveScroll: true });
const rechazando = ref<Fila | null>(null);
const rechazo = useForm({ motivo: '' });
const rechazar = () => rechazo.post(`/inventario/mermas/${rechazando.value!.id}/rechazar`, { preserveScroll: true, onSuccess: () => { rechazando.value = null; rechazo.reset(); } });
</script>

<template>
    <Head title="Mermas" />
    <Encabezado titulo="Mermas" :bajada="`Pérdidas de stock con motivo y responsable. Sobre ${pesos(umbral)} a costo las aprueba otra persona.`">
        <button v-if="puede('inventario.mermas.crear')" class="btn btn-primario" @click="abierto = true"><Icono nombre="mas" :tamano="18" /> Registrar merma</button>
    </Encabezado>

    <button v-if="pendientes" class="tarjeta mb-6 block w-full border-[#F0D58A] bg-[#FFF3D6] p-4 text-left text-[#8A5A00]" @click="f.estado = 'por_aprobar'">
        {{ pendientes === 1 ? 'Hay 1 merma' : `Hay ${pendientes} mermas` }} sobre el umbral esperando aprobación.
    </button>
    <div v-if="errores.length && !abierto && !rechazando" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">
        <ul class="list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul>
    </div>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todas</option><option v-for="(v, k) in estadoMerma" :key="k" :value="k">{{ v[1] }}</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="mermas.data.length" class="tabla">
                <thead><tr><th>Fecha</th><th>Producto</th><th>Local</th><th class="text-right">Cantidad</th><th>Motivo</th><th class="text-right">Costo</th><th>Responsable</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead>
                <tbody>
                    <tr v-for="m in mermas.data" :key="m.id">
                        <td class="num whitespace-nowrap">{{ fechaHora(m.fecha) }}</td>
                        <td><div class="font-semibold">{{ m.producto }}</div><div class="num text-[13px] text-grafito">{{ m.sku }}</div></td>
                        <td>{{ m.local }}</td>
                        <td class="num text-right">{{ cantidad(m.cantidad) }} {{ m.unidad }}</td>
                        <td><div>{{ m.motivo }}</div><div class="text-[13px] text-grafito">{{ m.detalle }}</div></td>
                        <td class="num text-right font-semibold">{{ pesos(m.costo_total) }}</td>
                        <td>{{ m.responsable }}<div v-if="m.aprobador" class="text-[13px] text-grafito">Revisó {{ m.aprobador }}</div></td>
                        <td><Chip chico :tono="estadoMerma[m.estado][0]">{{ estadoMerma[m.estado][1] }}</Chip><div v-if="m.motivo_rechazo" class="text-[13px] text-grafito">{{ m.motivo_rechazo }}</div></td>
                        <td class="whitespace-nowrap text-right">
                            <template v-if="m.estado === 'por_aprobar' && puede('inventario.mermas.aprobar') && !m.propia">
                                <button class="btn btn-secundario h-9 px-3" @click="rechazando = m">Rechazar</button>
                                <button class="btn btn-primario ml-2 h-9 px-3" @click="aprobar(m)">Aprobar</button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin mermas" texto="No hay mermas con estos filtros." />
        </div>
        <Paginacion :datos="mermas" nombre="mermas" />
    </div>

    <Modal :abierto="abierto" titulo="Registrar merma" @cerrar="abierto = false">
        <div class="space-y-4">
            <Campo etiqueta="Local" id="m-local" obligatorio :error="form.errors.local_id">
                <select id="m-local" v-model="form.local_id" class="campo h-11"><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Producto" id="m-producto" obligatorio :error="form.errors.producto_id">
                <div v-if="producto" class="flex items-center justify-between rounded-boton bg-hormigon px-3 py-2">
                    <span><span class="num text-grafito">{{ producto.sku }}</span> {{ producto.nombre }}</span>
                    <button type="button" class="text-[14px] underline" @click="elegir(null)">Cambiar</button>
                </div>
                <div v-else class="relative">
                    <input id="m-producto" v-model="q" class="campo h-11" placeholder="Buscar por SKU o nombre" autocomplete="off" />
                    <ul v-if="resultados.length" class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                        <li v-for="p in resultados" :key="p.id"><button type="button" class="w-full px-3 py-2 text-left hover:bg-hormigon" @click="elegir(p)"><span class="num text-grafito">{{ p.sku }}</span> {{ p.nombre }}</button></li>
                    </ul>
                </div>
            </Campo>
            <div class="grid gap-4 sm:grid-cols-2">
                <Campo etiqueta="Cantidad" id="m-cantidad" obligatorio :error="form.errors.cantidad"><input id="m-cantidad" v-model="form.cantidad" type="number" min="0" step="any" class="campo num h-11" /></Campo>
                <Campo etiqueta="Motivo" id="m-motivo" obligatorio :error="form.errors.motivo"><select id="m-motivo" v-model="form.motivo" class="campo h-11"><option v-for="(n, k) in motivos" :key="k" :value="k">{{ n }}</option></select></Campo>
            </div>
            <Campo etiqueta="Detalle" id="m-detalle" obligatorio :error="form.errors.detalle || (form.errors as any).stock"><textarea id="m-detalle" v-model="form.detalle" rows="2" class="campo" maxlength="300" placeholder="Qué pasó y dónde" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="registrar">Registrar</button>
        </template>
    </Modal>

    <Modal :abierto="!!rechazando" titulo="Rechazar merma" @cerrar="rechazando = null">
        <Campo etiqueta="Motivo del rechazo" id="r-motivo" obligatorio :error="rechazo.errors.motivo || (rechazo.errors as any).merma"><input id="r-motivo" v-model="rechazo.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="rechazando = null">Cancelar</button>
            <button class="btn btn-peligro" :disabled="rechazo.processing" @click="rechazar">Rechazar</button>
        </template>
    </Modal>
</template>
