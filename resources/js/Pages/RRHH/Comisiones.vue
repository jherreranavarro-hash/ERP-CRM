<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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
import { usePermisos } from '@/lib/permisos';

interface Comision { id: number; empleado: string; documento: string | null; documento_id: number | null; cliente: string | null; base: number; tasa: number; monto: number; estado: string }
const props = defineProps<{
    periodo: string; porVendedor: { empleado: string; documentos: number; base: number; monto: number; pendientes: number }[]; comisiones: Paginado<Comision>;
    totales: { monto: number; pendientes: number }; nominaCerrada: boolean; reglas: { id: number; categoria: string | null; empleado: string | null; tasa: number }[];
    categorias: { id: number; nombre: string }[]; vendedores: { id: number; nombre: string }[];
}>();
const puede = usePermisos();
const cambiarPeriodo = (p: string) => router.get('/rrhh/comisiones', { periodo: p }, { preserveState: true, replace: true });
const fa = useForm({ periodo: props.periodo });
const aprobar = () => fa.transform(() => ({ periodo: props.periodo })).post('/rrhh/comisiones/aprobar', { preserveScroll: true });

const reglando = ref(false);
const fr = useForm({ categoria_id: null as number | null, empleado_id: null as number | null, tasa: null as number | null });
const guardarRegla = () => fr.post('/rrhh/comisiones/reglas', { preserveScroll: true, onSuccess: () => { fr.reset(); } });
const eliminarRegla = (id: number) => router.delete(`/rrhh/comisiones/reglas/${id}`, { preserveScroll: true });
const tasa = (t: number) => `${t.toLocaleString('es-CL', { maximumFractionDigits: 2 })}%`;
</script>

<template>
    <Head title="Comisiones" />
    <Encabezado titulo="Comisiones de venta" bajada="Se calculan al emitir cada documento según las reglas por categoría (o la tasa del contrato del vendedor). Las notas de crédito restan. Se aprueban por mes antes de la nómina.">
        <input :value="periodo" type="month" class="campo h-11 w-44" aria-label="Período" @change="cambiarPeriodo(($event.target as HTMLInputElement).value)" />
        <a v-if="puede('rrhh.comisiones-de-venta.exportar')" :href="`/rrhh/comisiones?periodo=${periodo}&exportar=1`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <button v-if="puede('rrhh.comisiones-de-venta.aprobar')" class="btn btn-secundario" @click="reglando = true">Reglas</button>
        <button v-if="puede('rrhh.comisiones-de-venta.aprobar') && totales.pendientes && !nominaCerrada" class="btn btn-primario" :disabled="fa.processing" @click="aprobar">Aprobar {{ totales.pendientes }} pendientes</button>
    </Encabezado>

    <p v-if="nominaCerrada" class="tarjeta mb-6 p-4 text-grafito">La nómina de este mes ya está aprobada: las comisiones nuevas del período no entran en ella.</p>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Comisiones del mes" :valor="pesos(totales.monto)" tono="naranja" />
        <Kpi etiqueta="Pendientes de aprobar" :valor="totales.pendientes" />
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="tarjeta self-start">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Por vendedor</h2>
            <ul v-if="porVendedor.length" class="divide-y divide-linea">
                <li v-for="v in porVendedor" :key="v.empleado" class="flex justify-between gap-3 p-4 text-[14px]">
                    <div><p class="font-semibold">{{ v.empleado }}</p><p class="text-grafito">{{ v.documentos }} documentos · ventas {{ pesos(v.base) }}</p></div>
                    <div class="text-right"><p class="num font-semibold">{{ pesos(v.monto) }}</p><p v-if="v.pendientes" class="text-[13px] text-grafito">{{ v.pendientes }} pendientes</p></div>
                </li>
            </ul>
            <p v-else class="p-4 text-grafito">Sin ventas con vendedor en el mes.</p>
        </section>
        <div class="xl:col-span-2">
            <div v-if="comisiones.data.length" class="tarjeta overflow-x-auto">
                <table class="tabla">
                    <thead><tr><th>Documento</th><th>Vendedor</th><th class="text-right">Base neta</th><th class="text-right">Tasa</th><th class="text-right">Comisión</th><th>Estado</th></tr></thead>
                    <tbody>
                        <tr v-for="c in comisiones.data" :key="c.id">
                            <td><Link v-if="c.documento_id" :href="`/caja/documentos/${c.documento_id}`" class="font-semibold">{{ c.documento }}</Link><p class="text-[13px] text-grafito">{{ c.cliente }}</p></td>
                            <td>{{ c.empleado }}</td>
                            <td class="num text-right">{{ pesos(c.base) }}</td>
                            <td class="num text-right">{{ tasa(c.tasa) }}</td>
                            <td class="num text-right font-semibold" :class="{ 'text-estado-error': c.monto < 0 }">{{ pesos(c.monto) }}</td>
                            <td><Chip chico :tono="c.estado === 'aprobada' ? 'ok' : 'alerta'">{{ c.estado === 'aprobada' ? 'Aprobada' : 'Pendiente' }}</Chip></td>
                        </tr>
                    </tbody>
                </table>
                <Paginacion :datos="comisiones" nombre="comisiones" />
            </div>
            <Vacio v-else class="tarjeta" titulo="Sin comisiones en el mes" />
        </div>
    </div>

    <Modal :abierto="reglando" titulo="Reglas de comisión" @cerrar="reglando = false">
        <p class="mb-4 text-grafito">La regla del vendedor prevalece sobre la general de la categoría. Sin regla, se usa la tasa del contrato. Aplican a las ventas que se emitan desde ahora.</p>
        <ul v-if="reglas.length" class="mb-4 divide-y divide-linea rounded-boton border border-linea text-[14px]">
            <li v-for="r in reglas" :key="r.id" class="flex items-center justify-between gap-3 p-3">
                <span><span class="font-semibold">{{ r.categoria }}</span> · {{ r.empleado ?? 'Todos los vendedores' }}</span>
                <span class="flex items-center gap-3"><span class="num font-semibold">{{ tasa(r.tasa) }}</span><button class="btn btn-texto btn-sm" :aria-label="`Eliminar regla ${r.categoria}`" @click="eliminarRegla(r.id)"><Icono nombre="cerrar" :tamano="16" /></button></span>
            </li>
        </ul>
        <div class="grid gap-3 sm:grid-cols-[1fr_1fr_110px]">
            <Campo etiqueta="Categoría" id="rc-cat" :error="fr.errors.categoria_id"><select id="rc-cat" v-model="fr.categoria_id" class="campo h-11"><option :value="null" disabled>Selecciona…</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option></select></Campo>
            <Campo etiqueta="Vendedor" id="rc-emp" :error="fr.errors.empleado_id"><select id="rc-emp" v-model="fr.empleado_id" class="campo h-11"><option :value="null">Todos</option><option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.nombre }}</option></select></Campo>
            <Campo etiqueta="Tasa %" id="rc-tasa" :error="fr.errors.tasa"><input id="rc-tasa" v-model.number="fr.tasa" type="number" min="0" max="30" step="0.01" class="campo num h-11" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="reglando = false">Cerrar</button>
            <button class="btn btn-primario" :disabled="fr.processing || !fr.categoria_id || fr.tasa === null" @click="guardarRegla">Guardar regla</button>
        </template>
    </Modal>
</template>
