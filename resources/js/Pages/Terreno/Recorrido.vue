<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha as fmtFecha, fechaHora } from '@/lib/formato';
import { estadoLead } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Parada { id: number; orden: number; nombre: string; direccion: string; comuna: string | null; lat: number | null; lng: number | null; estado: string; resultado: string | null; notas: string | null; cliente_id: number | null; lead_id: number | null; visitada_at: string | null }
interface Candidato { id: number; nombre: string; direccion: string; comuna: string | null; estado?: string }
type Sel = { tipo: 'cliente' | 'lead'; id: number; nombre: string; comuna: string | null };
const props = defineProps<{
    fecha: string; ejecutivoId: number; esMio: boolean; ejecutivos: { id: number; name: string }[];
    recorrido: null | { id: number; estado: string; origen: string | null; origen_lat: number | null; origen_lng: number | null; distancia_km: number | null; duracion_min: number | null; optimizado: boolean; paradas: Parada[] };
    candidatos: { clientes: Candidato[]; leads: Candidato[] }; buscar: string; origenSugerido: { direccion: string | null; comuna: string | null } | null;
    historial: { fecha: string; estado: string; paradas: number; visitadas: number; km: number | null }[];
    resultados: Record<string, string>; maxParadas: number; mapasSimulado: boolean;
}>();
const puede = usePermisos();
const puedeArmar = computed(() => puede('ventascrm.lista-de-recorrido.crear') && (props.esMio || puede('ventascrm.lista-de-recorrido.editar')));
const hoy = new Date().toISOString().slice(0, 10);
const editable = computed(() => puedeArmar.value && props.fecha >= hoy && props.recorrido?.estado !== 'cerrado');

const ir = (q: Record<string, unknown>) => router.get('/ventas/recorrido', { fecha: props.fecha, ...(props.ejecutivos.length ? { ejecutivo: props.ejecutivoId } : {}), ...q }, { preserveState: true, preserveScroll: true, replace: true });
const buscar = ref(props.buscar);

// Selección de paradas (al planificar o al agregar)
const seleccion = ref<Sel[]>([]);
const yaEsta = (tipo: string, id: number) => props.recorrido?.paradas.some((p) => (tipo === 'cliente' ? p.cliente_id : p.lead_id) === id) ?? false;
const marcado = (tipo: string, id: number) => seleccion.value.some((s) => s.tipo === tipo && s.id === id);
const alternar = (tipo: 'cliente' | 'lead', c: Candidato) => {
    seleccion.value = marcado(tipo, c.id) ? seleccion.value.filter((s) => !(s.tipo === tipo && s.id === c.id)) : [...seleccion.value, { tipo, id: c.id, nombre: c.nombre, comuna: c.comuna }];
};
const cargaParadas = () => seleccion.value.map((s) => (s.tipo === 'cliente' ? { cliente_id: s.id } : { lead_id: s.id }));

const fp = useForm({ origen: props.origenSugerido?.direccion ?? '', comuna_origen: props.origenSugerido?.comuna ?? '' });
const errores = computed(() => fp.errors as Record<string, string>);
const crear = () => fp.transform((d) => ({ ...d, fecha: props.fecha, ejecutivo_id: props.ejecutivoId, paradas: cargaParadas() })).post('/ventas/recorrido', { preserveScroll: true, onSuccess: () => { seleccion.value = []; } });
const fa = useForm({});
const agregar = () => fa.transform(() => ({ paradas: cargaParadas() })).post(`/ventas/recorrido/${props.recorrido!.id}/paradas`, { preserveScroll: true, onSuccess: () => { seleccion.value = []; agregando.value = false; } });
const agregando = ref(false);

const accion = (url: string) => router.post(url, {}, { preserveScroll: true });
const quitar = (p: Parada) => router.delete(`/ventas/recorrido/paradas/${p.id}`, { preserveScroll: true });

// Registro de visita
const visitando = ref<Parada | null>(null);
const fv = useForm({ estado: 'visitada', resultado: '', notas: '' });
const abrirVisita = (p: Parada, estado: 'visitada' | 'no_visitada') => { visitando.value = p; fv.clearErrors(); fv.estado = estado; fv.resultado = estado === 'no_visitada' ? 'ausente' : ''; fv.notas = ''; };
const registrar = () => fv.post(`/ventas/recorrido/paradas/${visitando.value!.id}/marcar`, { preserveScroll: true, onSuccess: () => { visitando.value = null; } });
const cerrando = ref(false);

const tonoParada: Record<string, 'neutro' | 'ok' | 'alerta'> = { pendiente: 'neutro', visitada: 'ok', no_visitada: 'alerta' };
const nombreEstado: Record<string, string> = { pendiente: 'Pendiente', visitada: 'Visitada', no_visitada: 'No visitada' };
const estadoRecorrido: Record<string, ['neutro' | 'info' | 'ok', string]> = { planificado: ['neutro', 'Planificado'], en_curso: ['info', 'En curso'], cerrado: ['ok', 'Cerrado'] };
const visitadas = computed(() => props.recorrido?.paradas.filter((p) => p.estado === 'visitada').length ?? 0);
const siguiente = computed(() => props.recorrido?.paradas.find((p) => p.estado === 'pendiente')?.id ?? null);

// Croquis del recorrido: puntos y trazo en un SVG propio, sin mapas de terceros (las direcciones no salen del sistema).
const ANCHO = 640;
const ALTO = 380;
const croquis = computed(() => {
    const r = props.recorrido;
    if (!r) return null;
    const pts = r.paradas.filter((p) => p.lat !== null && p.lng !== null);
    const origen = r.origen_lat !== null && r.origen_lng !== null ? { lat: r.origen_lat, lng: r.origen_lng } : null;
    const todos = [...pts.map((p) => ({ lat: p.lat!, lng: p.lng! })), ...(origen ? [origen] : [])];
    if (!todos.length) return null;
    const [minLat, maxLat] = [Math.min(...todos.map((p) => p.lat)), Math.max(...todos.map((p) => p.lat))];
    const [minLng, maxLng] = [Math.min(...todos.map((p) => p.lng)), Math.max(...todos.map((p) => p.lng))];
    const pad = 36;
    const esc = Math.min((ANCHO - 2 * pad) / Math.max(maxLng - minLng, 0.005), (ALTO - 2 * pad) / Math.max(maxLat - minLat, 0.005));
    const x = (lng: number) => pad + (lng - minLng) * esc + (ANCHO - 2 * pad - (maxLng - minLng) * esc) / 2;
    const y = (lat: number) => ALTO - pad - (lat - minLat) * esc - (ALTO - 2 * pad - (maxLat - minLat) * esc) / 2;
    const puntos = pts.map((p) => ({ id: p.id, orden: p.orden, estado: p.estado, nombre: p.nombre, x: x(p.lng!), y: y(p.lat!) }));
    const o = origen ? { x: x(origen.lng), y: y(origen.lat) } : null;
    const trazo = [...(o ? [o] : []), ...puntos].map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' ');
    return { puntos, origen: o, trazo };
});
</script>

<template>
    <Head title="Lista de recorrido" />
    <Encabezado titulo="Lista de recorrido" bajada="Ruta del día para visitas en terreno: el sistema ordena las paradas para recorrer menos kilómetros y en cada visita se registra el resultado.">
        <input :value="fecha" type="date" class="campo h-11 w-44" aria-label="Fecha del recorrido" @change="ir({ fecha: ($event.target as HTMLInputElement).value })" />
        <select v-if="ejecutivos.length" :value="ejecutivoId" class="campo h-11 w-56" aria-label="Ejecutivo" @change="ir({ ejecutivo: ($event.target as HTMLSelectElement).value })">
            <option v-for="e in ejecutivos" :key="e.id" :value="e.id">{{ e.name }}</option>
        </select>
    </Encabezado>
    <p v-if="mapasSimulado" class="mb-4 text-[13px] text-grafito">Ubicaciones aproximadas por comuna: el proveedor de mapas está simulado. Al proveedor real solo se le envía dirección y comuna.</p>

    <template v-if="recorrido">
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Kpi etiqueta="Paradas" :valor="recorrido.paradas.length">{{ visitadas }} visitadas</Kpi>
            <Kpi etiqueta="Distancia estimada" :valor="recorrido.distancia_km === null ? '—' : `${recorrido.distancia_km.toLocaleString('es-CL', { maximumFractionDigits: 1 })} km`" />
            <Kpi etiqueta="Tiempo estimado" :valor="recorrido.duracion_min === null ? '—' : `${Math.floor(recorrido.duracion_min / 60)} h ${recorrido.duracion_min % 60} min`">Con 20 min por visita</Kpi>
            <Kpi etiqueta="Estado" :valor="estadoRecorrido[recorrido.estado][1]" :tono="recorrido.estado === 'cerrado' ? 'ok' : 'normal'">{{ recorrido.optimizado ? 'Orden optimizado' : 'Orden sin optimizar' }}</Kpi>
        </div>

        <div class="grid gap-6 xl:grid-cols-5">
            <section class="tarjeta xl:col-span-3">
                <div class="flex flex-wrap items-center gap-2 border-b border-linea p-4">
                    <h2 class="mr-auto text-[18px] font-bold">Paradas</h2>
                    <template v-if="editable">
                        <button class="btn btn-secundario btn-sm" @click="agregando = !agregando"><Icono nombre="mas" :tamano="16" /> Agregar</button>
                        <button class="btn btn-secundario btn-sm" @click="accion(`/ventas/recorrido/${recorrido.id}/optimizar`)"><Icono nombre="mapa" :tamano="16" /> Optimizar orden</button>
                        <button class="btn btn-oscuro btn-sm" @click="cerrando = true">Cerrar recorrido</button>
                    </template>
                </div>
                <p v-if="recorrido.origen" class="border-b border-linea px-4 py-2 text-[14px] text-grafito">Sale desde {{ recorrido.origen }}</p>
                <ol class="divide-y divide-linea">
                    <li v-for="p in recorrido.paradas" :key="p.id" class="flex flex-wrap items-start gap-3 p-4" :class="{ 'bg-estado-alerta-bg': p.id === siguiente && esMio }">
                        <span class="num flex h-8 w-8 shrink-0 items-center justify-center rounded-full font-bold" :class="p.estado === 'pendiente' ? 'bg-carbon text-white' : 'bg-linea text-grafito'">{{ p.orden }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ p.nombre }} <Chip chico :tono="tonoParada[p.estado]">{{ nombreEstado[p.estado] }}</Chip> <Chip v-if="p.lead_id" chico tono="info">Prospecto</Chip></p>
                            <p class="text-[14px] text-grafito">{{ p.direccion }}<template v-if="p.comuna">, {{ p.comuna }}</template></p>
                            <p v-if="p.resultado" class="text-[14px]">{{ resultados[p.resultado] }}<template v-if="p.visitada_at"> · {{ fechaHora(p.visitada_at) }}</template></p>
                            <p v-if="p.notas" class="text-[14px] text-grafito">{{ p.notas }}</p>
                        </div>
                        <div v-if="p.estado === 'pendiente' && recorrido.estado !== 'cerrado'" class="flex flex-wrap gap-2">
                            <template v-if="esMio">
                                <button class="btn btn-primario btn-sm" @click="abrirVisita(p, 'visitada')">Visitada</button>
                                <button class="btn btn-secundario btn-sm" @click="abrirVisita(p, 'no_visitada')">No visitada</button>
                            </template>
                            <button v-if="editable" class="btn btn-texto btn-sm" @click="quitar(p)">Quitar</button>
                        </div>
                    </li>
                </ol>
            </section>

            <section class="tarjeta self-start p-4 xl:col-span-2">
                <h2 class="mb-2 text-[18px] font-bold">Croquis</h2>
                <svg v-if="croquis" :viewBox="`0 0 ${ANCHO} ${ALTO}`" class="h-auto w-full rounded-lg bg-hormigon" role="img" :aria-label="`Croquis del recorrido con ${croquis.puntos.length} paradas`">
                    <polyline :points="croquis.trazo" fill="none" stroke="#C9C4BE" stroke-width="2" stroke-dasharray="6 5" />
                    <g v-if="croquis.origen"><rect :x="croquis.origen.x - 9" :y="croquis.origen.y - 9" width="18" height="18" rx="3" fill="#1E1E1E" /><text :x="croquis.origen.x" :y="croquis.origen.y + 4" text-anchor="middle" font-size="11" fill="#fff" font-weight="700">S</text></g>
                    <g v-for="p in croquis.puntos" :key="p.id">
                        <title>{{ p.orden }}. {{ p.nombre }}</title>
                        <circle :cx="p.x" :cy="p.y" r="13" :fill="p.estado === 'visitada' ? '#1E7A46' : p.estado === 'no_visitada' ? '#8A5A00' : '#F7791E'" stroke="#fff" stroke-width="2" />
                        <text :x="p.x" :y="p.y + 4" text-anchor="middle" font-size="12" font-weight="700" fill="#fff">{{ p.orden }}</text>
                    </g>
                </svg>
                <p v-else class="text-grafito">Sin ubicaciones para dibujar.</p>
                <p class="mt-2 text-[13px] text-grafito">S: punto de salida. Naranjo: pendiente; verde: visitada; café: no visitada.</p>
            </section>
        </div>
    </template>

    <section v-if="(!recorrido && puedeArmar && fecha >= hoy) || agregando" class="tarjeta mt-6">
        <div class="border-b border-linea p-4">
            <h2 class="text-[18px] font-bold">{{ recorrido ? 'Agregar paradas' : 'Planificar recorrido del ' + fmtFecha(fecha) }}</h2>
            <p class="text-[14px] text-grafito">Elige clientes y prospectos con dirección (hasta {{ maxParadas }}). El orden se calcula al guardar.</p>
        </div>
        <div v-if="!recorrido" class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <Campo etiqueta="Punto de salida" id="r-orig" :error="fp.errors.origen" class="sm:col-span-2"><input id="r-orig" v-model="fp.origen" class="campo h-11" maxlength="160" placeholder="Dirección de salida" /></Campo>
            <Campo etiqueta="Comuna" id="r-com" :error="fp.errors.comuna_origen"><input id="r-com" v-model="fp.comuna_origen" class="campo h-11" maxlength="60" /></Campo>
        </div>
        <form class="flex gap-2 border-b border-linea p-4" @submit.prevent="ir({ buscar })"><input v-model="buscar" class="campo h-9 w-72" placeholder="Buscar por nombre, comuna o RUT" aria-label="Buscar candidatos" /><button class="btn btn-secundario btn-sm">Buscar</button></form>
        <div class="grid gap-6 p-4 lg:grid-cols-2">
            <div>
                <p class="etiqueta">Mis prospectos</p>
                <ul class="divide-y divide-linea">
                    <li v-for="l in candidatos.leads" :key="'l' + l.id" class="flex items-center gap-3 py-2">
                        <input :id="`cl-${l.id}`" type="checkbox" :checked="marcado('lead', l.id) || yaEsta('lead', l.id)" :disabled="yaEsta('lead', l.id)" @change="alternar('lead', l)" />
                        <label :for="`cl-${l.id}`" class="min-w-0 flex-1"><span class="font-semibold">{{ l.nombre }}</span> <Chip v-if="l.estado" chico :tono="estadoLead[l.estado][0]">{{ estadoLead[l.estado][1] }}</Chip><br /><span class="text-[13px] text-grafito">{{ l.direccion }}, {{ l.comuna }}</span></label>
                    </li>
                    <li v-if="!candidatos.leads.length" class="py-2 text-[14px] text-grafito">Sin prospectos asignados con dirección.</li>
                </ul>
            </div>
            <div>
                <p class="etiqueta">Clientes</p>
                <ul class="divide-y divide-linea">
                    <li v-for="c in candidatos.clientes" :key="'c' + c.id" class="flex items-center gap-3 py-2">
                        <input :id="`cc-${c.id}`" type="checkbox" :checked="marcado('cliente', c.id) || yaEsta('cliente', c.id)" :disabled="yaEsta('cliente', c.id)" @change="alternar('cliente', c)" />
                        <label :for="`cc-${c.id}`" class="min-w-0 flex-1"><span class="font-semibold">{{ c.nombre }}</span><br /><span class="text-[13px] text-grafito">{{ c.direccion }}, {{ c.comuna }}</span></label>
                    </li>
                    <li v-if="!candidatos.clientes.length" class="py-2 text-[14px] text-grafito">Sin clientes con dirección para esta búsqueda.</li>
                </ul>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3 border-t border-linea p-4">
            <span class="num mr-auto text-[14px]">{{ seleccion.length }} seleccionadas</span>
            <p v-for="(e, k) in errores" v-show="k.startsWith('paradas') || k === 'fecha'" :key="k" class="error w-full">{{ e }}</p>
            <button v-if="recorrido" class="btn btn-primario" :disabled="!seleccion.length || fa.processing" @click="agregar">Agregar y reordenar</button>
            <button v-else class="btn btn-primario" :disabled="!seleccion.length || fp.processing" @click="crear">Crear recorrido</button>
        </div>
    </section>
    <Vacio v-else-if="!recorrido" class="tarjeta" titulo="Sin recorrido para este día" :texto="fecha < hoy ? 'No se registró un recorrido en esta fecha.' : 'El ejecutivo aún no planifica su ruta.'" />

    <section v-if="historial.length" class="tarjeta mt-6">
        <h2 class="border-b border-linea p-4 text-[18px] font-bold">Recorridos anteriores</h2>
        <ul class="divide-y divide-linea">
            <li v-for="h in historial" :key="h.fecha" class="flex flex-wrap items-center justify-between gap-3 p-4">
                <button class="font-semibold underline-offset-2 hover:underline" @click="ir({ fecha: h.fecha })">{{ fmtFecha(h.fecha) }}</button>
                <span class="num text-[14px] text-grafito">{{ h.visitadas }} de {{ h.paradas }} visitadas<template v-if="h.km !== null"> · {{ h.km.toLocaleString('es-CL', { maximumFractionDigits: 1 }) }} km</template></span>
                <Chip chico :tono="estadoRecorrido[h.estado][0]">{{ estadoRecorrido[h.estado][1] }}</Chip>
            </li>
        </ul>
    </section>

    <Modal :abierto="!!visitando" :titulo="fv.estado === 'visitada' ? `Visita a ${visitando?.nombre ?? ''}` : `No visitada: ${visitando?.nombre ?? ''}`" @cerrar="visitando = null">
        <Campo etiqueta="Resultado" id="v-res" :obligatorio="fv.estado === 'visitada'" :error="fv.errors.resultado">
            <select id="v-res" v-model="fv.resultado" class="campo h-11"><option value="" disabled>Selecciona…</option><option v-for="(n, k) in resultados" :key="k" :value="k">{{ n }}</option></select>
        </Campo>
        <Campo etiqueta="Notas" id="v-not" :error="fv.errors.notas" class="mt-3"><textarea id="v-not" v-model="fv.notas" rows="3" class="campo" maxlength="1000"></textarea></Campo>
        <p v-if="(fv.errors as Record<string, string>).parada" class="error">{{ (fv.errors as Record<string, string>).parada }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="visitando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="fv.processing || (fv.estado === 'visitada' && !fv.resultado)" @click="registrar">Registrar</button>
        </template>
    </Modal>

    <Modal :abierto="cerrando" titulo="Cerrar recorrido" @cerrar="cerrando = false">
        <p>Las paradas que sigan pendientes quedarán como no visitadas, para reagendar.</p>
        <template #pie>
            <button class="btn btn-secundario" @click="cerrando = false">Cancelar</button>
            <button class="btn btn-oscuro" @click="accion(`/ventas/recorrido/${recorrido!.id}/cerrar`); cerrando = false">Cerrar recorrido</button>
        </template>
    </Modal>
</template>
