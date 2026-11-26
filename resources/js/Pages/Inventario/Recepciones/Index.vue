<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; numero: number; fecha: string; proveedor: string; documento: string | null; local: string | null; costo_total: number; estado: string; creador: string | null }
const props = defineProps<{ recepciones: Paginado<Fila>; filtros: Record<string, string | undefined> }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/inventario/recepciones', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
</script>

<template>
    <Head title="Recepción de mercadería" />
    <Encabezado titulo="Recepción de mercadería" bajada="Ingreso de productos al stock con su costo. Al confirmar se actualiza el costo promedio.">
        <Link v-if="puede('inventario.recepcion-de-mercaderia.crear')" href="/inventario/recepciones/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nueva recepción</Link>
    </Encabezado>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Número, proveedor o documento" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todas</option><option value="borrador">Borradores</option><option value="confirmada">Confirmadas</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="recepciones.data.length" class="tabla">
                <thead><tr><th>N°</th><th>Fecha</th><th>Proveedor</th><th>Documento</th><th>Local</th><th class="text-right">Costo</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="r in recepciones.data" :key="r.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/inventario/recepciones/${r.id}`)">
                        <td><Link :href="`/inventario/recepciones/${r.id}`" class="num font-semibold">{{ r.numero }}</Link></td>
                        <td class="num whitespace-nowrap">{{ fecha(r.fecha) }}</td>
                        <td>{{ r.proveedor }}</td>
                        <td class="num">{{ r.documento ?? '—' }}</td>
                        <td>{{ r.local }}</td>
                        <td class="num text-right font-semibold">{{ pesos(r.costo_total) }}</td>
                        <td><Chip chico :tono="r.estado === 'confirmada' ? 'ok' : 'neutro'">{{ r.estado === 'confirmada' ? 'Confirmada' : 'Borrador' }}</Chip></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin recepciones" texto="Registra la primera recepción de mercadería." />
        </div>
        <Paginacion :datos="recepciones" nombre="recepciones" />
    </div>
</template>
