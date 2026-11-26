<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Vacio from '@/Components/Vacio.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, numero } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Rango { id: number; tipo_dte: number; tipo: string; desde: number; hasta: number; siguiente: number; restantes: number; caf_referencia: string | null; vence: string | null; activo: boolean }
defineProps<{ rangos: Rango[]; tipos: Record<string, string> }>();
const puede = usePermisos();

const abierto = ref(false);
const form = useForm({ tipo_dte: 33, desde: null as number | null, hasta: null as number | null, caf_referencia: '', vence: '' });
function guardar() {
    form.post('/configuracion/folios', { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset(); } });
}
function desactivar(r: Rango) {
    if (confirm(`¿Desactivar el rango ${r.desde}–${r.hasta}? No se usará para nuevas emisiones.`)) router.post(`/configuracion/folios/${r.id}/desactivar`, {}, { preserveScroll: true });
}
const tono = (r: Rango) => (!r.activo || r.restantes === 0 ? 'neutro' : r.restantes <= 100 ? 'alerta' : 'ok');
const estado = (r: Rango) => (!r.activo ? 'Inactivo' : r.restantes === 0 ? 'Agotado' : r.restantes <= 100 ? 'Quedan pocos' : 'Vigente');
</script>

<template>
    <Head title="Folios" />
    <Encabezado titulo="Folios (CAF)" bajada="Rangos autorizados por el SII. El folio se asigna solo al emitir, en la misma transacción; los rangos no se pueden solapar.">
        <button v-if="puede('configuracion.folios.crear')" class="btn btn-primario" @click="abierto = true"><Icono nombre="mas" :tamano="18" /> Cargar rango</button>
    </Encabezado>

    <div class="tarjeta overflow-x-auto">
        <table v-if="rangos.length" class="tabla">
            <thead><tr><th>Documento</th><th class="text-right">Desde</th><th class="text-right">Hasta</th><th class="text-right">Próximo</th><th class="text-right">Quedan</th><th>Vence</th><th>Estado</th><th class="w-px"></th></tr></thead>
            <tbody>
                <tr v-for="r in rangos" :key="r.id">
                    <td><div class="font-semibold">{{ r.tipo }}</div><div v-if="r.caf_referencia" class="text-[13px] text-grafito">{{ r.caf_referencia }}</div></td>
                    <td class="num text-right">{{ numero(r.desde) }}</td>
                    <td class="num text-right">{{ numero(r.hasta) }}</td>
                    <td class="num text-right">{{ r.restantes ? numero(r.siguiente) : '—' }}</td>
                    <td class="num text-right font-semibold">{{ numero(r.restantes) }}</td>
                    <td class="num">{{ fecha(r.vence) }}</td>
                    <td><Chip chico :tono="tono(r)">{{ estado(r) }}</Chip></td>
                    <td class="text-right"><button v-if="r.activo && puede('configuracion.folios.crear')" class="btn btn-secundario btn-sm" @click="desactivar(r)">Desactivar</button></td>
                </tr>
            </tbody>
        </table>
        <Vacio v-else titulo="Sin rangos de folios" texto="Carga el rango que entregó el SII (o tu proveedor DTE) para poder emitir." />
    </div>

    <Modal :abierto="abierto" titulo="Cargar rango de folios" @cerrar="abierto = false">
        <form id="form-folios" class="grid gap-5 sm:grid-cols-2" @submit.prevent="guardar">
            <Campo class="sm:col-span-2" etiqueta="Tipo de documento" id="tipo_dte" :error="form.errors.tipo_dte">
                <select id="tipo_dte" v-model.number="form.tipo_dte" class="campo h-11"><option v-for="(n, t) in tipos" :key="t" :value="Number(t)">{{ n }} ({{ t }})</option></select>
            </Campo>
            <Campo etiqueta="Desde" id="desde" :error="form.errors.desde" obligatorio><input id="desde" v-model.number="form.desde" type="number" min="1" class="campo num h-11" /></Campo>
            <Campo etiqueta="Hasta" id="hasta" :error="form.errors.hasta" obligatorio><input id="hasta" v-model.number="form.hasta" type="number" min="1" class="campo num h-11" /></Campo>
            <Campo etiqueta="Referencia del CAF" id="caf" :error="form.errors.caf_referencia" ayuda="Identificador del archivo; el CAF lo custodia el proveedor DTE."><input id="caf" v-model="form.caf_referencia" class="campo h-11" /></Campo>
            <Campo etiqueta="Vence" id="vence" :error="form.errors.vence"><input id="vence" v-model="form.vence" type="date" class="campo h-11" /></Campo>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button type="submit" form="form-folios" class="btn btn-primario" :disabled="form.processing">Cargar</button>
        </template>
    </Modal>
</template>
