<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';
import { estadoTurno } from '@/lib/documentos';

interface Fila { id: number; caja: string; local: string | null; usuario: string; abierto_at: string; cerrado_at: string | null; estado: string; monto_apertura: number; esperado: number | null; declarado: number | null; diferencia: number | null; requiere_aprobacion: boolean; aprobado_por: string | null }
const props = defineProps<{ turnos: Paginado<Fila>; filtros: Record<string, string | undefined>; cajas: { id: number; nombre: string }[]; pendientes: number }>();
const puede = usePermisos();

const f = reactive({ caja_id: props.filtros.caja_id ?? '', estado: props.filtros.estado ?? '', desde: props.filtros.desde ?? '', hasta: props.filtros.hasta ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/caja/cierres', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true }), 300); });
const exportar = () => (window.location.href = `/caja/cierres?${new URLSearchParams({ ...f, exportar: '1' })}`);
</script>

<template>
    <Head title="Cierres de caja" />
    <Encabezado titulo="Cierres de caja" bajada="Cuadratura de cada turno contra los pagos registrados. Toda diferencia queda en la bitácora.">
        <button v-if="puede('caja.cierres-de-caja.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
    </Encabezado>

    <button v-if="pendientes" class="tarjeta mb-6 block w-full border-[#F0D58A] bg-[#FFF3D6] p-4 text-left text-[#8A5A00]" @click="f.estado = 'por_aprobar'">
        {{ pendientes === 1 ? 'Hay 1 cierre' : `Hay ${pendientes} cierres` }} con diferencia sobre la tolerancia esperando aprobación.
    </button>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-2 xl:grid-cols-4">
            <select v-model="f.caja_id" class="campo h-11" aria-label="Caja"><option value="">Todas las cajas</option><option v-for="c in cajas" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado">
                <option value="">Todos</option><option value="abierto">Abiertos</option><option value="cuadrado">Cuadrados</option><option value="diferencia">Con diferencia</option><option value="por_aprobar">Por aprobar</option>
            </select>
            <input v-model="f.desde" type="date" class="campo h-11" aria-label="Desde" />
            <input v-model="f.hasta" type="date" class="campo h-11" aria-label="Hasta" />
        </div>
        <div class="overflow-x-auto">
            <table v-if="turnos.data.length" class="tabla">
                <thead><tr><th>Caja</th><th>Usuario</th><th>Apertura</th><th>Cierre</th><th class="text-right">Esperado</th><th class="text-right">Declarado</th><th class="text-right">Diferencia</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="r in turnos.data" :key="r.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/caja/cierres/${r.id}`)">
                        <td><Link :href="`/caja/cierres/${r.id}`" class="font-semibold">{{ r.caja }}</Link><div class="text-[13px] text-grafito">{{ r.local }}</div></td>
                        <td>{{ r.usuario }}</td>
                        <td class="num whitespace-nowrap">{{ fechaHora(r.abierto_at) }}</td>
                        <td class="num whitespace-nowrap">{{ r.cerrado_at ? fechaHora(r.cerrado_at) : '—' }}</td>
                        <td class="num text-right">{{ r.esperado === null ? '—' : pesos(r.esperado) }}</td>
                        <td class="num text-right">{{ r.declarado === null ? '—' : pesos(r.declarado) }}</td>
                        <td class="num text-right font-semibold" :class="r.diferencia ? 'text-estado-error' : ''">{{ r.diferencia === null ? '—' : pesos(r.diferencia) }}</td>
                        <td><Chip chico :tono="estadoTurno(r)[0]">{{ estadoTurno(r)[1] }}</Chip></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin turnos" texto="Aún no hay turnos de caja con estos filtros." />
        </div>
        <Paginacion :datos="turnos" nombre="turnos" />
    </div>
</template>
