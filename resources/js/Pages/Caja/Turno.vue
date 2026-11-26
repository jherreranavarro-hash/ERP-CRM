<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Turno {
    id: number; caja: string; local: string | null; abierto_at: string; monto_apertura: number;
    resumen: { esperado: Record<string, number>; otros: Record<string, number>; ventas: number; documentos: number };
    pagos: { id: number; documento_id: number; documento: string | null; monto: number; metodo: string; hora: string }[];
}
const props = defineProps<{ turno: Turno | null; cajas: { id: number; nombre: string; local: string | null; ocupada_por: string | null }[]; locales: { id: number; nombre: string }[]; medios: Record<string, string> }>();
const puede = usePermisos();

const apertura = useForm({ caja_id: props.cajas.find((c) => !c.ocupada_por)?.id ?? null, monto_apertura: 0 });
const abrir = () => apertura.post('/caja/turno/abrir', { preserveScroll: true });

// Cierre a ciegas: la cajera cuenta y declara sin ver el esperado.
const cierre = useForm({ declarado: Object.fromEntries(Object.keys(props.medios).map((m) => [m, null])) as Record<string, number | null>, observacion: '' });
const cierreAbierto = ref(false);
const cerrar = () => cierre.post('/caja/turno/cerrar');
const erroresCierre = computed(() => Object.entries(cierre.errors as Record<string, string>).filter(([k]) => k !== 'observacion').map(([, v]) => v));

const nuevaCaja = useForm({ local_id: props.locales[0]?.id ?? null, nombre: '' });
const cajaAbierta = ref(false);
</script>

<template>
    <Head title="Caja" />
    <Encabezado titulo="Caja" bajada="Abre tu turno con el fondo inicial, cobra desde los documentos y ciérralo contando lo que hay por medio de pago.">
        <button v-if="locales.length" class="btn btn-secundario" @click="cajaAbierta = true"><Icono nombre="mas" :tamano="18" /> Nueva caja</button>
        <Link v-if="turno && puede('caja.facturas-de-ventas.crear')" href="/caja/documentos/crear" class="btn btn-secundario no-underline">Nueva venta</Link>
        <button v-if="turno && puede('caja.cierres-de-caja.crear')" class="btn btn-primario" @click="cierreAbierto = true">Cerrar turno</button>
    </Encabezado>

    <template v-if="turno">
        <div class="mb-6 flex flex-wrap items-center gap-2">
            <Chip tono="ok">Turno abierto</Chip>
            <span class="text-[15px] text-grafito">{{ turno.caja }}{{ turno.local ? ` · ${turno.local}` : '' }} · desde {{ fechaHora(turno.abierto_at) }}</span>
        </div>
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi etiqueta="Cobrado en el turno" :valor="pesos(turno.resumen.ventas)" tono="naranja">{{ turno.resumen.documentos }} documentos</Kpi>
            <Kpi etiqueta="Fondo inicial" :valor="pesos(turno.monto_apertura)" />
            <Kpi etiqueta="Tarjetas" :valor="pesos((turno.resumen.esperado.debito ?? 0) + (turno.resumen.esperado.credito ?? 0))">Débito y crédito</Kpi>
            <Kpi etiqueta="Otros medios" :valor="pesos(Object.values(turno.resumen.otros).reduce((a, b) => a + b, 0))">Transferencias y cheques, fuera del arqueo</Kpi>
        </div>
        <section class="tarjeta overflow-x-auto">
            <h2 class="titulo-ancho px-5 pt-5 text-[16px] font-bold">Cobros del turno</h2>
            <table v-if="turno.pagos.length" class="tabla">
                <thead><tr><th>Hora</th><th>Documento</th><th>Medio</th><th class="text-right">Monto</th></tr></thead>
                <tbody><tr v-for="p in turno.pagos" :key="p.id"><td class="num whitespace-nowrap">{{ fechaHora(p.hora) }}</td><td><Link :href="`/caja/documentos/${p.documento_id}`">{{ p.documento }}</Link></td><td>{{ p.metodo }}</td><td class="num text-right font-semibold">{{ pesos(p.monto) }}</td></tr></tbody>
            </table>
            <Vacio v-else titulo="Sin cobros todavía" texto="Los pagos que registres en los documentos quedan en este turno." />
        </section>
    </template>

    <section v-else class="tarjeta max-w-xl p-5">
        <h2 class="titulo-ancho mb-1 text-[18px] font-bold">Abrir turno</h2>
        <p class="mb-5 text-[15px] text-grafito">Para cobrar en efectivo o con tarjeta necesitas un turno abierto.</p>
        <form v-if="cajas.length" class="space-y-5" @submit.prevent="abrir">
            <Campo etiqueta="Caja" id="caja_id" :error="apertura.errors.caja_id">
                <select id="caja_id" v-model.number="apertura.caja_id" class="campo h-11">
                    <option v-for="c in cajas" :key="c.id" :value="c.id" :disabled="!!c.ocupada_por">{{ c.nombre }}{{ c.local ? ` · ${c.local}` : '' }}{{ c.ocupada_por ? ` (en uso por ${c.ocupada_por})` : '' }}</option>
                </select>
            </Campo>
            <Campo etiqueta="Fondo inicial en efectivo" id="monto_apertura" :error="apertura.errors.monto_apertura" ayuda="Lo que hay en el cajón al empezar.">
                <input id="monto_apertura" v-model.number="apertura.monto_apertura" type="number" min="0" step="1" class="campo num h-11" />
            </Campo>
            <button type="submit" class="btn btn-primario" :disabled="apertura.processing || !apertura.caja_id || !puede('caja.caja.crear')">Abrir turno</button>
        </form>
        <Vacio v-else titulo="No hay cajas" texto="Pide a un administrador que cree una caja para tu local." />
    </section>

    <Modal :abierto="cierreAbierto" titulo="Cerrar turno" @cerrar="cierreAbierto = false">
        <p class="mb-4 text-[15px] text-grafito">Cuenta el efectivo (incluido el fondo) y suma los comprobantes de tarjeta. El sistema compara con lo registrado al confirmar.</p>
        <form id="form-cierre" class="grid gap-5 sm:grid-cols-3" @submit.prevent="cerrar">
            <Campo v-for="(n, m) in medios" :key="m" :etiqueta="n" :id="`dec-${m}`" :error="(cierre.errors as any)[`declarado.${m}`]">
                <input :id="`dec-${m}`" v-model.number="cierre.declarado[m]" type="number" min="0" step="1" class="campo num h-11" required />
            </Campo>
            <Campo class="sm:col-span-3" etiqueta="Observación" id="observacion" :error="cierre.errors.observacion" ayuda="Obligatoria si hay diferencia.">
                <textarea id="observacion" v-model="cierre.observacion" rows="2" class="campo" maxlength="300" />
            </Campo>
        </form>
        <p v-for="e in erroresCierre" :key="e" class="error mt-2">{{ e }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="cierreAbierto = false">Cancelar</button>
            <button type="submit" form="form-cierre" class="btn btn-primario" :disabled="cierre.processing">Cerrar turno</button>
        </template>
    </Modal>

    <Modal :abierto="cajaAbierta" titulo="Nueva caja" @cerrar="cajaAbierta = false">
        <form id="form-caja" class="grid gap-5 sm:grid-cols-2" @submit.prevent="nuevaCaja.post('/caja/cajas', { preserveScroll: true, onSuccess: () => { cajaAbierta = false; nuevaCaja.reset('nombre'); } })">
            <Campo etiqueta="Local" id="local_caja" :error="nuevaCaja.errors.local_id"><select id="local_caja" v-model.number="nuevaCaja.local_id" class="campo h-11"><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select></Campo>
            <Campo etiqueta="Nombre" id="nombre_caja" :error="nuevaCaja.errors.nombre"><input id="nombre_caja" v-model="nuevaCaja.nombre" class="campo h-11" maxlength="60" placeholder="Caja 1" /></Campo>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="cajaAbierta = false">Cancelar</button>
            <button type="submit" form="form-caja" class="btn btn-primario" :disabled="nuevaCaja.processing">Crear</button>
        </template>
    </Modal>
</template>
