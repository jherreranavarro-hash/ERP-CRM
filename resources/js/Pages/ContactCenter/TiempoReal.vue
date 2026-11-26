<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Vacio from '@/Components/Vacio.vue';
import { numero } from '@/lib/formato';
import { duracion, estadoAgente, estadoLlamada } from '@/lib/documentos';
import { conectar, type Conexion } from '@/lib/tiempoReal';

interface Agente { id: number; nombre: string; extension: string; estado: string; motivo: string | null; desde: string | null; cola: string | null; llamada_id: number | null }
interface Llamada { id: number; agente_id: number | null; cola_id: number | null; direccion: string; numero: string; estado: string; campana: string | null; inicio: string | null; contestada: string | null }
const props = defineProps<{
    agentes: Agente[]; llamadas: Llamada[]; colas: { id: number; nombre: string; nivel_servicio_seg: number }[];
    hoy: { llamadas: number; salientes: number; contactadas: number; entrantes: number; abandonadas: number; nivel_servicio: number | null; tmo: number };
    canal: string; conexion: Conexion | null;
}>();

// Copia local que los eventos del WebSocket van actualizando; se resincroniza si el servidor entrega datos nuevos.
const agentes = ref<Agente[]>([...props.agentes]);
const llamadas = ref<Llamada[]>([...props.llamadas]);
watch(() => props.agentes, (v) => (agentes.value = [...v]));
watch(() => props.llamadas, (v) => (llamadas.value = [...v]));
const ACTIVAS = ['marcando', 'en_cola', 'en_curso'];

const enVivo = ref(false);
const ultimo = ref<string | null>(null);
let echo: ReturnType<typeof conectar> | null = null;
let recarga: ReturnType<typeof setTimeout> | undefined;
function aplicar(e: { tipo: 'agente' | 'llamada'; datos: Agente & Llamada; momento: string }) {
    ultimo.value = e.momento;
    if (e.tipo === 'agente') {
        const i = agentes.value.findIndex((a) => a.id === e.datos.id);
        if (i >= 0) agentes.value.splice(i, 1, e.datos);
        else agentes.value.push(e.datos);
        return;
    }
    const i = llamadas.value.findIndex((l) => l.id === e.datos.id);
    if (ACTIVAS.includes(e.datos.estado)) {
        if (i >= 0) llamadas.value.splice(i, 1, e.datos);
        else llamadas.value.push(e.datos);
    } else {
        if (i >= 0) llamadas.value.splice(i, 1);
        // Una llamada terminada cambia los totales del día: se piden solo esos, agrupando eventos seguidos.
        clearTimeout(recarga);
        recarga = setTimeout(() => router.reload({ only: ['hoy'] }), 1500);
    }
}

const ahora = ref(Date.now());
let reloj: ReturnType<typeof setInterval>;
onMounted(() => {
    reloj = setInterval(() => (ahora.value = Date.now()), 1000);
    if (!props.conexion) return;
    try {
        echo = conectar(props.conexion);
        echo.private(props.canal).listen('.actualizado', aplicar);
        const pusher = (echo.connector as unknown as { pusher: { connection: { bind: (ev: string, fn: (s: { current: string }) => void) => void; state: string } } }).pusher;
        enVivo.value = pusher.connection.state === 'connected';
        pusher.connection.bind('state_change', (s) => (enVivo.value = s.current === 'connected'));
    } catch {
        enVivo.value = false;
    }
});
onBeforeUnmount(() => {
    clearInterval(reloj);
    clearTimeout(recarga);
    echo?.leave(props.canal);
    echo?.disconnect();
});
const desde = (iso: string | null) => (iso ? Math.max(0, Math.floor((ahora.value - new Date(iso).getTime()) / 1000)) : 0);

const conteo = computed(() => Object.fromEntries(Object.keys(estadoAgente).map((k) => [k, agentes.value.filter((a) => a.estado === k).length])));
const enCola = computed(() => llamadas.value.filter((l) => l.estado === 'en_cola'));
const porCola = computed(() => props.colas.map((c) => {
    const espera = enCola.value.filter((l) => l.cola_id === c.id);
    const mayor = Math.max(0, ...espera.map((l) => desde(l.inicio)));
    return { ...c, espera: espera.length, mayor };
}));
const llamadaDe = (a: Agente) => llamadas.value.find((l) => l.agente_id === a.id) ?? null;
</script>

<template>
    <Head title="Tiempo real" />
    <Encabezado titulo="Tiempo real" bajada="Estado de agentes, colas y llamadas en curso. Los números de teléfono van enmascarados porque esta pantalla queda a la vista.">
        <Chip :tono="enVivo ? 'ok' : 'alerta'">{{ enVivo ? 'En vivo' : conexion ? 'Conectando…' : 'Sin conexión en vivo' }}</Chip>
    </Encabezado>
    <p v-if="!conexion" class="tarjeta mb-6 p-4 text-[14px] text-grafito">El servidor de tiempo real no está configurado. La pantalla muestra la foto al momento de abrirla; recárgala para ver cambios.</p>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <Kpi etiqueta="Llamadas hoy" :valor="numero(hoy.llamadas)">{{ numero(hoy.salientes) }} salientes · {{ numero(hoy.entrantes) }} entrantes</Kpi>
        <Kpi etiqueta="Contactadas" :valor="numero(hoy.contactadas)" tono="ok" />
        <Kpi etiqueta="Abandonadas" :valor="numero(hoy.abandonadas)" :tono="hoy.abandonadas ? 'error' : 'normal'" />
        <Kpi etiqueta="Nivel de servicio" :valor="hoy.nivel_servicio === null ? '—' : `${hoy.nivel_servicio}%`" :tono="hoy.nivel_servicio !== null && hoy.nivel_servicio < 80 ? 'naranja' : 'normal'">Atendidas en 20 s o menos</Kpi>
        <Kpi etiqueta="Tiempo medio" :valor="duracion(hoy.tmo)">Por llamada contestada</Kpi>
    </div>

    <div class="mb-6 flex flex-wrap gap-2">
        <Chip v-for="(v, k) in estadoAgente" :key="k" :tono="v[0]">{{ `${v[1]}: ${conteo[k]}` }}</Chip>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="tarjeta xl:col-span-2">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Agentes</h2>
            <div v-if="agentes.length" class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="a in agentes" :key="a.id" class="rounded-lg border border-linea p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0"><p class="truncate font-semibold">{{ a.nombre }}</p><p class="num text-[13px] text-grafito">Ext. {{ a.extension }}<template v-if="a.cola"> · {{ a.cola }}</template></p></div>
                        <Chip chico :tono="estadoAgente[a.estado][0]">{{ estadoAgente[a.estado][1] }}</Chip>
                    </div>
                    <p class="num mt-2 text-[14px]">{{ duracion(desde(a.desde)) }}<template v-if="a.motivo"> · {{ a.motivo }}</template></p>
                    <p v-if="llamadaDe(a)" class="num text-[13px] text-grafito">{{ llamadaDe(a)!.numero }}<template v-if="llamadaDe(a)!.campana"> · {{ llamadaDe(a)!.campana }}</template></p>
                </div>
            </div>
            <Vacio v-else titulo="Sin agentes activos" texto="Registra agentes en VentasCRM → Agentes." />
        </section>

        <div class="space-y-6">
            <section class="tarjeta">
                <h2 class="border-b border-linea p-4 text-[18px] font-bold">Colas</h2>
                <ul v-if="porCola.length" class="divide-y divide-linea">
                    <li v-for="c in porCola" :key="c.id" class="flex items-center justify-between gap-3 p-4">
                        <div><p class="font-semibold">{{ c.nombre }}</p><p class="text-[13px] text-grafito">Meta: atender en {{ c.nivel_servicio_seg }} s</p></div>
                        <div class="text-right">
                            <p class="num text-[22px] font-bold" :class="{ 'text-estado-error': c.espera > 0 && c.mayor > c.nivel_servicio_seg }">{{ c.espera }}</p>
                            <p v-if="c.espera" class="num text-[13px] text-grafito">más antigua {{ duracion(c.mayor) }}</p>
                        </div>
                    </li>
                </ul>
                <p v-else class="p-4 text-grafito">Sin colas activas.</p>
            </section>
            <section class="tarjeta">
                <h2 class="border-b border-linea p-4 text-[18px] font-bold">Llamadas en curso</h2>
                <ul v-if="llamadas.length" class="divide-y divide-linea">
                    <li v-for="l in llamadas" :key="l.id" class="flex items-center justify-between gap-3 p-4">
                        <div><p class="num font-semibold">{{ l.numero }}</p><p class="text-[13px] text-grafito">{{ l.direccion === 'entrante' ? 'Entrante' : 'Saliente' }}<template v-if="l.campana"> · {{ l.campana }}</template></p></div>
                        <div class="text-right"><Chip chico :tono="estadoLlamada[l.estado][0]">{{ estadoLlamada[l.estado][1] }}</Chip><p class="num text-[13px]">{{ duracion(desde(l.contestada ?? l.inicio)) }}</p></div>
                    </li>
                </ul>
                <p v-else class="p-4 text-grafito">No hay llamadas en curso.</p>
            </section>
        </div>
    </div>
</template>
