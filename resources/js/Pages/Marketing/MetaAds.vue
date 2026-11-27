<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Vacio from '@/Components/Vacio.vue';
import { fechaHora, haceCuanto, numero, pesos } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Camp { id: number; externo_id: string; nombre: string; estado: string; objetivo: string | null; presupuesto_diario: number; gasto: number; impresiones: number; clics: number; leads: number;
    campana_id: number | null; destino: string | null; sincronizada_at: string | null; importados: number; sin_destino: number }
interface Prospecto { id: number; ads_campana_id: number; nombre: string; comuna: string | null; resultado: string; lead_id: number | null; telefono: string; recibido_at: string }
const props = defineProps<{ activa: boolean; campanas: Camp[]; prospectos: Prospecto[]; resultados: Record<string, string>; destinos: { id: number; nombre: string; estado: string }[]; ultima: string | null }>();
const puede = usePermisos();
const editar = computed(() => puede('ventascrm.meta-ads.editar'));

const total = computed(() => props.campanas.reduce((a, c) => ({ gasto: a.gasto + c.gasto, leads: a.leads + c.leads, clics: a.clics + c.clics, imp: a.imp + c.impresiones, imp2: a.imp2 + c.importados }), { gasto: 0, leads: 0, clics: 0, imp: 0, imp2: 0 }));
const cpl = (gasto: number, leads: number) => (leads ? pesos(gasto / leads) : '—');
const ctr = (clics: number, imp: number) => (imp ? `${((clics / imp) * 100).toLocaleString('es-CL', { maximumFractionDigits: 2 })} %` : '—');
const nombreCamp = (id: number) => props.campanas.find((c) => c.id === id)?.nombre ?? '';
const TONO: Record<string, 'ok' | 'neutro' | 'alerta' | 'error' | 'info'> = { importado: 'ok', duplicado: 'neutro', bloqueado: 'error', sin_destino: 'alerta', invalido: 'error' };

const sincronizar = () => router.post('/ventas/meta-ads/sincronizar', {}, { preserveScroll: true });
const destino = (c: Camp, v: string) => router.put(`/ventas/meta-ads/${c.id}/destino`, { campana_id: v ? Number(v) : null }, { preserveScroll: true });
</script>

<template>
    <Head title="Meta Ads" />
    <Encabezado titulo="Meta Ads" bajada="Métricas de tus campañas en Facebook e Instagram y los prospectos de sus formularios, que entran solos a la campaña del contact center que elijas. Solo lectura: las campañas se editan en Meta.">
        <span v-if="ultima" class="text-[14px] text-grafito">Sincronizado {{ haceCuanto(ultima) }}</span>
        <button v-if="editar" class="btn btn-primario" :disabled="!activa" @click="sincronizar">Sincronizar ahora</button>
    </Encabezado>
    <p v-if="!activa" class="tarjeta mb-4 p-4 text-[14px]">La integración de Meta Ads no está activa. Actívala en <Link href="/configuracion/integraciones">Configuración → Integraciones</Link>.</p>

    <template v-if="campanas.length">
        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Kpi etiqueta="Gasto del mes" :valor="pesos(total.gasto)" />
            <Kpi etiqueta="Prospectos" :valor="numero(total.leads)">{{ numero(total.imp2) }} importados al contact center</Kpi>
            <Kpi etiqueta="Costo por prospecto" :valor="cpl(total.gasto, total.leads)" tono="naranja" />
            <Kpi etiqueta="CTR" :valor="ctr(total.clics, total.imp)">{{ numero(total.clics) }} clics</Kpi>
        </div>

        <section class="tarjeta mb-6 overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Campaña</th><th class="text-right">Gasto</th><th class="text-right">Clics · CTR</th><th class="text-right">Prospectos</th><th class="text-right">Costo/prospecto</th><th class="min-w-[220px]">Envía prospectos a</th></tr></thead>
                <tbody>
                    <tr v-for="c in campanas" :key="c.id">
                        <td>
                            <div class="font-semibold">{{ c.nombre }}</div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[13px] text-grafito">
                                <Chip chico :tono="c.estado === 'activa' ? 'ok' : 'neutro'">{{ c.estado === 'activa' ? 'Activa' : c.estado === 'pausada' ? 'Pausada' : 'Terminada' }}</Chip>
                                <span>{{ pesos(c.presupuesto_diario) }}/día</span>
                            </div>
                        </td>
                        <td class="num text-right">{{ pesos(c.gasto) }}</td>
                        <td class="num text-right">{{ numero(c.clics) }}<div class="text-[13px] text-grafito">{{ ctr(c.clics, c.impresiones) }}</div></td>
                        <td class="num text-right">{{ numero(c.leads) }}<div class="text-[13px] text-grafito">{{ c.importados }} importados</div></td>
                        <td class="num text-right font-semibold">{{ cpl(c.gasto, c.leads) }}</td>
                        <td>
                            <select v-if="editar" class="campo h-10" :aria-label="`Destino de ${c.nombre}`" :value="c.campana_id ?? ''" @change="destino(c, ($event.target as HTMLSelectElement).value)">
                                <option value="">Sin destino (solo registrar)</option>
                                <option v-for="d in destinos" :key="d.id" :value="d.id">{{ d.nombre }}</option>
                            </select>
                            <span v-else>{{ c.destino ?? 'Sin destino' }}</span>
                            <p v-if="c.sin_destino" class="mt-1 text-[13px] text-estado-alerta">{{ c.sin_destino }} prospectos esperan destino</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="tarjeta overflow-x-auto">
            <h2 class="px-5 pt-4 text-[18px] font-bold">Últimos prospectos recibidos</h2>
            <table v-if="prospectos.length" class="tabla">
                <thead><tr><th>Recibido</th><th>Nombre</th><th>Teléfono</th><th>Comuna</th><th>Campaña</th><th>Resultado</th></tr></thead>
                <tbody>
                    <tr v-for="p in prospectos" :key="p.id">
                        <td class="whitespace-nowrap text-grafito">{{ fechaHora(p.recibido_at) }}</td>
                        <td class="font-semibold">{{ p.nombre }}</td>
                        <td class="num whitespace-nowrap">{{ p.telefono }}</td>
                        <td>{{ p.comuna ?? '—' }}</td>
                        <td class="text-[14px] text-grafito">{{ nombreCamp(p.ads_campana_id) }}</td>
                        <td><Chip chico :tono="TONO[p.resultado]">{{ resultados[p.resultado] }}</Chip></td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="p-5 text-grafito">Aún no llegan prospectos.</p>
        </section>
    </template>
    <div v-else class="tarjeta"><Vacio titulo="Sin campañas sincronizadas" :texto="activa ? 'Pulsa Sincronizar ahora para traer las campañas de la cuenta publicitaria.' : 'Primero activa la integración.'" /></div>
</template>
