<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Vacio from '@/Components/Vacio.vue';
import { numero, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Meta { id: number; indicador: string; alcance: string; local_id: number | null; user_id: number | null; quien: string; objetivo: number; logrado: number; pct: number; proyeccion: number | null }
const props = defineProps<{ periodo: string; metas: Meta[]; indicadores: Record<string, string>; alcances: Record<string, string>; locales: { id: number; nombre: string }[];
    vendedores: { id: number; name: string }[]; avanceMes: { dias: number; transcurridos: number }; hayAnterior: boolean }>();
const puede = usePermisos();

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const nombreMes = computed(() => { const [a, m] = props.periodo.split('-').map(Number); return `${MESES[m - 1]} ${a}`; });
function mover(delta: number) {
    const [a, m] = props.periodo.split('-').map(Number);
    const d = new Date(a, m - 1 + delta, 1);
    router.get('/configuracion/metas', { periodo: `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}` }, { preserveScroll: true });
}
const esperado = computed(() => Math.round((props.avanceMes.transcurridos / props.avanceMes.dias) * 100));
const valor = (m: Meta, n: number | null) => (n === null ? '—' : m.indicador === 'venta_neta' ? pesos(n) : numero(n));
function estado(m: Meta): { texto: string; tono: 'ok' | 'alerta' | 'error' | 'info' | 'neutro' } {
    if (m.pct >= 100) return { texto: 'Cumplida', tono: 'ok' };
    if (props.avanceMes.transcurridos === 0) return { texto: 'Por comenzar', tono: 'neutro' };
    if (props.avanceMes.transcurridos >= props.avanceMes.dias) return { texto: 'No cumplida', tono: 'error' };
    if (m.proyeccion !== null && m.proyeccion >= m.objetivo) return { texto: 'En ruta', tono: 'info' };
    return m.pct >= esperado.value * 0.8 ? { texto: 'Algo atrasada', tono: 'alerta' } : { texto: 'Atrasada', tono: 'error' };
}
const grupos = computed(() => Object.entries(props.indicadores).map(([k, nombre]) => ({ k, nombre, metas: props.metas.filter((m) => m.indicador === k) })).filter((g) => g.metas.length));
const cumplidas = computed(() => props.metas.filter((m) => m.pct >= 100).length);
const empresaVenta = computed(() => props.metas.find((m) => m.indicador === 'venta_neta' && m.alcance === 'empresa'));

const abierto = ref(false);
const form = useForm({ periodo: props.periodo, indicador: 'venta_neta', alcance: 'vendedor', local_id: null as number | null, user_id: null as number | null, objetivo: '' as string | number });
function nueva() {
    form.reset();
    form.clearErrors();
    form.periodo = props.periodo;
    abierto.value = true;
}
const guardar = () => form.post('/configuracion/metas', { preserveScroll: true, onSuccess: () => (abierto.value = false) });

const editando = ref<Meta | null>(null);
const objetivo = ref<string | number>('');
function editar(m: Meta) {
    editando.value = m;
    objetivo.value = m.objetivo;
}
const guardarObjetivo = () => router.put(`/configuracion/metas/${editando.value!.id}`, { objetivo: objetivo.value }, { preserveScroll: true, onSuccess: () => (editando.value = null) });
const eliminar = (m: Meta) => router.delete(`/configuracion/metas/${m.id}`, { preserveScroll: true });
const copiar = () => router.post('/configuracion/metas/copiar', { periodo: props.periodo }, { preserveScroll: true });
</script>

<template>
    <Head title="Metas comerciales" />
    <Encabezado titulo="Metas comerciales" bajada="Metas del mes para la empresa, cada local o cada vendedor. El avance se calcula con los documentos emitidos (la nota de crédito resta) y las cotizaciones creadas.">
        <button v-if="hayAnterior && puede('configuracion.meta-admin.crear')" class="btn btn-secundario" @click="copiar">Copiar del mes anterior</button>
        <button v-if="puede('configuracion.meta-admin.crear')" class="btn btn-primario" @click="nueva">Nueva meta</button>
    </Encabezado>

    <div class="tarjeta mb-6 flex flex-wrap items-center gap-3 p-4">
        <button class="btn btn-secundario btn-sm" aria-label="Mes anterior" @click="mover(-1)">←</button>
        <div class="min-w-[160px] text-center text-[18px] font-bold capitalize">{{ nombreMes }}</div>
        <button class="btn btn-secundario btn-sm" aria-label="Mes siguiente" @click="mover(1)">→</button>
        <span class="text-[14px] text-grafito">Día {{ avanceMes.transcurridos }} de {{ avanceMes.dias }} · avance esperado {{ esperado }} %</span>
    </div>

    <div v-if="metas.length" class="mb-6 grid gap-3 sm:grid-cols-3">
        <Kpi etiqueta="Metas del mes" :valor="metas.length" />
        <Kpi etiqueta="Cumplidas" :valor="`${cumplidas} de ${metas.length}`" :tono="cumplidas === metas.length ? 'ok' : 'normal'" />
        <Kpi v-if="empresaVenta" etiqueta="Venta neta de la empresa" :valor="`${empresaVenta.pct.toLocaleString('es-CL')} %`" tono="naranja">{{ pesos(empresaVenta.logrado) }} de {{ pesos(empresaVenta.objetivo) }}</Kpi>
    </div>

    <section v-for="g in grupos" :key="g.k" class="tarjeta mb-6 overflow-x-auto">
        <h2 class="px-5 pt-4 text-[18px] font-bold">{{ g.nombre }}</h2>
        <table class="tabla">
            <thead><tr><th>Para</th><th class="w-[34%]">Avance</th><th class="text-right">Logrado</th><th class="text-right">Meta</th><th class="text-right">Proyección</th><th>Estado</th><th class="w-px"></th></tr></thead>
            <tbody>
                <tr v-for="m in g.metas" :key="m.id">
                    <td><div class="font-semibold">{{ m.quien }}</div><div class="text-[13px] text-grafito">{{ alcances[m.alcance] }}</div></td>
                    <td>
                        <div class="relative h-3 overflow-hidden rounded-full bg-hormigon" role="progressbar" :aria-valuenow="m.pct" aria-valuemin="0" aria-valuemax="100" :aria-label="`Avance ${m.quien}`">
                            <div class="h-full rounded-full" :class="m.pct >= 100 ? 'bg-estado-ok' : 'bg-naranja'" :style="{ width: Math.min(100, m.pct) + '%' }"></div>
                            <div v-if="avanceMes.transcurridos > 0 && avanceMes.transcurridos < avanceMes.dias" class="absolute inset-y-0 w-0.5 bg-carbon/60" :style="{ left: esperado + '%' }" title="Avance esperado a hoy"></div>
                        </div>
                        <div class="num mt-1 text-[13px] text-grafito">{{ m.pct.toLocaleString('es-CL') }} %</div>
                    </td>
                    <td class="num text-right font-semibold">{{ valor(m, m.logrado) }}</td>
                    <td class="num text-right">{{ valor(m, m.objetivo) }}</td>
                    <td class="num text-right text-grafito">{{ valor(m, m.proyeccion) }}</td>
                    <td><Chip chico :tono="estado(m).tono">{{ estado(m).texto }}</Chip></td>
                    <td class="whitespace-nowrap text-right">
                        <div class="flex justify-end gap-2">
                            <button v-if="puede('configuracion.meta-admin.editar')" class="btn btn-secundario btn-sm" @click="editar(m)">Cambiar meta</button>
                            <MenuAcciones v-if="puede('configuracion.meta-admin.eliminar')" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(m) }]" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>
    <div v-if="!metas.length" class="tarjeta">
        <Vacio titulo="Sin metas este mes" :texto="hayAnterior ? 'Puedes copiar las del mes anterior y ajustarlas.' : 'Define la venta neta, documentos o cotizaciones esperadas.'">
            <button v-if="hayAnterior && puede('configuracion.meta-admin.crear')" class="btn btn-secundario" @click="copiar">Copiar del mes anterior</button>
        </Vacio>
    </div>

    <Modal :abierto="abierto" titulo="Nueva meta" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Mes" id="mt-per" obligatorio :error="form.errors.periodo"><input id="mt-per" v-model="form.periodo" type="month" class="campo h-11" /></Campo>
            <Campo etiqueta="Indicador" id="mt-ind" obligatorio :error="form.errors.indicador">
                <select id="mt-ind" v-model="form.indicador" class="campo h-11"><option v-for="(n, k) in indicadores" :key="k" :value="k">{{ n }}</option></select>
            </Campo>
            <Campo etiqueta="Para" id="mt-alc" obligatorio :error="form.errors.alcance">
                <select id="mt-alc" v-model="form.alcance" class="campo h-11"><option v-for="(n, k) in alcances" :key="k" :value="k">{{ n }}</option></select>
            </Campo>
            <Campo v-if="form.alcance === 'local'" etiqueta="Local" id="mt-loc" obligatorio :error="form.errors.local_id">
                <select id="mt-loc" v-model="form.local_id" class="campo h-11"><option :value="null" disabled>Elige</option><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
            </Campo>
            <Campo v-if="form.alcance === 'vendedor'" etiqueta="Vendedor" id="mt-ven" obligatorio :error="form.errors.user_id">
                <select id="mt-ven" v-model="form.user_id" class="campo h-11"><option :value="null" disabled>Elige</option><option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.name }}</option></select>
            </Campo>
            <Campo :etiqueta="form.indicador === 'venta_neta' ? 'Meta en pesos (neto)' : 'Meta (cantidad)'" id="mt-obj" obligatorio :error="form.errors.objetivo"><input id="mt-obj" v-model="form.objetivo" type="number" min="1" class="campo num h-11" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Crear meta</button>
        </template>
    </Modal>

    <Modal :abierto="!!editando" :titulo="`Meta de ${editando?.quien ?? ''}`" @cerrar="editando = null">
        <Campo :etiqueta="editando?.indicador === 'venta_neta' ? 'Meta en pesos (neto)' : 'Meta (cantidad)'" id="mt-edit"><input id="mt-edit" v-model="objetivo" type="number" min="1" class="campo num h-11" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="editando = null">Cancelar</button>
            <button class="btn btn-primario" @click="guardarObjetivo">Guardar</button>
        </template>
    </Modal>
</template>
