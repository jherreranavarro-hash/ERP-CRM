<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import { fecha, fechaHora, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Linea { id: number; cuenta: string; cuenta_id: number; centro: string | null; debe: number; haber: number; glosa: string | null }
interface Ref { id: number; numero: number }
const props = defineProps<{ asiento: { id: number; numero: number; fecha: string; glosa: string; tipo: string; total: number; creador: string | null; creado_at: string | null; origen: { texto: string; url: string | null } | null; revierte: Ref | null; reverso: Ref | null; lineas: Linea[] }; tipos: Record<string, string> }>();
const puede = usePermisos();
const abierto = ref(false);
const form = useForm({ motivo: '' });
const revertir = () => form.post(`/finanzas/asientos/${props.asiento.id}/revertir`, { onSuccess: () => { abierto.value = false; } });
</script>

<template>
    <Head :title="`Asiento N° ${asiento.numero}`" />
    <Encabezado :titulo="`Asiento N° ${asiento.numero}`" :bajada="asiento.glosa">
        <Link href="/finanzas/asientos" class="btn btn-secundario no-underline">Libro diario</Link>
        <button v-if="puede('finanzas.asientos-contables.eliminar') && asiento.tipo !== 'reverso' && !asiento.reverso" class="btn btn-peligro" @click="abierto = true">Revertir</button>
    </Encabezado>

    <div class="tarjeta mb-6 grid gap-4 p-4 sm:grid-cols-2 xl:grid-cols-4">
        <div><p class="etiqueta">Fecha</p><p class="num">{{ fecha(asiento.fecha) }}</p></div>
        <div><p class="etiqueta">Tipo</p><Chip chico :tono="asiento.tipo === 'automatico' ? 'info' : asiento.tipo === 'reverso' ? 'alerta' : 'neutro'">{{ tipos[asiento.tipo] }}</Chip></div>
        <div><p class="etiqueta">Origen</p><Link v-if="asiento.origen?.url" :href="asiento.origen.url">{{ asiento.origen.texto }}</Link><p v-else>{{ asiento.origen?.texto ?? 'Manual' }}</p></div>
        <div><p class="etiqueta">Registró</p><p>{{ asiento.creador ?? 'Sistema' }} <span class="num text-[13px] text-grafito">{{ fechaHora(asiento.creado_at) }}</span></p></div>
    </div>

    <p v-if="asiento.revierte" class="tarjeta mb-6 p-4">Este asiento revierte el <Link :href="`/finanzas/asientos/${asiento.revierte.id}`">N° {{ asiento.revierte.numero }}</Link>.</p>
    <p v-if="asiento.reverso" class="tarjeta mb-6 border-estado-error-borde bg-estado-error-bg p-4 text-estado-error">Revertido por el asiento <Link :href="`/finanzas/asientos/${asiento.reverso.id}`">N° {{ asiento.reverso.numero }}</Link>.</p>

    <div class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Cuenta</th><th>Centro</th><th>Detalle</th><th class="text-right">Debe</th><th class="text-right">Haber</th></tr></thead>
            <tbody>
                <tr v-for="l in asiento.lineas" :key="l.id">
                    <td :class="{ 'pl-10': l.haber > 0 }"><Link :href="`/finanzas/balances?vista=mayor&cuenta_id=${l.cuenta_id}`">{{ l.cuenta }}</Link></td>
                    <td>{{ l.centro ?? '—' }}</td>
                    <td>{{ l.glosa ?? '' }}</td>
                    <td class="num text-right">{{ l.debe ? pesos(l.debe) : '' }}</td>
                    <td class="num text-right">{{ l.haber ? pesos(l.haber) : '' }}</td>
                </tr>
            </tbody>
            <tfoot><tr class="font-semibold"><td colspan="3">Totales</td><td class="num text-right">{{ pesos(asiento.total) }}</td><td class="num text-right">{{ pesos(asiento.total) }}</td></tr></tfoot>
        </table>
    </div>

    <Modal :abierto="abierto" titulo="Revertir asiento" @cerrar="abierto = false">
        <p class="mb-4 text-grafito">Se contabiliza un asiento con las mismas líneas invertidas, con fecha de hoy. El original no se modifica.</p>
        <Campo etiqueta="Motivo" id="rev-motivo" obligatorio :error="form.errors.motivo || (form.errors as any).asiento"><input id="rev-motivo" v-model="form.motivo" class="campo h-11" maxlength="150" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-peligro" :disabled="form.processing" @click="revertir">Revertir</button>
        </template>
    </Modal>
</template>
