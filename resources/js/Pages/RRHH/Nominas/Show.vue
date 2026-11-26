<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, fechaHora, pesos, rut } from '@/lib/formato';
import { estadoNomina } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Linea { concepto: string; monto: number; imponible?: boolean; legal?: boolean; bono?: boolean }
interface Liq {
    id: number; empleado: string; rut: string; cargo: string; dias: number; horas_extra: number; imponible: number; no_imponible: number; descuentos: number; impuesto: number;
    liquido: number; costo: number; haberes_detalle: Linea[]; descuentos_detalle: Linea[]; aportes: Linea[]; bonos: { concepto: string; monto: number }[];
}
const props = defineProps<{
    nomina: { id: number; periodo: string; estado: string; haberes: number; descuentos: number; liquido: number; empleador: number; indicadores: Record<string, string | number>;
        generador: string | null; generado_por: number; aprobador: string | null; aprobado_at: string | null; fecha_pago: string | null };
    liquidaciones: Liq[]; afps: Record<string, string>;
}>();
const puede = usePermisos();
const page = usePage<PageProps>();
const yo = computed(() => page.props.auth.user!.id);
const mes = computed(() => new Date(`${props.nomina.periodo}-15T12:00:00`).toLocaleDateString('es-CL', { month: 'long', year: 'numeric' }));
const borrador = computed(() => props.nomina.estado === 'borrador');

const abierta = ref<number | null>(null);
const alternar = (id: number) => { abierta.value = abierta.value === id ? null : id; };

const editando = ref<Liq | null>(null);
const fb = useForm({ bonos: [] as { concepto: string; monto: number | null }[] });
function abrirBonos(l: Liq) {
    editando.value = l;
    fb.clearErrors();
    fb.bonos = l.bonos.map((b) => ({ ...b }));
    if (!fb.bonos.length) fb.bonos.push({ concepto: '', monto: null });
}
const guardarBonos = () => fb.transform((d) => ({ bonos: d.bonos.filter((b) => b.concepto.trim() && b.monto) }))
    .post(`/rrhh/liquidaciones/${editando.value!.id}/bonos`, { preserveScroll: true, onSuccess: () => { editando.value = null; } });

const fa = useForm({});
const aprobar = () => fa.post(`/rrhh/nominas/${props.nomina.id}/aprobar`, { preserveScroll: true });
const pagando = ref(false);
const fp = useForm({ fecha_pago: new Date().toLocaleDateString('sv-SE') });
const pagar = () => fp.post(`/rrhh/nominas/${props.nomina.id}/pagar`, { preserveScroll: true, onSuccess: () => { pagando.value = false; } });
const ind = (k: string) => props.nomina.indicadores?.[k];
</script>

<template>
    <Head :title="`Nómina ${nomina.periodo}`" />
    <Encabezado :titulo="`Nómina de ${mes}`" volver="/rrhh/nominas">
        <a v-if="puede('rrhh.nomina-y-liquidaciones.exportar')" :href="`/rrhh/nominas/${nomina.id}?exportar=1`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <template v-if="borrador && puede('rrhh.nomina-y-liquidaciones.aprobar')">
            <button v-if="nomina.generado_por !== yo" class="btn btn-primario" :disabled="fa.processing || !liquidaciones.length" @click="aprobar">Aprobar y contabilizar</button>
            <span v-else class="self-center text-[14px] text-grafito">La aprueba otra persona</span>
        </template>
        <button v-if="nomina.estado === 'aprobada' && puede('rrhh.nomina-y-liquidaciones.editar')" class="btn btn-primario" @click="pagando = true">Registrar pago</button>
    </Encabezado>

    <p class="-mt-3 mb-6 flex flex-wrap items-center gap-2">
            <Chip :tono="estadoNomina[nomina.estado][0]">{{ estadoNomina[nomina.estado][1] }}</Chip>
            <span class="ml-2 text-grafito">Calculó {{ nomina.generador }}<template v-if="nomina.aprobador"> · aprobó {{ nomina.aprobador }} el {{ fechaHora(nomina.aprobado_at) }}</template><template v-if="nomina.fecha_pago"> · pagada el {{ fecha(nomina.fecha_pago) }}</template></span>
    </p>
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Total haberes" :valor="pesos(nomina.haberes)">{{ liquidaciones.length }} {{ liquidaciones.length === 1 ? 'persona' : 'personas' }}</Kpi>
        <Kpi etiqueta="Descuentos" :valor="pesos(nomina.descuentos)" />
        <Kpi etiqueta="Líquido a pagar" :valor="pesos(nomina.liquido)" tono="naranja" />
        <Kpi etiqueta="Costo empresa" :valor="pesos(nomina.haberes + nomina.empleador)">Aportes del empleador {{ pesos(nomina.empleador) }}</Kpi>
    </div>
    <p v-if="nomina.indicadores" class="mb-6 text-[14px] text-grafito">
        Indicadores usados: UF {{ Number(ind('uf')).toLocaleString('es-CL', { minimumFractionDigits: 2 }) }} · UTM {{ pesos(Number(ind('utm'))) }} · Ingreso mínimo {{ pesos(Number(ind('imm'))) }}<template v-if="borrador"> · Si cambian, vuelve a calcular la nómina desde el listado.</template>
    </p>

    <div v-if="liquidaciones.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Persona</th><th class="text-right">Días</th><th class="text-right">Imponible</th><th class="text-right">No imponible</th><th class="text-right">Descuentos</th><th class="text-right">Líquido</th><th></th></tr></thead>
            <tbody>
                <template v-for="l in liquidaciones" :key="l.id">
                    <tr class="cursor-pointer" @click="alternar(l.id)">
                        <td><p class="font-semibold">{{ l.empleado }}</p><p class="num text-[13px] text-grafito">{{ rut(l.rut) }} · {{ l.cargo }}</p></td>
                        <td class="num text-right">{{ l.dias }}<span v-if="l.horas_extra" class="block text-[13px] text-grafito">+{{ l.horas_extra }} h extra</span></td>
                        <td class="num text-right">{{ pesos(l.imponible) }}</td>
                        <td class="num text-right">{{ pesos(l.no_imponible) }}</td>
                        <td class="num text-right">{{ pesos(l.descuentos) }}</td>
                        <td class="num text-right font-semibold">{{ pesos(l.liquido) }}</td>
                        <td class="whitespace-nowrap text-right" @click.stop>
                            <button v-if="borrador && puede('rrhh.nomina-y-liquidaciones.editar')" class="btn btn-secundario btn-sm" @click="abrirBonos(l)">Bonos</button>
                            <a :href="`/rrhh/liquidaciones/${l.id}/pdf`" target="_blank" class="btn btn-secundario btn-sm ml-2 no-underline">PDF</a>
                        </td>
                    </tr>
                    <tr v-if="abierta === l.id" class="bg-hormigon/50">
                        <td colspan="7">
                            <div class="grid gap-6 py-2 md:grid-cols-3">
                                <div>
                                    <p class="etiqueta">Haberes</p>
                                    <p v-for="(h, i) in l.haberes_detalle" :key="i" class="flex justify-between gap-3 text-[14px]"><span>{{ h.concepto }}<span v-if="!h.imponible" class="text-grafito"> (no imp.)</span></span><span class="num">{{ pesos(h.monto) }}</span></p>
                                </div>
                                <div>
                                    <p class="etiqueta">Descuentos</p>
                                    <p v-for="(d, i) in l.descuentos_detalle" :key="i" class="flex justify-between gap-3 text-[14px]"><span>{{ d.concepto }}</span><span class="num">{{ pesos(d.monto) }}</span></p>
                                </div>
                                <div>
                                    <p class="etiqueta">Aportes del empleador</p>
                                    <p v-for="(a, i) in l.aportes" :key="i" class="flex justify-between gap-3 text-[14px]"><span>{{ a.concepto }}</span><span class="num">{{ pesos(a.monto) }}</span></p>
                                    <p class="mt-2 flex justify-between gap-3 border-t border-linea pt-2 text-[14px] font-semibold"><span>Costo total</span><span class="num">{{ pesos(l.costo) }}</span></p>
                                </div>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin liquidaciones" texto="No hay contratos vigentes en el período." />

    <Modal :abierto="editando !== null" :titulo="`Bonos · ${editando?.empleado ?? ''}`" @cerrar="editando = null">
        <p class="mb-4 text-grafito">Bonos imponibles del mes. Al guardar se recalcula la liquidación completa.</p>
        <div v-for="(b, i) in fb.bonos" :key="i" class="mb-3 flex items-end gap-2">
            <Campo etiqueta="Concepto" :id="`b-c-${i}`" class="flex-1"><input :id="`b-c-${i}`" v-model="b.concepto" class="campo h-11" maxlength="60" placeholder="Bono de producción" /></Campo>
            <Campo etiqueta="Monto" :id="`b-m-${i}`" class="w-40"><input :id="`b-m-${i}`" v-model.number="b.monto" type="number" min="1" class="campo num h-11" /></Campo>
            <button class="btn btn-secundario h-11" aria-label="Quitar bono" @click="fb.bonos.splice(i, 1)"><Icono nombre="cerrar" :tamano="16" /></button>
        </div>
        <button v-if="fb.bonos.length < 10" class="btn btn-secundario btn-sm" @click="fb.bonos.push({ concepto: '', monto: null })"><Icono nombre="mas" :tamano="16" /> Agregar bono</button>
        <p v-for="(e, k) in fb.errors" :key="k" class="error">{{ e }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="editando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="fb.processing" @click="guardarBonos">Guardar y recalcular</button>
        </template>
    </Modal>

    <Modal :abierto="pagando" titulo="Registrar pago de la nómina" @cerrar="pagando = false">
        <p class="mb-4 text-grafito">Se contabiliza el pago de {{ pesos(nomina.liquido) }} desde el banco.</p>
        <Campo etiqueta="Fecha de pago" id="p-fecha" obligatorio :error="fp.errors.fecha_pago"><input id="p-fecha" v-model="fp.fecha_pago" type="date" class="campo h-11" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="pagando = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fp.processing" @click="pagar">Registrar pago</button>
        </template>
    </Modal>
</template>
