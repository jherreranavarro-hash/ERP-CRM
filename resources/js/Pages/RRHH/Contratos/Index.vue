<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import { consulta } from '@/lib/http';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos } from '@/lib/formato';
import { estadoContrato } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; empleado_id: number; empleado: string; rut: string; tipo: string; cargo: string; sueldo: number; inicio: string; termino: string | null; estado: string }
const props = defineProps<{ contratos: Paginado<Fila>; porVencer: Fila[]; filtros: { estado?: string; buscar?: string }; estados: Record<string, string>; diasAviso: number }>();
const puede = usePermisos();
const f = reactive({ estado: props.filtros.estado ?? '', buscar: props.filtros.buscar ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/rrhh/contratos', { ...f }, { preserveState: true, replace: true }), 300); });
const tipos: Record<string, string> = { indefinido: 'Indefinido', plazo_fijo: 'Plazo fijo', obra: 'Obra o faena' };
</script>

<template>
    <Head title="Contratos" />
    <Encabezado titulo="Gestión de contratos" bajada="Se redactan desde plantillas, se firman electrónicamente y quedan en el historial de la ficha. Para un contrato nuevo, entra a la ficha de la persona.">
        <Link href="/rrhh/contratos/plantillas" class="btn btn-secundario no-underline">Plantillas</Link>
        <a v-if="puede('rrhh.gestion-de-contratos.exportar')" :href="`/rrhh/contratos?${consulta({ ...f, exportar: '1' })}`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
    </Encabezado>

    <section v-if="porVencer.length" class="tarjeta mb-6 border-[#F0D58A] bg-[#FFF3D6]">
        <h2 class="p-4 pb-2 text-[18px] font-bold text-[#8A5A00]">Vencen en los próximos {{ diasAviso }} días</h2>
        <ul class="divide-y divide-[#F0D58A]">
            <li v-for="c in porVencer" :key="c.id" class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <span><Link :href="`/rrhh/personal/${c.empleado_id}`" class="font-semibold">{{ c.empleado }}</Link> · {{ c.cargo }} · {{ tipos[c.tipo] }}</span>
                <span class="num font-semibold text-[#8A5A00]">{{ fecha(c.termino) }}</span>
            </li>
        </ul>
    </section>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Persona o cargo" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todos los estados</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="contratos.data.length" class="tabla">
                <thead><tr><th>Persona</th><th>Cargo</th><th>Tipo</th><th class="text-right">Sueldo base</th><th>Vigencia</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="c in contratos.data" :key="c.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/rrhh/contratos/${c.id}`)">
                        <td><Link :href="`/rrhh/contratos/${c.id}`" class="font-semibold">{{ c.empleado }}</Link></td>
                        <td>{{ c.cargo }}</td>
                        <td>{{ tipos[c.tipo] }}</td>
                        <td class="num text-right">{{ pesos(c.sueldo) }}</td>
                        <td class="num">{{ fecha(c.inicio) }} – {{ c.termino ? fecha(c.termino) : 'indefinido' }}</td>
                        <td><Chip chico :tono="estadoContrato[c.estado][0]">{{ estadoContrato[c.estado][1] }}</Chip></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin contratos" />
        </div>
        <Paginacion :datos="contratos" nombre="contratos" />
    </div>
</template>
