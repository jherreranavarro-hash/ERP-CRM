<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, fechaHora, pesos } from '@/lib/formato';
import { http } from '@/lib/http';
import { usePermisos } from '@/lib/permisos';

interface CuentaB { id: number; banco: string; numero: string; nombre: string; activa?: boolean }
interface Mov { id: number; fecha: string; glosa: string; documento: string | null; monto: number; estado: string; forma: string | null; contraparte: { texto: string; url: string | null } | null }
interface Candidato { tipo: 'pago' | 'pago_proveedor'; id: number; fecha: string; monto: number; texto: string; referencia: string | null }
interface Cartola { id: number; archivo: string; desde: string | null; hasta: string | null; movimientos: number; duplicados: number; conciliados: number; importador: string | null; fecha: string }
type Opcion = { id: number; codigo: string; nombre: string };
const props = defineProps<{
    cuentas: CuentaB[];
    cuenta: (CuentaB & { saldo_cartola: number; saldo_contable: number }) | null;
    resumen: { clave: string; nombre: string; cantidad: number; monto: number }[];
    movimientos: Paginado<Mov>;
    cartolas: Cartola[];
    filtros: { estado: string; buscar?: string | null };
    cuentasContables: Opcion[];
    centros: Opcion[];
}>();
const puede = usePermisos();
const tono: Record<string, 'alerta' | 'ok' | 'neutro'> = { pendiente: 'alerta', conciliado: 'ok', ignorado: 'neutro' };

const f = reactive({ estado: props.filtros.estado, buscar: props.filtros.buscar ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/finanzas/conciliacion', { cuenta: props.cuenta?.id, ...f }, { preserveState: true, replace: true }), 300); });
const elegirCuenta = (id: number) => router.get('/finanzas/conciliacion', { cuenta: id });

// Nueva cuenta bancaria
const nuevaCuenta = ref(false);
const fc = useForm({ banco: '', numero: '', nombre: '' });
const crearCuenta = () => fc.post('/finanzas/conciliacion/cuentas', { onSuccess: () => { nuevaCuenta.value = false; fc.reset(); } });

// Importar cartola
const importando = ref(false);
const fi = useForm({ archivo: null as File | null });
const importar = () => fi.post(`/finanzas/conciliacion/${props.cuenta!.id}/importar`, { forceFormData: true, preserveScroll: true, onSuccess: () => { importando.value = false; fi.reset(); } });
const automatico = () => router.post(`/finanzas/conciliacion/${props.cuenta!.id}/automatico`, {}, { preserveScroll: true });

// Conciliar un movimiento
const actual = ref<Mov | null>(null);
const candidatos = ref<Candidato[] | null>(null);
const modo = ref<'calce' | 'asiento'>('calce');
const fa = useForm({ cuenta_id: null as number | null, glosa: '', centro_costo_id: null as number | null });
const errorCalce = ref('');
async function abrir(m: Mov) {
    actual.value = m;
    candidatos.value = null;
    errorCalce.value = '';
    modo.value = 'calce';
    fa.reset();
    fa.clearErrors();
    fa.glosa = m.glosa;
    candidatos.value = (await http.get<Candidato[]>(`/finanzas/conciliacion/movimientos/${m.id}/candidatos`)).data;
    if (!candidatos.value.length) modo.value = 'asiento';
}
const cerrar = () => { actual.value = null; };
const conciliar = (c: Candidato) => router.post(`/finanzas/conciliacion/movimientos/${actual.value!.id}/conciliar`, { tipo: c.tipo, id: c.id }, {
    preserveScroll: true, onSuccess: cerrar, onError: (e) => { errorCalce.value = Object.values(e)[0] ?? 'No se pudo conciliar.'; },
});
const contabilizar = () => fa.post(`/finanzas/conciliacion/movimientos/${actual.value!.id}/contabilizar`, { preserveScroll: true, onSuccess: cerrar });
const ignorar = (m: Mov) => confirm('¿Marcar el movimiento como ignorado? No afectará la contabilidad.') && router.post(`/finanzas/conciliacion/movimientos/${m.id}/ignorar`, {}, { preserveScroll: true });
const deshacer = (m: Mov) => confirm('¿Deshacer la conciliación? Si generó un asiento, se revierte.') && router.post(`/finanzas/conciliacion/movimientos/${m.id}/deshacer`, {}, { preserveScroll: true });
</script>

<template>
    <Head title="Conciliación bancaria" />
    <Encabezado titulo="Conciliación bancaria" bajada="Importa la cartola del banco en CSV: los movimientos que calzan en monto y fecha con cobros y pagos registrados se concilian solos.">
        <select v-if="cuentas.length > 1" :value="cuenta?.id" class="campo h-12 w-auto" aria-label="Cuenta bancaria" @change="elegirCuenta(Number(($event.target as HTMLSelectElement).value))">
            <option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.nombre }} · {{ c.banco }} {{ c.numero }}</option>
        </select>
        <a v-if="cuenta && puede('finanzas.conciliacion-bancaria.exportar')" :href="`/finanzas/conciliacion?cuenta=${cuenta.id}&estado=${f.estado}&exportar=1`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <button v-if="puede('finanzas.conciliacion-bancaria.crear')" class="btn btn-secundario" @click="nuevaCuenta = true"><Icono nombre="mas" :tamano="18" /> Cuenta bancaria</button>
        <button v-if="cuenta && puede('finanzas.conciliacion-bancaria.crear')" class="btn btn-primario" @click="importando = true"><Icono nombre="subir" :tamano="18" /> Importar cartola</button>
    </Encabezado>

    <Vacio v-if="!cuenta" class="tarjeta" titulo="Sin cuentas bancarias" texto="Crea la cuenta corriente de la empresa para importar sus cartolas y conciliarlas." />

    <template v-else>
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <Kpi etiqueta="Saldo según cartolas" :valor="pesos(cuenta.saldo_cartola)" />
            <Kpi etiqueta="Saldo contable banco" :valor="pesos(cuenta.saldo_contable)" />
            <Kpi v-for="r in resumen" :key="r.clave" :etiqueta="r.nombre" :valor="r.cantidad" :tono="r.clave === 'pendiente' && r.cantidad ? 'naranja' : 'normal'">{{ pesos(r.monto) }}</Kpi>
        </div>

        <div class="grid gap-6 xl:grid-cols-4">
            <div class="tarjeta xl:col-span-3">
                <div class="flex flex-wrap items-center gap-3 border-b border-linea p-4">
                    <div class="flex gap-2" role="tablist">
                        <button v-for="r in resumen" :key="r.clave" role="tab" :aria-selected="f.estado === r.clave" class="btn btn-sm" :class="f.estado === r.clave ? 'btn-oscuro' : 'btn-secundario'" @click="f.estado = r.clave">{{ r.nombre }} · {{ r.cantidad }}</button>
                    </div>
                    <div class="relative ml-auto w-full max-w-xs">
                        <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                        <input v-model="f.buscar" class="campo h-10 pl-10" placeholder="Glosa o documento" aria-label="Buscar" />
                    </div>
                    <button v-if="f.estado === 'pendiente' && puede('finanzas.conciliacion-bancaria.editar')" class="btn btn-secundario btn-sm" @click="automatico">Conciliar automáticamente</button>
                </div>
                <div class="overflow-x-auto">
                    <table v-if="movimientos.data.length" class="tabla">
                        <thead><tr><th>Fecha</th><th>Glosa</th><th class="text-right">Monto</th><th>Estado</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="m in movimientos.data" :key="m.id">
                                <td class="num">{{ fecha(m.fecha) }}</td>
                                <td>{{ m.glosa }}<div v-if="m.documento" class="num text-[13px] text-grafito">Doc. {{ m.documento }}</div>
                                    <div v-if="m.contraparte" class="text-[13px]"><Link v-if="m.contraparte.url" :href="m.contraparte.url">{{ m.contraparte.texto }}</Link><span v-else>{{ m.contraparte.texto }}</span><span v-if="m.forma" class="text-grafito"> · {{ m.forma }}</span></div>
                                </td>
                                <td class="num text-right font-semibold" :class="m.monto < 0 ? 'text-estado-error' : 'text-estado-ok'">{{ pesos(m.monto) }}</td>
                                <td><Chip chico :tono="tono[m.estado]">{{ resumen.find((r) => r.clave === m.estado)?.nombre }}</Chip></td>
                                <td class="whitespace-nowrap text-right">
                                    <template v-if="puede('finanzas.conciliacion-bancaria.editar')">
                                        <template v-if="m.estado === 'pendiente'">
                                            <button class="btn btn-primario btn-sm" @click="abrir(m)">Conciliar</button>
                                            <button class="btn btn-texto ml-3 text-[15px]" @click="ignorar(m)">Ignorar</button>
                                        </template>
                                        <button v-else class="btn btn-secundario btn-sm" @click="deshacer(m)">Deshacer</button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <Vacio v-else :titulo="f.estado === 'pendiente' ? 'Nada pendiente' : 'Sin movimientos'" :texto="f.estado === 'pendiente' ? 'Todos los movimientos importados están conciliados o ignorados.' : undefined" />
                </div>
                <Paginacion :datos="movimientos" nombre="movimientos" />
            </div>

            <section class="tarjeta self-start">
                <h2 class="border-b border-linea p-4 text-[18px] font-bold">Cartolas importadas</h2>
                <ul v-if="cartolas.length" class="divide-y divide-linea">
                    <li v-for="c in cartolas" :key="c.id" class="p-4 text-[14px]">
                        <p class="break-all font-semibold">{{ c.archivo }}</p>
                        <p class="num text-grafito">{{ fecha(c.desde) }} – {{ fecha(c.hasta) }}</p>
                        <p>{{ c.movimientos }} nuevos<span v-if="c.duplicados"> · {{ c.duplicados }} repetidos</span> · {{ c.conciliados }} conciliados</p>
                        <p class="text-grafito">{{ c.importador }} · {{ fechaHora(c.fecha) }}</p>
                    </li>
                </ul>
                <p v-else class="p-4 text-grafito">Aún no se importa ninguna cartola.</p>
            </section>
        </div>
    </template>

    <Modal :abierto="nuevaCuenta" titulo="Nueva cuenta bancaria" @cerrar="nuevaCuenta = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Banco" id="cb-banco" obligatorio :error="fc.errors.banco"><input id="cb-banco" v-model="fc.banco" class="campo h-11" maxlength="60" /></Campo>
            <Campo etiqueta="N° de cuenta" id="cb-numero" obligatorio :error="fc.errors.numero"><input id="cb-numero" v-model="fc.numero" class="campo h-11" maxlength="30" /></Campo>
            <Campo etiqueta="Nombre" id="cb-nombre" obligatorio :error="fc.errors.nombre" ayuda="Ej. Cuenta corriente principal" class="sm:col-span-2"><input id="cb-nombre" v-model="fc.nombre" class="campo h-11" maxlength="100" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="nuevaCuenta = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fc.processing" @click="crearCuenta">Crear</button>
        </template>
    </Modal>

    <Modal :abierto="importando" titulo="Importar cartola" @cerrar="importando = false">
        <p class="mb-4 text-grafito">Archivo CSV exportado desde el banco, con columnas de fecha, descripción y monto (o cargo y abono). Los movimientos ya importados se omiten.</p>
        <Campo etiqueta="Archivo CSV" id="cart-archivo" obligatorio :error="fi.errors.archivo"><input id="cart-archivo" type="file" accept=".csv,.txt" class="campo h-auto py-2" @change="fi.archivo = ($event.target as HTMLInputElement).files?.[0] ?? null" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="importando = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fi.processing || !fi.archivo" @click="importar">Importar</button>
        </template>
    </Modal>

    <Modal :abierto="actual !== null" titulo="Conciliar movimiento" @cerrar="cerrar">
        <template v-if="actual">
            <p class="mb-4 rounded-boton bg-hormigon p-3"><span class="num">{{ fecha(actual.fecha) }}</span> · {{ actual.glosa }} · <strong class="num">{{ pesos(actual.monto) }}</strong></p>
            <div class="mb-4 flex gap-2" role="tablist">
                <button role="tab" :aria-selected="modo === 'calce'" class="btn btn-sm" :class="modo === 'calce' ? 'btn-oscuro' : 'btn-secundario'" @click="modo = 'calce'">Con un {{ actual.monto > 0 ? 'cobro' : 'pago' }} registrado</button>
                <button role="tab" :aria-selected="modo === 'asiento'" class="btn btn-sm" :class="modo === 'asiento' ? 'btn-oscuro' : 'btn-secundario'" @click="modo = 'asiento'">Contabilizar directo</button>
            </div>
            <div v-if="modo === 'calce'">
                <p v-if="candidatos === null" class="text-grafito">Buscando…</p>
                <p v-else-if="!candidatos.length" class="text-grafito">No hay {{ actual.monto > 0 ? 'cobros' : 'pagos' }} sin conciliar por {{ pesos(Math.abs(actual.monto)) }} cerca de esa fecha. Contabilízalo directo (comisiones, intereses, transferencias).</p>
                <ul v-else class="divide-y divide-linea rounded-boton border border-linea">
                    <li v-for="c in candidatos" :key="`${c.tipo}-${c.id}`" class="flex items-center justify-between gap-3 p-3">
                        <div><p class="font-semibold">{{ c.texto }}</p><p class="num text-[13px] text-grafito">{{ fecha(c.fecha) }} · {{ pesos(c.monto) }}<span v-if="c.referencia"> · ref. {{ c.referencia }}</span></p></div>
                        <button class="btn btn-primario btn-sm" @click="conciliar(c)">Elegir</button>
                    </li>
                </ul>
                <p v-if="errorCalce" class="error mt-2" role="alert">{{ errorCalce }}</p>
            </div>
            <div v-else class="grid gap-4">
                <Campo etiqueta="Contrapartida" id="mv-cuenta" obligatorio :error="fa.errors.cuenta_id || (fa.errors as any).movimiento" :ayuda="`El banco va al ${actual.monto > 0 ? 'debe' : 'haber'}; esta cuenta, al otro lado.`">
                    <select id="mv-cuenta" v-model="fa.cuenta_id" class="campo h-11"><option :value="null">Elige una cuenta</option><option v-for="c in cuentasContables" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select>
                </Campo>
                <Campo etiqueta="Glosa" id="mv-glosa" :error="fa.errors.glosa"><input id="mv-glosa" v-model="fa.glosa" class="campo h-11" maxlength="200" /></Campo>
                <Campo etiqueta="Centro de costo" id="mv-centro" :error="fa.errors.centro_costo_id">
                    <select id="mv-centro" v-model="fa.centro_costo_id" class="campo h-11"><option :value="null">Sin asignar</option><option v-for="c in centros" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select>
                </Campo>
            </div>
        </template>
        <template #pie>
            <button class="btn btn-secundario" @click="cerrar">Cancelar</button>
            <button v-if="modo === 'asiento'" class="btn btn-primario" :disabled="fa.processing || !fa.cuenta_id" @click="contabilizar">Contabilizar</button>
        </template>
    </Modal>
</template>
