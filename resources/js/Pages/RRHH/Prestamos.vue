<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import type { Paginado } from '@/types';
import { pesos } from '@/lib/formato';
import { estadoPrestamo } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Prestamo { id: number; empleado: string; empleado_id: number; monto: number; cuotas: number; valor_cuota: number; saldo: number; primer_periodo: string; motivo: string; estado: string; solicitante: string | null; propio: boolean }
const props = defineProps<{
    prestamos: Paginado<Prestamo>; empleados: { id: number; nombre: string }[]; filtros: { estado?: string }; estados: Record<string, string>;
    totales: { vigente: number; por_aprobar: number }; topePct: number; sugerido: string;
}>();
const puede = usePermisos();
const filtrar = (estado: string) => router.get('/rrhh/prestamos', estado ? { estado } : {}, { preserveState: true, replace: true });
const abierto = ref(false);
const form = useForm({ empleado_id: null as number | null, monto: null as number | null, cuotas: 6, primer_periodo: props.sugerido, motivo: '' });
const cuota = computed(() => (form.monto && form.cuotas ? Math.ceil(form.monto / form.cuotas) : 0));
const solicitar = () => form.post('/rrhh/prestamos', { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset(); } });
const resolver = (p: Prestamo, accion: 'aprobar' | 'rechazar') => router.post(`/rrhh/prestamos/${p.id}/${accion}`, {}, { preserveScroll: true });
const mes = (p: string) => new Date(`${p}-15T12:00:00`).toLocaleDateString('es-CL', { month: 'short', year: 'numeric' });
</script>

<template>
    <Head title="Préstamos" />
    <Encabezado titulo="Préstamos al personal" :bajada="`Préstamos de la empresa descontados en cuotas por nómina. La cuota no puede superar el ${topePct}% del sueldo y el préstamo lo aprueba otra persona.`">
        <button v-if="puede('rrhh.gestion-de-prestamos.crear')" class="btn btn-primario" @click="abierto = true"><Icono nombre="mas" :tamano="18" /> Nuevo préstamo</button>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Saldo por cobrar" :valor="pesos(totales.vigente)" />
        <Kpi etiqueta="Por aprobar" :valor="totales.por_aprobar" :tono="totales.por_aprobar ? 'naranja' : 'normal'" />
    </div>

    <div class="tarjeta mb-6 flex flex-wrap gap-2 p-4" role="group" aria-label="Estado">
        <button class="btn btn-sm" :class="!filtros.estado ? 'btn-oscuro' : 'btn-secundario'" @click="filtrar('')">Todos</button>
        <button v-for="(n, k) in estados" :key="k" class="btn btn-sm" :class="filtros.estado === k ? 'btn-oscuro' : 'btn-secundario'" @click="filtrar(String(k))">{{ n }}</button>
    </div>

    <div v-if="prestamos.data.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Persona</th><th class="text-right">Monto</th><th class="text-right">Cuotas</th><th>Desde</th><th class="text-right">Saldo</th><th>Estado</th><th></th></tr></thead>
            <tbody>
                <tr v-for="p in prestamos.data" :key="p.id">
                    <td><p class="font-semibold">{{ p.empleado }}</p><p class="text-[13px] text-grafito">{{ p.motivo }} · solicitó {{ p.solicitante }}</p></td>
                    <td class="num text-right">{{ pesos(p.monto) }}</td>
                    <td class="num text-right">{{ p.cuotas }} × {{ pesos(p.valor_cuota) }}</td>
                    <td class="capitalize">{{ mes(p.primer_periodo) }}</td>
                    <td class="num text-right font-semibold">{{ pesos(p.saldo) }}</td>
                    <td><Chip chico :tono="estadoPrestamo[p.estado][0]">{{ estadoPrestamo[p.estado][1] }}</Chip></td>
                    <td class="whitespace-nowrap text-right">
                        <template v-if="p.estado === 'por_aprobar' && puede('rrhh.gestion-de-prestamos.aprobar')">
                            <span v-if="p.propio" class="text-[13px] text-grafito">Lo aprueba otra persona</span>
                            <template v-else>
                                <button class="btn btn-primario btn-sm" @click="resolver(p, 'aprobar')">Aprobar</button>
                                <button class="btn btn-secundario btn-sm ml-2" @click="resolver(p, 'rechazar')">Rechazar</button>
                            </template>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
        <Paginacion :datos="prestamos" nombre="préstamos" />
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin préstamos" />

    <Modal :abierto="abierto" titulo="Nuevo préstamo" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Persona" id="p-emp" obligatorio :error="form.errors.empleado_id" class="sm:col-span-2">
                <select id="p-emp" v-model="form.empleado_id" class="campo h-11"><option :value="null" disabled>Selecciona…</option><option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Monto" id="p-monto" obligatorio :error="form.errors.monto"><input id="p-monto" v-model.number="form.monto" type="number" min="1" class="campo num h-11" /></Campo>
            <Campo etiqueta="Cuotas" id="p-cuotas" obligatorio :error="form.errors.cuotas" :ayuda="cuota ? `Cuota de ${pesos(cuota)}` : undefined"><input id="p-cuotas" v-model.number="form.cuotas" type="number" min="1" max="48" class="campo num h-11" /></Campo>
            <Campo etiqueta="Primera cuota" id="p-periodo" obligatorio :error="form.errors.primer_periodo"><input id="p-periodo" v-model="form.primer_periodo" type="month" class="campo h-11" /></Campo>
            <Campo etiqueta="Motivo" id="p-motivo" obligatorio :error="form.errors.motivo"><input id="p-motivo" v-model="form.motivo" class="campo h-11" maxlength="200" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing || !form.empleado_id" @click="solicitar">Solicitar</button>
        </template>
    </Modal>
</template>
