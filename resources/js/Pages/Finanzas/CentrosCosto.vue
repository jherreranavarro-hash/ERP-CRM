<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Icono from '@/Components/Icono.vue';
import { pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Centro { id: number | null; codigo: string; nombre: string; local_id: number | null; local: string | null; activo: boolean; ingresos: number; gastos: number; resultado: number }
const props = defineProps<{ centros: Centro[]; filtros: { desde: string; hasta: string }; locales: { id: number; nombre: string }[] }>();
const puede = usePermisos();
const f = reactive({ ...props.filtros });
watch(f, () => router.get('/finanzas/centros-de-costo', { ...f }, { preserveState: true, replace: true }));
const total = computed(() => props.centros.reduce((s, c) => ({ ingresos: s.ingresos + c.ingresos, gastos: s.gastos + c.gastos }), { ingresos: 0, gastos: 0 }));

const editando = ref<Centro | null>(null);
const abierto = ref(false);
const form = useForm({ codigo: '', nombre: '', local_id: null as number | null, activo: true });
function abrir(c: Centro | null) {
    editando.value = c;
    form.clearErrors();
    Object.assign(form, c ? { codigo: c.codigo, nombre: c.nombre, local_id: c.local_id, activo: c.activo } : { codigo: '', nombre: '', local_id: null, activo: true });
    abierto.value = true;
}
const guardar = () => (editando.value ? form.put(`/finanzas/centros-de-costo/${editando.value.id}`, opts) : form.post('/finanzas/centros-de-costo', opts));
const opts = { preserveScroll: true, onSuccess: () => { abierto.value = false; } };
</script>

<template>
    <Head title="Centros de costo" />
    <Encabezado titulo="Centros de costo" bajada="Resultado por centro según los asientos del período. Las líneas sin centro quedan en «Sin asignar».">
        <input v-model="f.desde" type="date" class="campo h-12 w-auto" aria-label="Desde" />
        <input v-model="f.hasta" type="date" class="campo h-12 w-auto" aria-label="Hasta" />
        <a v-if="puede('finanzas.centro-de-costos.exportar')" :href="`/finanzas/centros-de-costo?desde=${f.desde}&hasta=${f.hasta}&exportar=1`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <button v-if="puede('finanzas.centro-de-costos.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo centro</button>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <Kpi etiqueta="Ingresos" :valor="pesos(total.ingresos)" />
        <Kpi etiqueta="Gastos y costos" :valor="pesos(total.gastos)" />
        <Kpi etiqueta="Resultado" :valor="pesos(total.ingresos - total.gastos)" :tono="total.ingresos - total.gastos < 0 ? 'error' : 'ok'" />
    </div>

    <div class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Código</th><th>Centro</th><th>Local</th><th class="text-right">Ingresos</th><th class="text-right">Gastos</th><th class="text-right">Resultado</th><th></th></tr></thead>
            <tbody>
                <tr v-for="c in centros" :key="c.id ?? 'sin'" :class="c.id === null && 'text-grafito'">
                    <td class="num font-semibold">{{ c.codigo }}</td>
                    <td>{{ c.nombre }} <Chip v-if="!c.activo" chico>Inactivo</Chip></td>
                    <td>{{ c.local ?? '—' }}</td>
                    <td class="num text-right">{{ pesos(c.ingresos) }}</td>
                    <td class="num text-right">{{ pesos(c.gastos) }}</td>
                    <td class="num text-right font-semibold" :class="c.resultado < 0 ? 'text-estado-error' : ''">{{ pesos(c.resultado) }}</td>
                    <td class="text-right"><button v-if="c.id !== null && puede('finanzas.centro-de-costos.editar')" class="btn btn-secundario btn-sm" @click="abrir(c)">Editar</button></td>
                </tr>
            </tbody>
        </table>
    </div>

    <Modal :abierto="abierto" :titulo="editando ? 'Editar centro de costo' : 'Nuevo centro de costo'" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Código" id="cc-codigo" obligatorio :error="form.errors.codigo"><input id="cc-codigo" v-model="form.codigo" class="campo h-11" maxlength="20" /></Campo>
            <Campo etiqueta="Nombre" id="cc-nombre" obligatorio :error="form.errors.nombre"><input id="cc-nombre" v-model="form.nombre" class="campo h-11" maxlength="100" /></Campo>
            <Campo etiqueta="Local asociado" id="cc-local" :error="form.errors.local_id" class="sm:col-span-2">
                <select id="cc-local" v-model="form.local_id" class="campo h-11"><option :value="null">Ninguno</option><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
            </Campo>
        </div>
        <div class="mt-4"><Interruptor v-model="form.activo" etiqueta="Activo" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
