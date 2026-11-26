<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { cantidad, estadoDevolucion } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Linea { descripcion: string | null; codigo: string | null; unidad: string | null; cantidad: number; vendida: number; reingresa: boolean }
interface Dev {
    id: number; numero: number; estado: string; fecha: string; motivo: string; detalle: string; monto: number;
    documento: { id: number; nombre: string | null; cliente: string | null }; nota_credito: { id: number; nombre: string; total: number } | null;
    local: string | null; solicitante: string | null; aprobador: string | null; motivo_rechazo: string | null; propia: boolean; lineas: Linea[];
}
const props = defineProps<{ devolucion: Dev }>();
const puede = usePermisos();
const page = usePage();
const d = computed(() => props.devolucion);
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);
const puedeAprobar = computed(() => d.value.estado === 'solicitada' && !d.value.propia && (puede('inventario.devolucion.aprobar') || puede('caja.devolucion.aprobar')));
const aprobar = () => confirm('Se emitirá la nota de crédito al SII y el stock reingresa. ¿Continuar?') && router.post(`/inventario/devoluciones/${d.value.id}/aprobar`, {}, { preserveScroll: true });
const rechazo = useForm({ motivo: '' });
const rechazoAbierto = ref(false);
</script>

<template>
    <Head :title="`Devolución N° ${d.numero}`" />
    <Encabezado :titulo="`Devolución N° ${d.numero}`" volver="/inventario/devoluciones" :bajada="`${d.local ?? ''} · registrada por ${d.solicitante ?? '—'} el ${fechaHora(d.fecha)}`">
        <template v-if="puedeAprobar">
            <button class="btn btn-secundario" @click="rechazoAbierto = true">Rechazar</button>
            <button class="btn btn-primario" @click="aprobar">Aprobar y emitir NC</button>
        </template>
        <Link v-if="d.nota_credito" :href="`/caja/documentos/${d.nota_credito.id}`" class="btn btn-primario no-underline">Ver {{ d.nota_credito.nombre }}</Link>
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="estadoDevolucion[d.estado][0]">{{ estadoDevolucion[d.estado][1] }}</Chip>
        <Chip v-if="d.estado === 'aprobada'" tono="neutro">{{ d.aprobador ? `Aprobó ${d.aprobador}` : 'Bajo el umbral: sin aprobación' }}</Chip>
    </div>
    <div v-if="errores.length && !rechazoAbierto" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]"><ul class="list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul></div>
    <div v-if="d.estado === 'solicitada' && d.propia" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6] p-5 text-[#8A5A00]">Debe aprobarla otra persona. Al aprobarse se emite la nota de crédito.</div>
    <div v-if="d.motivo_rechazo" class="tarjeta mb-6 p-5"><span class="font-semibold">Motivo del rechazo:</span> {{ d.motivo_rechazo }}</div>

    <section class="tarjeta mb-6 grid gap-4 p-5 md:grid-cols-3">
        <div><p class="etiqueta">Documento original</p><Link :href="`/caja/documentos/${d.documento.id}`" class="font-semibold">{{ d.documento.nombre }}</Link><p class="text-grafito">{{ d.documento.cliente ?? 'Consumidor final' }}</p></div>
        <div><p class="etiqueta">Motivo</p><p>{{ d.motivo }}</p><p class="text-[14px] text-grafito">{{ d.detalle }}</p></div>
        <div><p class="etiqueta">{{ d.nota_credito ? 'Nota de crédito' : 'Monto estimado' }}</p><p class="num text-[24px] font-bold text-naranja-profundo">{{ pesos(d.nota_credito?.total ?? d.monto) }}</p></div>
    </section>

    <section class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Producto</th><th class="text-right">Vendido</th><th class="text-right">Devuelto</th><th>Stock</th></tr></thead>
            <tbody>
                <tr v-for="(l, i) in d.lineas" :key="i">
                    <td>{{ l.descripcion }}<div v-if="l.codigo" class="num text-[13px] text-grafito">{{ l.codigo }}</div></td>
                    <td class="num text-right">{{ cantidad(l.vendida) }} {{ l.unidad }}</td>
                    <td class="num text-right font-semibold">{{ cantidad(l.cantidad) }}</td>
                    <td><Chip chico :tono="l.reingresa ? 'ok' : 'alerta'">{{ l.reingresa ? 'Reingresa' : 'Dañado, no reingresa' }}</Chip></td>
                </tr>
            </tbody>
        </table>
    </section>

    <Modal :abierto="rechazoAbierto" titulo="Rechazar devolución" @cerrar="rechazoAbierto = false">
        <Campo etiqueta="Motivo" id="motivo" obligatorio :error="rechazo.errors.motivo || (rechazo.errors as any).devolucion"><input id="motivo" v-model="rechazo.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="rechazoAbierto = false">Cancelar</button>
            <button class="btn btn-peligro" :disabled="rechazo.processing" @click="rechazo.post(`/inventario/devoluciones/${d.id}/rechazar`, { preserveScroll: true, onSuccess: () => (rechazoAbierto = false) })">Rechazar</button>
        </template>
    </Modal>
</template>
