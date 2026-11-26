<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Campo from '@/Components/Campo.vue';
import Modal from '@/Components/Modal.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora } from '@/lib/formato';
import { mostrarValor } from './valor';
import { usePermisos } from '@/lib/permisos';

interface Param {
    clave: string; etiqueta: string; tipo: string; unidad: string | null; ayuda: string | null; min: number | null; max: number | null;
    opciones: string[] | null; obligatorias: string[]; estricto: 'mayor' | 'menor' | null; alcances: string[]; inicial: unknown; efectivo: unknown;
    valores: { global: unknown; empresa: unknown; local: unknown };
}
interface Cambio {
    id: number; clave: string; etiqueta: string; valor: unknown; unidad: string | null; alcance: string; estado: string; motivo: string;
    vigente_desde: string; propuesto_por: string; propuesto_por_id: number; resuelto_por: string | null; comentario: string | null; auto: boolean; fecha: string;
}
const props = defineProps<{
    mantenedor: { clave: string; nombre: string; grupo_nombre: string; sensible: boolean; edita: string; piso: string; entidad: string | null };
    parametros: Param[];
    historial: Cambio[];
    locales: { id: number; nombre: string }[];
    localId: number | null;
}>();

const page = usePage<PageProps>();
const puede = usePermisos();
const yo = computed(() => page.props.auth.user!);
const esSuperadmin = computed(() => yo.value.es_superadmin);
const pendientes = computed(() => props.historial.filter((c) => c.estado === 'propuesto'));

const etiquetasAlcance: Record<string, string> = { global: 'Global (todas las empresas)', empresa: `Empresa: ${page.props.empresa?.actual.nombre ?? ''}`, local: 'Local' };
const estadoCambio: Record<string, ['ok' | 'alerta' | 'error', string]> = { aprobado: ['ok', 'Aprobado'], propuesto: ['alerta', 'Por aprobar'], rechazado: ['error', 'Rechazado'] };

// Edición: un parámetro a la vez, en un modal.
const editando = ref<Param | null>(null);
const form = useForm<{ clave: string; valor: string | number | boolean | string[] | number[] | null; alcance: string; local_id: number | null; motivo: string; vigente_desde: string }>({
    clave: '', valor: null, alcance: 'empresa', local_id: null, motivo: '', vigente_desde: '',
});
const texto = ref('');
const seleccion = reactive<Record<string, boolean>>({});

function alcancesPermitidos(p: Param) {
    return p.alcances.filter((a) => a !== 'global' || esSuperadmin.value);
}
function abrir(p: Param) {
    editando.value = p;
    form.clearErrors();
    const alcance = alcancesPermitidos(p).includes('empresa') ? 'empresa' : alcancesPermitidos(p)[0];
    Object.assign(form, { clave: p.clave, valor: p.efectivo, alcance, local_id: props.localId, motivo: '', vigente_desde: '' });
    texto.value = Array.isArray(p.efectivo) ? (p.efectivo as unknown[]).join(p.tipo === 'escalones' ? ', ' : '\n') : '';
    Object.keys(seleccion).forEach((k) => delete seleccion[k]);
    (p.opciones ?? []).forEach((o) => (seleccion[o] = Array.isArray(p.efectivo) && (p.efectivo as string[]).includes(o)));
}
function guardar() {
    const p = editando.value!;
    form.transform((d) => ({
        ...d,
        valor: p.tipo === 'lista' ? texto.value.split('\n').map((s) => s.trim()).filter(Boolean)
            : p.tipo === 'escalones' ? texto.value.split(',').map((s) => s.trim()).filter(Boolean)
            : p.tipo === 'multiple' ? (p.opciones ?? []).filter((o) => seleccion[o])
            : d.valor,
        local_id: d.alcance === 'local' ? d.local_id : null,
        vigente_desde: d.vigente_desde || null,
    })).post('/configuracion/parametros/proponer', { preserveScroll: true, onSuccess: () => (editando.value = null) });
}

const resolviendo = ref<{ cambio: Cambio; accion: 'aprobar' | 'rechazar' | 'revertir' } | null>(null);
const resolucion = useForm({ comentario: '', motivo: '' });
// El servidor puede devolver errores de campos que no están en el formulario (valor, cambio).
const erroresRes = computed(() => resolucion.errors as Record<string, string | undefined>);
function resolver() {
    const r = resolviendo.value!;
    resolucion.post(`/configuracion/parametros/cambios/${r.cambio.id}/${r.accion}`, { preserveScroll: true, onSuccess: () => { resolviendo.value = null; resolucion.reset(); } });
}

function verLocal(e: Event) {
    const v = (e.target as HTMLSelectElement).value;
    router.get(`/configuracion/parametros/${props.mantenedor.clave}`, v ? { local_id: v } : {}, { preserveState: false });
}
const tieneLocal = computed(() => props.parametros.some((p) => p.alcances.includes('local')));
</script>

<template>
    <Head :title="mantenedor.nombre" />
    <Encabezado :titulo="mantenedor.nombre" :bajada="mantenedor.grupo_nombre" volver="/configuracion/parametros">
        <a v-if="mantenedor.entidad" :href="mantenedor.entidad" class="btn btn-oscuro no-underline">Abrir mantenedor</a>
    </Encabezado>

    <div class="mb-6 flex flex-wrap items-start gap-4">
        <div class="tarjeta flex-1 p-4">
            <p class="text-[13px] font-semibold uppercase tracking-wide text-grafito">Piso fijo</p>
            <p class="mt-1">{{ mantenedor.piso }}</p>
        </div>
        <div class="tarjeta p-4">
            <p class="text-[13px] font-semibold uppercase tracking-wide text-grafito">Edita</p>
            <p class="mt-1">{{ mantenedor.edita }}</p>
            <Chip v-if="mantenedor.sensible" chico tono="info" class="mt-2">Doble aprobación</Chip>
        </div>
        <div v-if="tieneLocal && locales.length" class="tarjeta p-4">
            <label class="text-[13px] font-semibold uppercase tracking-wide text-grafito" for="ver_local">Ver valores del local</label>
            <select id="ver_local" class="campo mt-1 h-10" :value="localId ?? ''" @change="verLocal"><option value="">Sin local</option><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
        </div>
    </div>

    <div v-if="!parametros.length" class="tarjeta p-6 text-grafito">
        Este mantenedor se administra en su propia pantalla.
    </div>

    <div class="tarjeta overflow-x-auto" v-else>
        <table class="tabla">
            <thead><tr><th>Parámetro</th><th>Valor que rige</th><th>Global</th><th>Empresa</th><th v-if="localId">Local</th><th class="w-px"></th></tr></thead>
            <tbody>
                <tr v-for="p in parametros" :key="p.clave">
                    <td class="max-w-sm">
                        <div class="font-semibold">{{ p.etiqueta }}</div>
                        <div v-if="p.ayuda" class="text-[13px] text-grafito">{{ p.ayuda }}</div>
                        <div v-if="p.min !== null || p.max !== null" class="num text-[13px] text-grafito">Rango {{ p.min ?? '—' }} a {{ p.max ?? '—' }}{{ p.unidad ? ` ${p.unidad}` : '' }}</div>
                    </td>
                    <td class="num text-[17px] font-bold">{{ mostrarValor(p.efectivo, p.unidad) }}</td>
                    <td class="num text-grafito">{{ p.valores.global === null ? `Inicial: ${mostrarValor(p.inicial, p.unidad)}` : mostrarValor(p.valores.global, p.unidad) }}</td>
                    <td class="num text-grafito">{{ p.alcances.includes('empresa') ? (p.valores.empresa === null ? 'Hereda' : mostrarValor(p.valores.empresa, p.unidad)) : 'No aplica' }}</td>
                    <td v-if="localId" class="num text-grafito">{{ p.alcances.includes('local') ? (p.valores.local === null ? 'Hereda' : mostrarValor(p.valores.local, p.unidad)) : 'No aplica' }}</td>
                    <td class="text-right">
                        <button v-if="puede('configuracion.parametros.proponer') && alcancesPermitidos(p).length" class="btn btn-secundario btn-sm" @click="abrir(p)">
                            {{ mantenedor.sensible ? 'Proponer cambio' : 'Cambiar' }}
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <section v-if="pendientes.length" class="tarjeta mt-6">
        <h2 class="titulo-ancho px-5 pb-2 pt-4 text-[16px] font-bold">Por aprobar</h2>
        <table class="tabla">
            <tbody>
                <tr v-for="c in pendientes" :key="c.id">
                    <td><div class="font-semibold">{{ c.etiqueta }}</div><div class="text-[13px] text-grafito">{{ c.propuesto_por }} · {{ fechaHora(c.fecha) }} · {{ c.motivo }}</div></td>
                    <td class="num font-semibold">{{ mostrarValor(c.valor, c.unidad) }}</td>
                    <td class="capitalize">{{ c.alcance }}</td>
                    <td class="whitespace-nowrap text-right">
                        <span v-if="c.propuesto_por_id === yo.id" class="text-[14px] text-grafito">Esperando a otro aprobador</span>
                        <div v-else-if="puede('configuracion.parametros.aprobar')" class="flex justify-end gap-2">
                            <button class="btn btn-primario btn-sm" @click="resolviendo = { cambio: c, accion: 'aprobar' }">Aprobar</button>
                            <button class="btn btn-peligro btn-sm" @click="resolviendo = { cambio: c, accion: 'rechazar' }">Rechazar</button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </section>

    <section class="tarjeta mt-6">
        <div class="flex items-center gap-2 px-5 pb-2 pt-4"><Icono nombre="historial" :tamano="18" /><h2 class="titulo-ancho text-[16px] font-bold">Historial de cambios</h2></div>
        <div class="overflow-x-auto">
            <table v-if="historial.length" class="tabla">
                <thead><tr><th>Fecha</th><th>Parámetro</th><th>Valor</th><th>Alcance</th><th>Estado</th><th>Quién</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="c in historial" :key="c.id">
                        <td class="num whitespace-nowrap text-grafito">{{ fechaHora(c.fecha) }}</td>
                        <td><div class="font-semibold">{{ c.etiqueta }}</div><div class="text-[13px] text-grafito">{{ c.motivo }}</div></td>
                        <td class="num">{{ mostrarValor(c.valor, c.unidad) }}</td>
                        <td class="capitalize">{{ c.alcance }}</td>
                        <td><Chip chico :tono="estadoCambio[c.estado][0]">{{ estadoCambio[c.estado][1] }}</Chip></td>
                        <td class="text-[14px]">{{ c.propuesto_por }}<template v-if="c.resuelto_por && !c.auto"> · aprobó {{ c.resuelto_por }}</template></td>
                        <td class="text-right">
                            <button v-if="c.estado === 'aprobado' && puede('configuracion.parametros.proponer') && (c.alcance !== 'global' || esSuperadmin)" class="btn btn-secundario btn-sm" @click="resolviendo = { cambio: c, accion: 'revertir' }">Volver a este valor</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="px-5 pb-5 text-grafito">Aún no hay cambios: rigen los valores iniciales.</p>
        </div>
    </section>

    <Modal :abierto="!!editando" :titulo="editando?.etiqueta ?? ''" ancho="lg" @cerrar="editando = null">
        <form v-if="editando" id="form-param" class="space-y-5" @submit.prevent="guardar">
            <Campo etiqueta="Alcance" id="alcance" :error="form.errors.alcance">
                <select id="alcance" v-model="form.alcance" class="campo h-11">
                    <option v-for="a in alcancesPermitidos(editando)" :key="a" :value="a">{{ etiquetasAlcance[a] }}</option>
                </select>
            </Campo>
            <Campo v-if="form.alcance === 'local'" etiqueta="Local" id="local" :error="form.errors.local_id">
                <select id="local" v-model="form.local_id" class="campo h-11"><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
            </Campo>

            <Campo etiqueta="Valor nuevo" id="valor" :error="form.errors.valor" :ayuda="editando.estricto && form.alcance !== 'global' ? 'Una empresa o local solo puede ser igual o más estricto que el valor global.' : (editando.ayuda ?? undefined)">
                <div v-if="editando.tipo === 'entero' || editando.tipo === 'porcentaje'" class="flex items-center gap-2">
                    <input id="valor" v-model.number="form.valor" type="number" :min="editando.min ?? undefined" :max="editando.max ?? undefined" :step="editando.tipo === 'porcentaje' ? '0.01' : '1'" class="campo num h-11 max-w-[220px]" />
                    <span v-if="editando.unidad" class="text-grafito">{{ editando.unidad }}</span>
                </div>
                <Interruptor v-else-if="editando.tipo === 'booleano'" :model-value="!!form.valor" etiqueta="Activado" @update:model-value="form.valor = $event" />
                <select v-else-if="editando.tipo === 'opcion'" id="valor" v-model="form.valor" class="campo h-11">
                    <option v-for="o in editando.opciones ?? []" :key="o" :value="o">{{ o }}</option>
                </select>
                <div v-else-if="editando.tipo === 'multiple'" class="grid gap-2 sm:grid-cols-2">
                    <label v-for="o in editando.opciones ?? []" :key="o" class="flex items-center gap-2" :class="editando.obligatorias.includes(o) && 'text-grafito'">
                        <input v-model="seleccion[o]" type="checkbox" class="h-5 w-5 rounded border-linea-fuerte text-carbon focus:ring-naranja" :disabled="editando.obligatorias.includes(o)" />
                        {{ o }}<span v-if="editando.obligatorias.includes(o)" class="text-[12px]">(piso)</span>
                    </label>
                </div>
                <textarea v-else-if="editando.tipo === 'lista'" id="valor" v-model="texto" rows="4" class="campo" placeholder="Un valor por línea" />
                <input v-else-if="editando.tipo === 'escalones'" id="valor" v-model="texto" class="campo num h-11" placeholder="1, 5, 30" />
                <input v-else id="valor" v-model="form.valor" class="campo h-11" />
            </Campo>

            <div class="grid gap-5 sm:grid-cols-2">
                <Campo etiqueta="Motivo" id="motivo" :error="form.errors.motivo" obligatorio ayuda="Queda en la auditoría.">
                    <input id="motivo" v-model="form.motivo" class="campo h-11" />
                </Campo>
                <Campo etiqueta="Rige desde" id="vigente" :error="form.errors.vigente_desde" ayuda="Vacío = desde ahora.">
                    <input id="vigente" v-model="form.vigente_desde" type="date" class="campo h-11" />
                </Campo>
            </div>
            <p v-if="mantenedor.sensible" class="rounded-boton bg-estado-info-bg px-4 py-3 text-[15px] text-estado-info">Mantenedor sensible: el cambio rige cuando lo apruebe otra persona con permiso.</p>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="editando = null">Cancelar</button>
            <button type="submit" form="form-param" class="btn btn-primario" :disabled="form.processing">{{ mantenedor.sensible ? 'Proponer cambio' : 'Guardar' }}</button>
        </template>
    </Modal>

    <Modal :abierto="!!resolviendo" :titulo="resolviendo?.accion === 'aprobar' ? 'Aprobar cambio' : resolviendo?.accion === 'rechazar' ? 'Rechazar cambio' : 'Volver a este valor'" @cerrar="resolviendo = null">
        <template v-if="resolviendo">
            <p class="mb-4"><span class="font-semibold">{{ resolviendo.cambio.etiqueta }}</span>: <span class="num font-semibold">{{ mostrarValor(resolviendo.cambio.valor, resolviendo.cambio.unidad) }}</span></p>
            <Campo v-if="resolviendo.accion === 'revertir'" etiqueta="Motivo" id="motivo_rev" :error="resolucion.errors.motivo || erroresRes.valor">
                <input id="motivo_rev" v-model="resolucion.motivo" class="campo h-11" />
            </Campo>
            <Campo v-else :etiqueta="resolviendo.accion === 'aprobar' ? 'Comentario (opcional)' : 'Motivo del rechazo'" id="comentario" :error="resolucion.errors.comentario || erroresRes.cambio">
                <textarea id="comentario" v-model="resolucion.comentario" class="campo" rows="3" />
            </Campo>
        </template>
        <template #pie>
            <button class="btn btn-secundario" @click="resolviendo = null">Cancelar</button>
            <button :class="resolviendo?.accion === 'rechazar' ? 'btn btn-peligro' : 'btn btn-primario'" :disabled="resolucion.processing" @click="resolver">Confirmar</button>
        </template>
    </Modal>
</template>
