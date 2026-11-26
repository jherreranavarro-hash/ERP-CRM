<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import type { Paginado } from '@/types';
import { fecha, pesos } from '@/lib/formato';
import { estadoFiniquito } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Finiquito {
    id: number; empleado: string; empleado_id: number; causal: string; fecha: string; anios: number; base: number; conceptos: { concepto: string; monto: number; tipo: string }[];
    total: number; estado: string; aviso_previo: boolean; propio: boolean;
}
const props = defineProps<{ finiquitos: Paginado<Finiquito>; empleados: { id: number; nombre: string }[]; causales: Record<string, string>; conIndemnizacion: string[]; estados: Record<string, string> }>();
const puede = usePermisos();
const hoy = new Date().toLocaleDateString('sv-SE');

const abierto = ref(false);
const form = useForm({ empleado_id: null as number | null, causal: '161-1', fecha_termino: hoy, aviso_previo: false });
const calcular = () => form.post('/rrhh/finiquitos', { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset(); } });
const accion = (f: Finiquito, a: 'aprobar' | 'anular') => router.post(`/rrhh/finiquitos/${f.id}/${a}`, {}, { preserveScroll: true });
const firmando = ref<Finiquito | null>(null);
const ff = useForm({ fecha_pago: hoy });
const firmar = () => ff.post(`/rrhh/finiquitos/${firmando.value!.id}/firmar`, { preserveScroll: true, onSuccess: () => { firmando.value = null; } });
const indemniza = (c: string) => props.conIndemnizacion.includes(c);
</script>

<template>
    <Head title="Finiquitos" />
    <Encabezado titulo="Desvinculaciones y finiquitos" bajada="Cálculo del feriado pendiente y proporcional, la indemnización por años de servicio (tope de 11 años y 90 UF) y la sustitutiva del aviso previo según la causal. Lo aprueba otra persona; al aprobar se termina el contrato.">
        <button v-if="puede('rrhh.desvinculaciones-y-finiquitos.crear')" class="btn btn-primario" @click="abierto = true"><Icono nombre="mas" :tamano="18" /> Calcular finiquito</button>
    </Encabezado>

    <div v-if="finiquitos.data.length" class="grid gap-6 xl:grid-cols-2">
        <section v-for="f in finiquitos.data" :key="f.id" class="tarjeta p-5">
            <div class="mb-3 flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-[18px] font-bold">{{ f.empleado }}</h2>
                    <p class="text-[14px] text-grafito">{{ causales[f.causal] }}</p>
                    <p class="text-[14px] text-grafito">Término {{ fecha(f.fecha) }} · {{ f.anios.toLocaleString('es-CL') }} años de servicio · base {{ pesos(f.base) }}<span v-if="indemniza(f.causal)"> · {{ f.aviso_previo ? 'con' : 'sin' }} aviso previo</span></p>
                </div>
                <Chip chico :tono="estadoFiniquito[f.estado][0]">{{ estadoFiniquito[f.estado][1] }}</Chip>
            </div>
            <ul class="divide-y divide-linea border-y border-linea text-[14px]">
                <li v-for="(c, i) in f.conceptos" :key="i" class="flex justify-between gap-3 py-2"><span>{{ c.concepto }}</span><span class="num" :class="{ 'text-estado-error': c.monto < 0 }">{{ pesos(c.monto) }}</span></li>
                <li v-if="!f.conceptos.length" class="py-2 text-grafito">Sin montos a pagar.</li>
            </ul>
            <p class="mt-3 flex justify-between text-[18px] font-bold"><span>Total a pagar</span><span class="num">{{ pesos(f.total) }}</span></p>
            <div class="mt-4 flex flex-wrap gap-2">
                <template v-if="f.estado === 'borrador'">
                    <template v-if="puede('rrhh.desvinculaciones-y-finiquitos.aprobar')">
                        <span v-if="f.propio" class="self-center text-[13px] text-grafito">Lo aprueba otra persona</span>
                        <button v-else class="btn btn-primario btn-sm" @click="accion(f, 'aprobar')">Aprobar y terminar contrato</button>
                    </template>
                    <button v-if="puede('rrhh.desvinculaciones-y-finiquitos.eliminar')" class="btn btn-secundario btn-sm" @click="accion(f, 'anular')">Anular</button>
                </template>
                <button v-if="f.estado === 'aprobado' && puede('rrhh.desvinculaciones-y-finiquitos.editar')" class="btn btn-primario btn-sm" @click="firmando = f">Registrar firma y pago</button>
            </div>
        </section>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin finiquitos" />
    <Paginacion v-if="finiquitos.last_page > 1" :datos="finiquitos" nombre="finiquitos" />

    <Modal :abierto="abierto" titulo="Calcular finiquito" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Persona" id="f-emp" obligatorio :error="form.errors.empleado_id" class="sm:col-span-2">
                <select id="f-emp" v-model="form.empleado_id" class="campo h-11"><option :value="null" disabled>Selecciona…</option><option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Causal" id="f-causal" obligatorio :error="form.errors.causal" class="sm:col-span-2"><select id="f-causal" v-model="form.causal" class="campo h-11"><option v-for="(n, k) in causales" :key="k" :value="k">{{ n }}</option></select></Campo>
            <Campo etiqueta="Fecha de término" id="f-fecha" obligatorio :error="form.errors.fecha_termino"><input id="f-fecha" v-model="form.fecha_termino" type="date" class="campo h-11" /></Campo>
            <div v-if="indemniza(form.causal)" class="self-end pb-2"><Interruptor v-model="form.aviso_previo" etiqueta="Se dio aviso con 30 días" /></div>
        </div>
        <p class="mt-4 text-[14px] text-grafito">Se calcula en borrador para revisión. El descuento de préstamos requiere el acuerdo expreso de la persona.</p>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing || !form.empleado_id" @click="calcular">Calcular</button>
        </template>
    </Modal>

    <Modal :abierto="firmando !== null" titulo="Firma ante ministro de fe y pago" @cerrar="firmando = null">
        <p class="mb-4 text-grafito">{{ firmando?.empleado }} · se contabiliza el pago de {{ pesos(firmando?.total ?? 0) }}.</p>
        <Campo etiqueta="Fecha de pago" id="ff-fecha" obligatorio :error="ff.errors.fecha_pago"><input id="ff-fecha" v-model="ff.fecha_pago" type="date" class="campo h-11" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="firmando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="ff.processing" @click="firmar">Registrar</button>
        </template>
    </Modal>
</template>
