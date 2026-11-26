<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { estadoCambioPrecio } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Linea { id: number; producto_id: number; sku: string | null; producto: string | null; campo: string; anterior: number | null; nuevo: number | null; variacion: number | null; costo: number | null }
interface Cambio { id: number; numero: number; descripcion: string; estado: string; motivos: string[]; fecha: string; aplicado_at: string | null; solicitante: string | null; aprobador: string | null; motivo_rechazo: string | null; propia: boolean; lineas: Linea[] }
const props = defineProps<{ cambio: Cambio }>();
const puede = usePermisos();
const page = usePage();
const c = computed(() => props.cambio);
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);
const rechazo = useForm({ motivo: '' });
const rechazoAbierto = ref(false);
const aprobar = () => confirm('¿Aprobar y aplicar estos precios?') && router.post(`/inventario/precios/${c.value.id}/aprobar`, {}, { preserveScroll: true });
const fmt = (v: number | null) => (v === null ? '—' : `${v.toLocaleString('es-CL', { maximumFractionDigits: 2, signDisplay: 'always' })} %`);
</script>

<template>
    <Head :title="`Cambio de precios N° ${c.numero}`" />
    <Encabezado :titulo="`Cambio de precios N° ${c.numero}`" volver="/inventario/precios" :bajada="`${c.descripcion} · solicitado por ${c.solicitante ?? '—'} el ${fechaHora(c.fecha)}`">
        <template v-if="c.estado === 'por_aprobar' && puede('inventario.actualizacion-de-precios.aprobar') && !c.propia">
            <button class="btn btn-secundario" @click="rechazoAbierto = true">Rechazar</button>
            <button class="btn btn-primario" @click="aprobar">Aprobar y aplicar</button>
        </template>
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="estadoCambioPrecio[c.estado][0]">{{ estadoCambioPrecio[c.estado][1] }}</Chip>
        <Chip v-if="c.aplicado_at" tono="info">Aplicado el {{ fechaHora(c.aplicado_at) }}</Chip>
        <Chip v-if="c.aprobador" tono="neutro">Revisó {{ c.aprobador }}</Chip>
    </div>
    <div v-if="errores.length && !rechazoAbierto" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]"><ul class="list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul></div>
    <div v-if="c.estado === 'por_aprobar'" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6] p-5 text-[#8A5A00]">
        <p class="font-semibold">{{ c.propia ? 'Debe aprobarlo otra persona:' : 'Requiere aprobación:' }}</p>
        <ul class="mt-1 list-disc pl-5"><li v-for="m in c.motivos" :key="m">{{ m }}</li></ul>
    </div>
    <div v-if="c.motivo_rechazo" class="tarjeta mb-6 p-5"><span class="font-semibold">Motivo del rechazo:</span> {{ c.motivo_rechazo }}</div>

    <section class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Producto</th><th>Precio</th><th class="text-right">Antes</th><th class="text-right">Nuevo</th><th class="text-right">Variación</th><th v-if="c.lineas.some((l) => l.costo !== null)" class="text-right">Costo</th></tr></thead>
            <tbody>
                <tr v-for="l in c.lineas" :key="l.id">
                    <td><Link :href="`/inventario/stock/${l.producto_id}/kardex`" class="font-semibold">{{ l.producto }}</Link><div class="num text-[13px] text-grafito">{{ l.sku }}</div></td>
                    <td>{{ l.campo }}</td>
                    <td class="num text-right">{{ l.anterior === null ? '—' : pesos(l.anterior) }}</td>
                    <td class="num text-right font-semibold" :class="l.costo !== null && l.nuevo !== null && l.nuevo < l.costo ? 'text-estado-error' : ''">{{ l.nuevo === null ? '—' : pesos(l.nuevo) }}</td>
                    <td class="num text-right">{{ fmt(l.variacion) }}</td>
                    <td v-if="c.lineas.some((x) => x.costo !== null)" class="num text-right">{{ l.costo === null ? '—' : pesos(l.costo) }}</td>
                </tr>
            </tbody>
        </table>
    </section>

    <Modal :abierto="rechazoAbierto" titulo="Rechazar cambio de precios" @cerrar="rechazoAbierto = false">
        <Campo etiqueta="Motivo" id="motivo" obligatorio :error="rechazo.errors.motivo || (rechazo.errors as any).cambio"><input id="motivo" v-model="rechazo.motivo" class="campo h-11" maxlength="200" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="rechazoAbierto = false">Cancelar</button>
            <button class="btn btn-peligro" :disabled="rechazo.processing" @click="rechazo.post(`/inventario/precios/${c.id}/rechazar`, { preserveScroll: true, onSuccess: () => (rechazoAbierto = false) })">Rechazar</button>
        </template>
    </Modal>
</template>
