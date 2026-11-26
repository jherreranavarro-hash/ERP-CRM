<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { consulta } from '@/lib/http';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, fechaHora } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Dia { fecha: string; entrada: string | null; salida: string | null; trabajados: number; turno: number; atraso: number; extra: number; excedido: boolean; estado: string }
const props = defineProps<{
    empleados: { id: number; nombre: string; turno: boolean }[]; empleado: number | null; filtros: { desde: string; hasta: string }; dias: Dia[];
    totales: { trabajados: number; extra: number; atraso: number; ausencias: number };
    marcas: { id: number; tipo: string; momento: string; origen: string; motivo: string | null; registrador: string | null }[];
}>();
const puede = usePermisos();
const f = reactive({ empleado: props.empleado, ...props.filtros });
watch(f, () => router.get('/rrhh/asistencia', { ...f }, { preserveState: true, replace: true }));
const hm = (min: number) => (min ? `${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}` : '—');
const tono: Record<string, ['ok' | 'alerta' | 'error' | 'neutro', string]> = { completo: ['ok', 'Completo'], sin_salida: ['alerta', 'Sin salida'], ausente: ['error', 'Ausente'], libre: ['neutro', 'Libre'], pendiente: ['neutro', '—'] };
const dia = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('es-CL', { weekday: 'short' });

const manual = ref(false);
const fm = useForm({ empleado_id: props.empleado, tipo: 'entrada', momento: '', motivo: '' });
const marcar = () => fm.post('/rrhh/asistencia/marcas', { preserveScroll: true, onSuccess: () => { manual.value = false; fm.reset('momento', 'motivo'); } });
const importando = ref(false);
const fi = useForm({ archivo: null as File | null });
const importar = () => fi.post('/rrhh/asistencia/importar', { forceFormData: true, preserveScroll: true, onSuccess: () => { importando.value = false; fi.reset(); } });
</script>

<template>
    <Head title="Asistencia" />
    <Encabezado titulo="Control de asistencia" bajada="Marcas del reloj control contra el turno: atrasos y horas extra (con el tope diario legal). Las marcas no se editan: una corrección es una marca manual con motivo.">
        <a v-if="empleado && puede('rrhh.control-de-asistencia.exportar')" :href="`/rrhh/asistencia?${consulta({ empleado: String(f.empleado), desde: f.desde, hasta: f.hasta, exportar: '1' })}`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <button v-if="puede('rrhh.control-de-asistencia.crear')" class="btn btn-secundario" @click="manual = true; fm.empleado_id = f.empleado">Marca manual</button>
        <button v-if="puede('rrhh.control-de-asistencia.crear')" class="btn btn-primario" @click="importando = true"><Icono nombre="subir" :tamano="18" /> Importar reloj control</button>
    </Encabezado>

    <div class="tarjeta mb-6 grid gap-3 p-4 sm:grid-cols-3">
        <select v-model="f.empleado" class="campo h-11" aria-label="Persona"><option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.nombre }}{{ e.turno ? '' : ' (sin turno)' }}</option></select>
        <input v-model="f.desde" type="date" class="campo h-11" aria-label="Desde" />
        <input v-model="f.hasta" type="date" class="campo h-11" aria-label="Hasta" />
    </div>

    <template v-if="empleado">
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi etiqueta="Horas trabajadas" :valor="hm(totales.trabajados)" />
            <Kpi etiqueta="Horas extra" :valor="hm(totales.extra)" tono="naranja" />
            <Kpi etiqueta="Atrasos" :valor="hm(totales.atraso)" :tono="totales.atraso ? 'error' : 'normal'" />
            <Kpi etiqueta="Días ausente" :valor="totales.ausencias" :tono="totales.ausencias ? 'error' : 'normal'" />
        </div>
        <div class="grid gap-6 xl:grid-cols-3">
            <div class="tarjeta overflow-x-auto xl:col-span-2">
                <table class="tabla">
                    <thead><tr><th>Día</th><th>Entrada</th><th>Salida</th><th class="text-right">Trabajado</th><th class="text-right">Atraso</th><th class="text-right">Extra</th><th>Estado</th></tr></thead>
                    <tbody>
                        <tr v-for="d in dias" :key="d.fecha" :class="{ 'text-grafito': d.estado === 'libre' }">
                            <td class="num"><span class="inline-block w-8 capitalize">{{ dia(d.fecha) }}</span>{{ fecha(d.fecha) }}</td>
                            <td class="num">{{ d.entrada ?? '—' }}</td>
                            <td class="num">{{ d.salida ?? '—' }}</td>
                            <td class="num text-right">{{ hm(d.trabajados) }}</td>
                            <td class="num text-right" :class="{ 'text-estado-error': d.atraso > 0 }">{{ hm(d.atraso) }}</td>
                            <td class="num text-right" :class="{ 'font-semibold text-estado-error': d.excedido }">{{ hm(d.extra) }}<span v-if="d.excedido" title="Trabajó sobre el tope diario de horas extra"> !</span></td>
                            <td><Chip chico :tono="tono[d.estado][0]">{{ tono[d.estado][1] }}</Chip></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <section class="tarjeta self-start">
                <h2 class="border-b border-linea p-4 text-[18px] font-bold">Últimas marcas</h2>
                <ul v-if="marcas.length" class="divide-y divide-linea">
                    <li v-for="m in marcas" :key="m.id" class="p-4 text-[14px]">
                        <p><span class="font-semibold capitalize">{{ m.tipo }}</span> · <span class="num">{{ fechaHora(m.momento) }}</span></p>
                        <p class="text-grafito">{{ m.origen }}<span v-if="m.motivo"> · {{ m.motivo }} ({{ m.registrador }})</span></p>
                    </li>
                </ul>
                <p v-else class="p-4 text-grafito">Sin marcas.</p>
            </section>
        </div>
    </template>
    <Vacio v-else class="tarjeta" titulo="Sin personas activas" />

    <Modal :abierto="manual" titulo="Marca manual" @cerrar="manual = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Persona" id="m-emp" obligatorio :error="fm.errors.empleado_id" class="sm:col-span-2"><select id="m-emp" v-model="fm.empleado_id" class="campo h-11"><option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.nombre }}</option></select></Campo>
            <Campo etiqueta="Tipo" id="m-tipo" obligatorio><select id="m-tipo" v-model="fm.tipo" class="campo h-11"><option value="entrada">Entrada</option><option value="salida">Salida</option></select></Campo>
            <Campo etiqueta="Fecha y hora" id="m-momento" obligatorio :error="fm.errors.momento"><input id="m-momento" v-model="fm.momento" type="datetime-local" class="campo h-11" /></Campo>
            <Campo etiqueta="Motivo" id="m-motivo" obligatorio :error="fm.errors.motivo" ayuda="Queda registrado en la auditoría." class="sm:col-span-2"><input id="m-motivo" v-model="fm.motivo" class="campo h-11" maxlength="200" placeholder="Olvidó marcar, falla del reloj…" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="manual = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fm.processing" @click="marcar">Registrar</button>
        </template>
    </Modal>

    <Modal :abierto="importando" titulo="Importar marcas del reloj control" @cerrar="importando = false">
        <p class="mb-4 text-grafito">Archivo CSV con RUT; fecha y hora; tipo (E o S). Las marcas ya importadas se omiten.</p>
        <Campo etiqueta="Archivo" id="r-archivo" obligatorio :error="fi.errors.archivo || (fi.errors as any).archivo"><input id="r-archivo" type="file" accept=".csv,.txt" class="campo h-auto py-2" @change="fi.archivo = ($event.target as HTMLInputElement).files?.[0] ?? null" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="importando = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fi.processing || !fi.archivo" @click="importar">Importar</button>
        </template>
    </Modal>
</template>
