<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos, rut } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Boleta { id: number; fecha: string; folio: number; rut: string; nombre: string; descripcion: string; bruto: number; tasa: number; retencion: number; liquido: number; estado: string; fecha_pago: string | null; centro: string | null }
const props = defineProps<{ mes: string; totales: { cantidad: number; bruto: number; retencion: number; liquido: number }; boletas: Paginado<Boleta>; tasa: number; centros: { id: number; codigo: string; nombre: string }[]; estados: Record<string, string> }>();
const puede = usePermisos();
const mes = ref(props.mes);
watch(mes, (m) => router.get('/finanzas/honorarios', { mes: m }, { preserveState: true }));
const tono: Record<string, 'alerta' | 'ok' | 'neutro'> = { por_pagar: 'alerta', pagada: 'ok', anulada: 'neutro' };

const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Santiago' });
const nueva = ref(false);
const form = useForm({ rut: '', nombre: '', folio: '' as number | '', fecha: hoy, descripcion: '', bruto: '' as number | '', centro_costo_id: null as number | null });
const retencion = computed(() => Math.round((Number(form.bruto) || 0) * props.tasa / 100));
const guardar = () => form.post('/finanzas/honorarios', { preserveScroll: true, onSuccess: () => { nueva.value = false; form.reset(); } });

const pagando = ref<Boleta | null>(null);
const pago = useForm({ fecha_pago: hoy });
const pagar = () => pago.post(`/finanzas/honorarios/${pagando.value!.id}/pagar`, { preserveScroll: true, onSuccess: () => { pagando.value = null; } });

const anulando = ref<Boleta | null>(null);
const anula = useForm({ motivo: '' });
const anular = () => anula.post(`/finanzas/honorarios/${anulando.value!.id}/anular`, { preserveScroll: true, onSuccess: () => { anulando.value = null; anula.reset(); } });
</script>

<template>
    <Head title="Libro de honorarios" />
    <Encabezado titulo="Libro de honorarios" :bajada="`Boletas de honorarios recibidas. La retención (${tasa.toLocaleString('es-CL')}%) se calcula sola y se contabiliza como impuesto por pagar.`">
        <input v-model="mes" type="month" class="campo h-12 w-auto" aria-label="Mes" />
        <a v-if="puede('finanzas.libro-de-honorarios.exportar')" :href="`/finanzas/honorarios?mes=${mes}&exportar=1`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <button v-if="puede('finanzas.libro-de-honorarios.crear')" class="btn btn-primario" @click="nueva = true"><Icono nombre="mas" :tamano="18" /> Registrar boleta</button>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Boletas" :valor="totales.cantidad" />
        <Kpi etiqueta="Bruto" :valor="pesos(totales.bruto)" />
        <Kpi etiqueta="Retención" :valor="pesos(totales.retencion)" tono="naranja" />
        <Kpi etiqueta="Líquido" :valor="pesos(totales.liquido)" />
    </div>

    <div class="tarjeta">
        <div class="overflow-x-auto">
            <table v-if="boletas.data.length" class="tabla">
                <thead><tr><th>Boleta</th><th>Fecha</th><th>Prestador</th><th>Detalle</th><th class="text-right">Bruto</th><th class="text-right">Retención</th><th class="text-right">Líquido</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="b in boletas.data" :key="b.id" :class="b.estado === 'anulada' && 'text-grafito line-through'">
                        <td class="num font-semibold">N° {{ b.folio }}</td>
                        <td class="num">{{ fecha(b.fecha) }}</td>
                        <td>{{ b.nombre }}<div class="num text-[13px] text-grafito">{{ rut(b.rut) }}</div></td>
                        <td>{{ b.descripcion }}<div v-if="b.centro" class="text-[13px] text-grafito">{{ b.centro }}</div></td>
                        <td class="num text-right">{{ pesos(b.bruto) }}</td>
                        <td class="num text-right">{{ pesos(b.retencion) }}</td>
                        <td class="num text-right font-semibold">{{ pesos(b.liquido) }}</td>
                        <td><Chip chico :tono="tono[b.estado]">{{ estados[b.estado] }}</Chip><div v-if="b.fecha_pago" class="num text-[13px] text-grafito">{{ fecha(b.fecha_pago) }}</div></td>
                        <td class="whitespace-nowrap text-right">
                            <template v-if="b.estado === 'por_pagar'">
                                <button v-if="puede('finanzas.libro-de-honorarios.editar')" class="btn btn-secundario btn-sm" @click="pagando = b">Pagar</button>
                                <button v-if="puede('finanzas.libro-de-honorarios.eliminar')" class="btn btn-texto ml-3 text-[15px]" @click="anulando = b">Anular</button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin boletas en el período" texto="Registra las boletas de honorarios recibidas para llevar la retención." />
        </div>
        <Paginacion :datos="boletas" nombre="boletas" />
    </div>

    <Modal :abierto="nueva" titulo="Registrar boleta de honorarios" @cerrar="nueva = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="RUT del prestador" id="h-rut" obligatorio :error="form.errors.rut"><input id="h-rut" v-model="form.rut" class="campo h-11" maxlength="12" /></Campo>
            <Campo etiqueta="Nombre" id="h-nombre" obligatorio :error="form.errors.nombre"><input id="h-nombre" v-model="form.nombre" class="campo h-11" maxlength="100" /></Campo>
            <Campo etiqueta="N° de boleta" id="h-folio" obligatorio :error="form.errors.folio"><input id="h-folio" v-model.number="form.folio" type="number" min="1" class="campo h-11" /></Campo>
            <Campo etiqueta="Fecha" id="h-fecha" obligatorio :error="form.errors.fecha"><input id="h-fecha" v-model="form.fecha" type="date" :max="hoy" class="campo h-11" /></Campo>
            <Campo etiqueta="Detalle del servicio" id="h-desc" obligatorio :error="form.errors.descripcion" class="sm:col-span-2"><input id="h-desc" v-model="form.descripcion" class="campo h-11" maxlength="200" /></Campo>
            <Campo etiqueta="Monto bruto" id="h-bruto" obligatorio :error="form.errors.bruto"><input id="h-bruto" v-model.number="form.bruto" type="number" min="1" class="campo h-11" /></Campo>
            <Campo etiqueta="Centro de costo" id="h-centro" :error="form.errors.centro_costo_id">
                <select id="h-centro" v-model="form.centro_costo_id" class="campo h-11"><option :value="null">Sin asignar</option><option v-for="c in centros" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select>
            </Campo>
        </div>
        <p class="mt-4 rounded-boton bg-hormigon p-3 text-[14px]">Retención {{ tasa.toLocaleString('es-CL') }}%: <strong class="num">{{ pesos(retencion) }}</strong> · líquido a pagar: <strong class="num">{{ pesos((Number(form.bruto) || 0) - retencion) }}</strong></p>
        <template #pie>
            <button class="btn btn-secundario" @click="nueva = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Registrar</button>
        </template>
    </Modal>

    <Modal :abierto="pagando !== null" titulo="Registrar pago de boleta" @cerrar="pagando = null">
        <p v-if="pagando" class="mb-4">Boleta N° {{ pagando.folio }} de {{ pagando.nombre }}: se paga el líquido de <strong class="num">{{ pesos(pagando.liquido) }}</strong> desde el banco.</p>
        <Campo etiqueta="Fecha de pago" id="h-fpago" obligatorio :error="pago.errors.fecha_pago || (pago.errors as any).honorario"><input id="h-fpago" v-model="pago.fecha_pago" type="date" :max="hoy" class="campo h-11" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="pagando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="pago.processing" @click="pagar">Registrar pago</button>
        </template>
    </Modal>

    <Modal :abierto="anulando !== null" titulo="Anular boleta" @cerrar="anulando = null">
        <p class="mb-4 text-grafito">Su asiento contable se revierte. La boleta queda en el libro como anulada.</p>
        <Campo etiqueta="Motivo" id="h-motivo" obligatorio :error="anula.errors.motivo || (anula.errors as any).honorario"><input id="h-motivo" v-model="anula.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="anulando = null">Cancelar</button>
            <button class="btn btn-peligro" :disabled="anula.processing" @click="anular">Anular</button>
        </template>
    </Modal>
</template>
