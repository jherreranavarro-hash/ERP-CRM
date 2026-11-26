<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import { fecha, fechaHora, pesos, rut } from '@/lib/formato';
import { cantidad } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Linea { producto_id: number; sku: string | null; nombre: string | null; unidad: string | null; cantidad: string; costo_unitario: string }
interface Rec { id: number; numero: number; estado: string; fecha: string; local: string | null; proveedor_nombre: string; proveedor_rut: string | null; documento_proveedor: string | null; observaciones: string | null; costo_total: number; creador: string | null; confirmador: string | null; confirmado_at: string | null; lineas: Linea[] }
const props = defineProps<{ recepcion: Rec }>();
const puede = usePermisos();
const page = usePage();
const r = computed(() => props.recepcion);
const errores = computed(() => Object.values(page.props.errors ?? {}) as string[]);
const confirmar = () => confirm('¿Confirmar la recepción? El stock y el costo promedio se actualizan y ya no podrá editarse.') && router.post(`/inventario/recepciones/${r.value.id}/confirmar`, {}, { preserveScroll: true });
const eliminar = () => confirm('¿Eliminar este borrador?') && router.delete(`/inventario/recepciones/${r.value.id}`);
</script>

<template>
    <Head :title="`Recepción N° ${r.numero}`" />
    <Encabezado :titulo="`Recepción N° ${r.numero}`" volver="/inventario/recepciones" :bajada="`${r.local ?? ''} · registrada por ${r.creador ?? '—'}`">
        <template v-if="r.estado === 'borrador'">
            <button v-if="puede('inventario.recepcion-de-mercaderia.eliminar')" class="btn btn-secundario" @click="eliminar">Eliminar</button>
            <Link v-if="puede('inventario.recepcion-de-mercaderia.editar')" :href="`/inventario/recepciones/${r.id}/editar`" class="btn btn-secundario no-underline">Editar</Link>
            <button v-if="puede('inventario.recepcion-de-mercaderia.confirmar')" class="btn btn-primario" @click="confirmar">Confirmar e ingresar stock</button>
        </template>
    </Encabezado>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip :tono="r.estado === 'confirmada' ? 'ok' : 'neutro'">{{ r.estado === 'confirmada' ? 'Confirmada' : 'Borrador' }}</Chip>
        <Chip v-if="r.confirmado_at" tono="info">Confirmada por {{ r.confirmador }} el {{ fechaHora(r.confirmado_at) }}</Chip>
    </div>

    <div v-if="errores.length" role="alert" class="tarjeta mb-6 border-[#F1B5AE] bg-[#FDECEA] p-5 text-[#B42318]">
        <ul class="list-disc pl-5"><li v-for="e in errores" :key="e">{{ e }}</li></ul>
    </div>

    <section class="tarjeta mb-6 grid gap-4 p-5 md:grid-cols-3">
        <div><p class="etiqueta">Proveedor</p><p class="font-semibold">{{ r.proveedor_nombre }}</p><p v-if="r.proveedor_rut" class="num text-grafito">{{ rut(r.proveedor_rut) }}</p></div>
        <div><p class="etiqueta">Documento del proveedor</p><p class="num">{{ r.documento_proveedor ?? '—' }}</p></div>
        <div><p class="etiqueta">Fecha</p><p class="num">{{ fecha(r.fecha) }}</p></div>
    </section>

    <section class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Producto</th><th class="text-right">Cantidad</th><th class="text-right">Costo unitario</th><th class="text-right">Subtotal</th></tr></thead>
            <tbody>
                <tr v-for="l in r.lineas" :key="l.producto_id">
                    <td><Link :href="`/inventario/stock/${l.producto_id}/kardex`" class="font-semibold">{{ l.nombre }}</Link><div class="num text-[13px] text-grafito">{{ l.sku }}</div></td>
                    <td class="num text-right">{{ cantidad(l.cantidad) }} {{ l.unidad }}</td>
                    <td class="num text-right">{{ pesos(Number(l.costo_unitario)) }}</td>
                    <td class="num text-right font-semibold">{{ pesos(Math.round(Number(l.cantidad) * Number(l.costo_unitario))) }}</td>
                </tr>
            </tbody>
            <tfoot><tr><td colspan="3" class="text-right font-semibold">Costo total</td><td class="num text-right text-[20px] font-bold text-naranja-profundo">{{ pesos(r.costo_total) }}</td></tr></tfoot>
        </table>
    </section>
    <p v-if="r.observaciones" class="mt-4 text-[15px] text-grafito"><span class="font-semibold text-carbon">Observaciones:</span> {{ r.observaciones }}</p>
</template>
