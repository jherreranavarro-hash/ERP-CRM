<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, numero } from '@/lib/formato';
import { estadoCampana } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Campana { id: number; nombre: string; tipo: string; estado: string; inicio: string; fin: string | null; leads: number; abiertos: number; ganados: number; agentes: number; contactos: number; meta_contactos: number }
defineProps<{ campanas: Campana[]; tipos: Record<string, string>; estados: Record<string, string> }>();
const puede = usePermisos();
const pct = (a: number, b: number) => (b ? Math.min(100, Math.round((a / b) * 100)) : 0);
</script>

<template>
    <Head title="Campañas" />
    <Encabezado titulo="Campañas" bajada="Campañas de llamadas salientes y entrantes: prospectos, agentes, metas y horario de discado.">
        <Link v-if="puede('ventascrm.campana.crear')" href="/ventas/campanas/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nueva campaña</Link>
    </Encabezado>

    <div v-if="campanas.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Campaña</th><th>Estado</th><th>Vigencia</th><th class="text-right">Prospectos</th><th class="text-right">Agentes</th><th>Avance de contactos</th></tr></thead>
            <tbody>
                <tr v-for="c in campanas" :key="c.id">
                    <td><Link :href="`/ventas/campanas/${c.id}`" class="font-semibold">{{ c.nombre }}</Link><p class="text-[13px] text-grafito">{{ tipos[c.tipo] }}</p></td>
                    <td><Chip chico :tono="estadoCampana[c.estado][0]">{{ estadoCampana[c.estado][1] }}</Chip></td>
                    <td class="num text-[14px]">{{ fecha(c.inicio) }}<template v-if="c.fin"> – {{ fecha(c.fin) }}</template></td>
                    <td class="num text-right">{{ numero(c.leads) }}<p class="text-[13px] text-grafito">{{ numero(c.abiertos) }} abiertos · {{ numero(c.ganados) }} ganados</p></td>
                    <td class="num text-right">{{ c.agentes }}</td>
                    <td class="min-w-40">
                        <div class="h-2 rounded-full bg-linea"><div class="h-2 rounded-full bg-naranja" :style="{ width: pct(c.contactos, c.meta_contactos) + '%' }"></div></div>
                        <p class="num mt-1 text-[13px] text-grafito">{{ numero(c.contactos) }} de {{ numero(c.meta_contactos) }}</p>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin campañas" texto="Crea una campaña, carga sus prospectos desde un CSV y asígnalos a los agentes." />
</template>
