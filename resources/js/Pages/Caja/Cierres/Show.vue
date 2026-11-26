<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { estadoTurno } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Turno {
    id: number; caja: string; local: string | null; usuario: string; abierto_at: string; cerrado_at: string | null; estado: string; monto_apertura: number;
    esperado: number | null; declarado: number | null; diferencia: number | null; requiere_aprobacion: boolean; aprobado_por: string | null; aprobado_at: string | null;
    medios: { medio: string; esperado: number; declarado: number | null; diferencia: number | null }[]; otros: { medio: string; monto: number }[];
    observacion: string | null; propio: boolean;
    pagos: { id: number; documento_id: number; documento: string | null; monto: number; metodo: string; hora: string; referencia: string | null }[];
}
const props = defineProps<{ turno: Turno }>();
const puede = usePermisos();
const t = computed(() => props.turno);
const estado = computed(() => estadoTurno(t.value));
const aprobacion = useForm({ comentario: '' });
const aprobarAbierto = ref(false);
</script>

<template>
    <Head :title="`Turno ${t.caja}`" />
    <Encabezado :titulo="`${t.caja} · ${t.usuario}`" volver="/caja/cierres" :bajada="`${t.local ?? ''} · ${fechaHora(t.abierto_at)}${t.cerrado_at ? ` al ${fechaHora(t.cerrado_at)}` : ''}`">
        <button v-if="t.requiere_aprobacion && !t.aprobado_por && puede('caja.cierres-de-caja.aprobar') && !t.propio" class="btn btn-primario" @click="aprobarAbierto = true">Aprobar diferencia</button>
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="estado[0]">{{ estado[1] }}</Chip>
        <Chip v-if="t.aprobado_por" tono="ok">Aprobada por {{ t.aprobado_por }} · {{ fechaHora(t.aprobado_at) }}</Chip>
    </div>
    <div v-if="aprobacion.errors && (aprobacion.errors as any).turno" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">{{ (aprobacion.errors as any).turno }}</div>

    <div class="grid gap-6 xl:grid-cols-[1fr_380px]">
        <section class="tarjeta overflow-x-auto self-start">
            <h2 class="titulo-ancho px-5 pt-5 text-[16px] font-bold">Pagos del turno</h2>
            <table v-if="t.pagos.length" class="tabla">
                <thead><tr><th>Hora</th><th>Documento</th><th>Medio</th><th class="text-right">Monto</th></tr></thead>
                <tbody><tr v-for="p in t.pagos" :key="p.id"><td class="num whitespace-nowrap">{{ fechaHora(p.hora) }}</td><td><Link :href="`/caja/documentos/${p.documento_id}`">{{ p.documento }}</Link></td><td>{{ p.metodo }}<span v-if="p.referencia" class="text-grafito"> · {{ p.referencia }}</span></td><td class="num text-right">{{ pesos(p.monto) }}</td></tr></tbody>
            </table>
            <p v-else class="p-5 text-grafito">Sin pagos en este turno.</p>
        </section>

        <aside class="tarjeta self-start p-5">
            <h2 class="titulo-ancho mb-4 text-[16px] font-bold">Cuadratura</h2>
            <table class="w-full text-[15px]">
                <thead><tr class="text-left text-grafito"><th class="pb-2 font-normal">Medio</th><th class="pb-2 text-right font-normal">Esperado</th><th class="pb-2 text-right font-normal">Contado</th><th class="pb-2 text-right font-normal">Dif.</th></tr></thead>
                <tbody>
                    <tr v-for="m in t.medios" :key="m.medio" class="border-t border-linea">
                        <td class="py-2">{{ m.medio }}</td>
                        <td class="num py-2 text-right">{{ pesos(m.esperado) }}</td>
                        <td class="num py-2 text-right">{{ m.declarado === null ? '—' : pesos(m.declarado) }}</td>
                        <td class="num py-2 text-right font-semibold" :class="m.diferencia ? 'text-estado-error' : ''">{{ m.diferencia === null ? '—' : pesos(m.diferencia) }}</td>
                    </tr>
                </tbody>
            </table>
            <p class="mt-3 text-[14px] text-grafito">El efectivo esperado incluye el fondo inicial de {{ pesos(t.monto_apertura) }}.</p>
            <div v-if="t.otros.length" class="mt-4 border-t border-linea pt-3 text-[14px] text-grafito">
                <p class="font-semibold text-carbon">Fuera del arqueo</p>
                <p v-for="o in t.otros" :key="o.medio" class="flex justify-between"><span>{{ o.medio }}</span><span class="num">{{ pesos(o.monto) }}</span></p>
            </div>
            <p v-if="t.observacion" class="mt-4 text-[15px]"><span class="font-semibold">Observación:</span> {{ t.observacion }}</p>
        </aside>
    </div>

    <Modal :abierto="aprobarAbierto" titulo="Aprobar diferencia de caja" @cerrar="aprobarAbierto = false">
        <p class="mb-4 text-[15px]">Diferencia total de <span class="num font-semibold">{{ pesos(t.diferencia) }}</span>. La aprobación queda en la bitácora con tu nombre.</p>
        <Campo etiqueta="Comentario (opcional)" id="comentario" :error="aprobacion.errors.comentario"><textarea id="comentario" v-model="aprobacion.comentario" rows="2" class="campo" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="aprobarAbierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="aprobacion.processing" @click="aprobacion.post(`/caja/cierres/${t.id}/aprobar`, { preserveScroll: true, onSuccess: () => (aprobarAbierto = false) })">Aprobar</button>
        </template>
    </Modal>
</template>
