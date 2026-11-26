<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import type { Paginado } from '@/types';
import { fecha } from '@/lib/formato';
import { consulta, http } from '@/lib/http';
import { estadoAusencia } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Ausencia {
    id: number; empleado_id: number; empleado: string; tipo: string; desde: string; hasta: string; dias: number; detalle: string | null; estado: string;
    solicitante: string | null; resolutor: string | null; motivo_rechazo: string | null; propia: boolean;
}
interface Saldo { devengados: number; progresivos: number; tomados: number; disponibles: number; anios: number }
const props = defineProps<{
    ausencias: Paginado<Ausencia>; empleados: { id: number; nombre: string }[]; filtros: { estado: string; tipo: string | null };
    tipos: Record<string, string>; estados: Record<string, string>; pendientes: number;
}>();
const puede = usePermisos();
const f = reactive({ estado: props.filtros.estado, tipo: props.filtros.tipo ?? '' });
watch(f, () => router.get('/rrhh/ausencias', { estado: f.estado, tipo: f.tipo || undefined }, { preserveState: true, replace: true }));
const hoy = new Date().toLocaleDateString('sv-SE');
const filtrosEstado: Record<string, string> = { ...props.estados, todas: 'Todas' };

const abierto = ref(false);
const form = useForm({ empleado_id: null as number | null, tipo: 'vacaciones', desde: '', hasta: '', detalle: '' });
const saldo = ref<Saldo | null>(null);
watch(() => form.empleado_id, async (id) => {
    saldo.value = null;
    if (id) saldo.value = (await http.get<Saldo>(`/rrhh/ausencias/saldo/${id}`)).data;
});
function habiles(): number {
    if (!form.desde || !form.hasta || form.hasta < form.desde) return 0;
    let n = 0;
    for (let d = new Date(`${form.desde}T12:00:00`); d <= new Date(`${form.hasta}T12:00:00`); d.setDate(d.getDate() + 1)) n += d.getDay() % 6 ? 1 : 0;
    return n;
}
const solicitar = () => form.post('/rrhh/ausencias', { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset(); } });

const opts = { preserveScroll: true };
const aprobar = (a: Ausencia) => router.post(`/rrhh/ausencias/${a.id}/aprobar`, {}, opts);
const anular = (a: Ausencia) => router.post(`/rrhh/ausencias/${a.id}/anular`, {}, opts);
const rechazando = ref<Ausencia | null>(null);
const fr = useForm({ motivo: '' });
const rechazar = () => fr.post(`/rrhh/ausencias/${rechazando.value!.id}/rechazar`, { preserveScroll: true, onSuccess: () => { rechazando.value = null; fr.reset(); } });
const exportar = () => `/rrhh/ausencias?${consulta({ estado: f.estado, tipo: f.tipo, exportar: 1 })}`;
</script>

<template>
    <Head title="Vacaciones y ausencias" />
    <Encabezado titulo="Vacaciones y ausencias" bajada="Solicitudes de vacaciones, licencias y permisos. Las aprueba una persona distinta de quien solicita. El saldo de vacaciones se calcula por antigüedad (15 días hábiles al año más el feriado progresivo).">
        <a v-if="puede('rrhh.vacaciones-y-ausencias.exportar')" :href="exportar()" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <button v-if="puede('rrhh.vacaciones-y-ausencias.crear')" class="btn btn-primario" @click="abierto = true"><Icono nombre="mas" :tamano="18" /> Nueva solicitud</button>
    </Encabezado>

    <div class="tarjeta mb-6 flex flex-wrap items-center gap-3 p-4">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Estado">
            <button v-for="(n, k) in filtrosEstado" :key="k" class="btn btn-sm" :class="f.estado === k ? 'btn-oscuro' : 'btn-secundario'" :aria-pressed="f.estado === k" @click="f.estado = String(k)">
                {{ n }}<span v-if="k === 'solicitada' && pendientes" class="num ml-1">({{ pendientes }})</span>
            </button>
        </div>
        <select v-model="f.tipo" class="campo h-10 sm:ml-auto sm:w-56" aria-label="Tipo"><option value="">Todos los tipos</option><option v-for="(n, k) in tipos" :key="k" :value="k">{{ n }}</option></select>
    </div>

    <div v-if="ausencias.data.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Persona</th><th>Tipo</th><th>Período</th><th class="text-right">Días hábiles</th><th>Estado</th><th></th></tr></thead>
            <tbody>
                <tr v-for="a in ausencias.data" :key="a.id">
                    <td><p class="font-semibold">{{ a.empleado }}</p><p class="text-[13px] text-grafito">Solicitó {{ a.solicitante }}<span v-if="a.detalle"> · {{ a.detalle }}</span></p></td>
                    <td>{{ tipos[a.tipo] }}</td>
                    <td class="num whitespace-nowrap">{{ fecha(a.desde) }} – {{ fecha(a.hasta) }}</td>
                    <td class="num text-right">{{ a.dias }}</td>
                    <td>
                        <Chip chico :tono="estadoAusencia[a.estado][0]">{{ estadoAusencia[a.estado][1] }}</Chip>
                        <p v-if="a.resolutor" class="text-[13px] text-grafito">{{ a.resolutor }}<span v-if="a.motivo_rechazo">: {{ a.motivo_rechazo }}</span></p>
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <template v-if="a.estado === 'solicitada' && puede('rrhh.vacaciones-y-ausencias.aprobar')">
                            <span v-if="a.propia" class="text-[13px] text-grafito">La resuelve otra persona</span>
                            <template v-else>
                                <button class="btn btn-primario btn-sm" @click="aprobar(a)">Aprobar</button>
                                <button class="btn btn-secundario btn-sm ml-2" @click="rechazando = a">Rechazar</button>
                            </template>
                        </template>
                        <button v-if="puede('rrhh.vacaciones-y-ausencias.eliminar') && (a.estado === 'solicitada' || (a.estado === 'aprobada' && a.desde > hoy))" class="btn btn-secundario btn-sm ml-2" @click="anular(a)">Anular</button>
                    </td>
                </tr>
            </tbody>
        </table>
        <Paginacion :datos="ausencias" nombre="ausencias" />
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin ausencias" :texto="f.estado === 'solicitada' ? 'No hay solicitudes pendientes de aprobación.' : 'No hay ausencias con estos filtros.'" />

    <Modal :abierto="abierto" titulo="Nueva solicitud" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Persona" id="a-emp" obligatorio :error="form.errors.empleado_id" class="sm:col-span-2">
                <select id="a-emp" v-model="form.empleado_id" class="campo h-11"><option :value="null" disabled>Selecciona…</option><option v-for="e in empleados" :key="e.id" :value="e.id">{{ e.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Tipo" id="a-tipo" obligatorio :error="form.errors.tipo" class="sm:col-span-2"><select id="a-tipo" v-model="form.tipo" class="campo h-11"><option v-for="(n, k) in tipos" :key="k" :value="k">{{ n }}</option></select></Campo>
            <Campo etiqueta="Desde" id="a-desde" obligatorio :error="form.errors.desde"><input id="a-desde" v-model="form.desde" type="date" class="campo h-11" /></Campo>
            <Campo etiqueta="Hasta" id="a-hasta" obligatorio :error="form.errors.hasta"><input id="a-hasta" v-model="form.hasta" type="date" class="campo h-11" :min="form.desde" /></Campo>
            <Campo etiqueta="Detalle" id="a-det" :error="form.errors.detalle" class="sm:col-span-2"><input id="a-det" v-model="form.detalle" class="campo h-11" maxlength="200" placeholder="N° de licencia, motivo del permiso…" /></Campo>
        </div>
        <div v-if="saldo && form.tipo === 'vacaciones'" class="mt-4 rounded-boton bg-hormigon p-4 text-[14px]">
            <p><span class="font-semibold">Saldo de vacaciones:</span> <span class="num">{{ saldo.disponibles }}</span> días hábiles disponibles</p>
            <p class="text-grafito">{{ saldo.anios }} años de antigüedad · devengados {{ saldo.devengados.toLocaleString('es-CL') }} + progresivos {{ saldo.progresivos }} − tomados o solicitados {{ saldo.tomados }}</p>
            <p v-if="habiles()" class="mt-1" :class="{ 'font-semibold text-estado-error': habiles() > saldo.disponibles }">Esta solicitud: {{ habiles() }} días hábiles</p>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing || !form.empleado_id" @click="solicitar">Solicitar</button>
        </template>
    </Modal>

    <Modal :abierto="rechazando !== null" titulo="Rechazar solicitud" @cerrar="rechazando = null">
        <p class="mb-4 text-grafito">{{ rechazando?.empleado }} · {{ rechazando ? tipos[rechazando.tipo] : '' }}</p>
        <Campo etiqueta="Motivo" id="r-motivo" obligatorio :error="fr.errors.motivo"><input id="r-motivo" v-model="fr.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="rechazando = null">Cancelar</button>
            <button class="btn btn-peligro" :disabled="fr.processing || !fr.motivo.trim()" @click="rechazar">Rechazar</button>
        </template>
    </Modal>
</template>
