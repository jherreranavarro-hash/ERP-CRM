<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Anticipo { id: number; empleado: string; fecha: string; monto: number; estado: string; registrador: string | null }
const props = defineProps<{ periodo: string; anticipos: Anticipo[]; total: number; empleados: { id: number; nombre: string; sueldo: number }[]; topePct: number; estados: Record<string, string> }>();
const puede = usePermisos();
const tono: Record<string, 'alerta' | 'ok' | 'neutro'> = { registrado: 'alerta', descontado: 'ok', anulado: 'neutro' };
const cambiarPeriodo = (p: string) => router.get('/rrhh/anticipos', { periodo: p }, { preserveState: true, replace: true });

const abierto = ref(false);
const form = useForm({ empleado_id: null as number | null, fecha: new Date().toLocaleDateString('sv-SE'), monto: null as number | null });
const tope = computed(() => {
    const e = props.empleados.find((x) => x.id === form.empleado_id);
    return e ? Math.floor((e.sueldo * props.topePct) / 100) : null;
});
const registrar = () => form.post('/rrhh/anticipos', { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset('empleado_id', 'monto'); } });
const anular = (a: Anticipo) => router.post(`/rrhh/anticipos/${a.id}/anular`, {}, { preserveScroll: true });
</script>

<template>
    <Head title="Anticipos" />
    <Encabezado titulo="Anticipos de sueldo (quincena)" :bajada="`Adelantos pagados durante el mes, hasta el ${topePct}% del sueldo base. Se descuentan automáticamente en la liquidación del mes.`">
        <input :value="periodo" type="month" class="campo h-11 w-44" aria-label="Período" @change="cambiarPeriodo(($event.target as HTMLInputElement).value)" />
        <button v-if="puede('rrhh.registrar-anticipo.crear')" class="btn btn-primario" @click="abierto = true"><Icono nombre="mas" :tamano="18" /> Registrar anticipo</button>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Total anticipado en el mes" :valor="pesos(total)" tono="naranja">{{ anticipos.filter((a) => a.estado !== 'anulado').length }} anticipos</Kpi>
    </div>

    <div v-if="anticipos.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Fecha</th><th>Persona</th><th class="text-right">Monto</th><th>Estado</th><th>Registró</th><th></th></tr></thead>
            <tbody>
                <tr v-for="a in anticipos" :key="a.id">
                    <td class="num">{{ fecha(a.fecha) }}</td>
                    <td class="font-semibold">{{ a.empleado }}</td>
                    <td class="num text-right">{{ pesos(a.monto) }}</td>
                    <td><Chip chico :tono="tono[a.estado]">{{ estados[a.estado] }}</Chip></td>
                    <td class="text-grafito">{{ a.registrador }}</td>
                    <td class="text-right"><button v-if="a.estado === 'registrado' && puede('rrhh.registrar-anticipo.eliminar')" class="btn btn-secundario btn-sm" @click="anular(a)">Anular</button></td>
                </tr>
            </tbody>
        </table>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin anticipos en el mes" />

    <Modal :abierto="abierto" titulo="Registrar anticipo" @cerrar="abierto = false">
        <p class="mb-4 text-grafito">Se contabiliza el pago desde el banco y queda pendiente de descuento en la nómina.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Persona" id="an-emp" obligatorio :error="form.errors.empleado_id" class="sm:col-span-2">
                <select id="an-emp" v-model="form.empleado_id" class="campo h-11"><option :value="null" disabled>Selecciona…</option><option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Fecha" id="an-fecha" obligatorio :error="form.errors.fecha"><input id="an-fecha" v-model="form.fecha" type="date" class="campo h-11" /></Campo>
            <Campo etiqueta="Monto" id="an-monto" obligatorio :error="form.errors.monto" :ayuda="tope !== null ? `Máximo ${pesos(tope)} en el mes` : undefined"><input id="an-monto" v-model.number="form.monto" type="number" min="1" class="campo num h-11" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing || !form.empleado_id || !form.monto" @click="registrar">Registrar</button>
        </template>
    </Modal>
</template>
