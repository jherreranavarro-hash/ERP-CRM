<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Vacio from '@/Components/Vacio.vue';
import { fecha, fechaHora, haceCuanto, numero } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Alerta { id: number; tipo: 'seguridad' | 'operacion'; codigo: string; nombre: string; titulo: string; detalle: Record<string, unknown>; plataforma: boolean; notificada: boolean; fecha: string; atendida: string | null }
interface Respaldo { id: number; tipo: 'respaldo' | 'restauracion'; resultado: 'ok' | 'fallo'; archivo: string | null; bytes: number | null; duracion_s: number | null; detalle: string | null; servidor: string | null; fecha: string }
interface Ejecucion { id: number; categoria: string; accion: string; registros: number; limite: string; plataforma: boolean; fecha: string }
const props = defineProps<{
    alertas: Alerta[];
    verAtendidas: boolean;
    respaldos: { frecuencia_horas: number; calendario: string; respaldo_atrasado: boolean; prueba_atrasada: boolean; ultimo: Respaldo | null; ultima_prueba: Respaldo | null; historial: Respaldo[] };
    retencion: Ejecucion[];
    solicitudesPendientes: number;
}>();
const puede = usePermisos();
const abiertas = computed(() => props.alertas.filter((a) => !a.atendida));
const deSeguridad = computed(() => abiertas.value.filter((a) => a.tipo === 'seguridad').length);
const atender = (a: Alerta) => router.post(`/configuracion/operacion/alertas/${a.id}/atender`, {}, { preserveScroll: true });
const alternar = () => router.get('/configuracion/operacion', props.verAtendidas ? {} : { atendidas: 1 }, { preserveScroll: true, preserveState: true });
const tamano = (b: number | null) => (b == null ? '—' : b >= 1073741824 ? `${(b / 1073741824).toFixed(1)} GB` : `${(b / 1048576).toFixed(1)} MB`);
const CATEGORIA: Record<string, string> = { grabaciones: 'Grabaciones', mensajeria: 'WhatsApp y LinkedIn', prospectos: 'Prospectos', logs_api: 'Logs de API', logs_app: 'Logs de aplicación', todas: 'Todas' };
const ACCION: Record<string, string> = { archivar: 'Archivado', anonimizar: 'Anonimizado', eliminar: 'Eliminado', suspendida: 'Suspendida por retención legal' };
const valor = (v: unknown) => (typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v));
</script>

<template>
    <Head title="Operación y continuidad" />
    <Encabezado titulo="Operación y continuidad" bajada="Alertas de seguridad y operación, estado de los respaldos con la última prueba de restauración, y lo que hizo la política de retención de datos." />

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi etiqueta="Alertas abiertas" :valor="numero(abiertas.length)" :tono="abiertas.length ? 'naranja' : 'ok'" />
        <Kpi etiqueta="De seguridad" :valor="numero(deSeguridad)" :tono="deSeguridad ? 'error' : 'ok'" />
        <Kpi etiqueta="Último respaldo" :valor="respaldos.ultimo ? haceCuanto(respaldos.ultimo.fecha) : 'Nunca'" :tono="respaldos.respaldo_atrasado ? 'error' : 'ok'" />
        <Kpi etiqueta="Última prueba de restauración" :valor="respaldos.ultima_prueba ? fecha(respaldos.ultima_prueba.fecha) : 'Nunca'" :tono="respaldos.prueba_atrasada ? 'error' : 'ok'" />
    </div>

    <p v-if="solicitudesPendientes" class="tarjeta mb-6 flex flex-wrap items-center justify-between gap-2 p-4 text-[14px]">
        <span><strong>{{ solicitudesPendientes }}</strong> solicitud(es) de privilegios esperan que otra persona las apruebe.</span>
        <Link href="/configuracion/roles" class="btn btn-secundario btn-sm">Revisar en Perfiles</Link>
    </p>

    <section class="mb-8">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-[18px] font-bold">Alertas</h2>
            <button class="btn btn-texto btn-sm" @click="alternar">{{ verAtendidas ? 'Ocultar atendidas' : 'Ver también las atendidas' }}</button>
        </div>
        <Vacio v-if="!alertas.length" titulo="Sin alertas" texto="Cuando algo requiera atención (API, folios, integraciones, respaldos, caja o DTE) aparecerá aquí y se avisará por correo." />
        <ul v-else class="grid gap-3">
            <li v-for="a in alertas" :key="a.id" class="tarjeta p-4" :class="{ 'opacity-70': a.atendida }">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="mb-1 flex flex-wrap gap-1">
                            <Chip chico :tono="a.tipo === 'seguridad' ? 'error' : 'alerta'">{{ a.tipo === 'seguridad' ? 'Seguridad' : 'Operación' }}</Chip>
                            <Chip chico>{{ a.nombre }}</Chip>
                            <Chip v-if="a.plataforma" chico tono="info">Plataforma</Chip>
                            <Chip v-if="a.atendida" chico tono="ok">Atendida</Chip>
                        </div>
                        <p class="font-semibold">{{ a.titulo }}</p>
                        <dl v-if="Object.keys(a.detalle).length" class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-[13px] text-grafito">
                            <div v-for="(v, k) in a.detalle" :key="k"><dt class="inline">{{ String(k).replaceAll('_', ' ') }}:</dt> <dd class="num inline">{{ valor(v) }}</dd></div>
                        </dl>
                        <p class="mt-1 text-[13px] text-grafito">{{ fechaHora(a.fecha) }}<span v-if="a.notificada"> · avisada por correo</span><span v-if="a.atendida"> · atendida {{ fechaHora(a.atendida) }}</span></p>
                    </div>
                    <button v-if="!a.atendida && puede('configuracion.operacion.editar')" class="btn btn-secundario btn-sm" @click="atender(a)">Marcar atendida</button>
                </div>
            </li>
        </ul>
    </section>

    <section class="mb-8">
        <h2 class="mb-1 text-[18px] font-bold">Respaldos y continuidad</h2>
        <p class="mb-3 text-[14px] text-grafito">Respaldo cada {{ respaldos.frecuencia_horas }} h y prueba de restauración {{ respaldos.calendario }}. Los registra el servidor al terminar cada tarea.</p>
        <div class="grid gap-4 lg:grid-cols-2">
            <article v-for="(r, i) in [respaldos.ultimo, respaldos.ultima_prueba]" :key="i" class="tarjeta p-4">
                <template v-if="r">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <h3 class="font-bold">{{ r.tipo === 'respaldo' ? 'Último respaldo exitoso' : 'Última prueba de restauración exitosa' }}</h3>
                        <Chip chico tono="ok">OK</Chip>
                    </div>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[14px]">
                        <dt class="text-grafito">Fecha</dt><dd>{{ fechaHora(r.fecha) }}</dd>
                        <template v-if="r.archivo"><dt class="text-grafito">Archivo</dt><dd class="num break-all">{{ r.archivo }}</dd></template>
                        <template v-if="r.bytes"><dt class="text-grafito">Tamaño</dt><dd class="num">{{ tamano(r.bytes) }}</dd></template>
                        <template v-if="r.duracion_s != null"><dt class="text-grafito">Duración</dt><dd class="num">{{ r.duracion_s }} s</dd></template>
                        <template v-if="r.detalle"><dt class="text-grafito">Detalle</dt><dd>{{ r.detalle }}</dd></template>
                    </dl>
                </template>
                <Vacio v-else titulo="Sin registros" texto="El servidor aún no informa un resultado exitoso. Revisa la guía de despliegue (docs/despliegue.md)." />
            </article>
        </div>
        <div v-if="respaldos.historial.length" class="tarjeta mt-4 overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Fecha</th><th>Tarea</th><th>Resultado</th><th class="text-right">Tamaño</th><th>Detalle</th></tr></thead>
                <tbody>
                    <tr v-for="r in respaldos.historial" :key="r.id">
                        <td class="whitespace-nowrap">{{ fechaHora(r.fecha) }}</td>
                        <td>{{ r.tipo === 'respaldo' ? 'Respaldo' : 'Prueba de restauración' }}</td>
                        <td><Chip chico :tono="r.resultado === 'ok' ? 'ok' : 'error'">{{ r.resultado === 'ok' ? 'OK' : 'Falló' }}</Chip></td>
                        <td class="num text-right">{{ tamano(r.bytes) }}</td>
                        <td class="max-w-[28rem] truncate">{{ r.detalle ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2 class="mb-1 text-[18px] font-bold">Retención de datos</h2>
        <p class="mb-3 text-[14px] text-grafito">Corre cada madrugada según el mantenedor "Retención de datos". La auditoría y los documentos tributarios no se borran desde la aplicación.</p>
        <Vacio v-if="!retencion.length" titulo="Sin ejecuciones" texto="La primera pasada corre a las 02:30." />
        <div v-else class="tarjeta overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Fecha</th><th>Tipo de dato</th><th>Acción</th><th class="text-right">Registros</th><th>Anteriores a</th></tr></thead>
                <tbody>
                    <tr v-for="r in retencion" :key="r.id">
                        <td class="whitespace-nowrap">{{ fechaHora(r.fecha) }}</td>
                        <td>{{ CATEGORIA[r.categoria] ?? r.categoria }}<span v-if="r.plataforma" class="text-grafito"> · plataforma</span></td>
                        <td><Chip chico :tono="r.accion === 'suspendida' ? 'alerta' : 'neutro'">{{ ACCION[r.accion] ?? r.accion }}</Chip></td>
                        <td class="num text-right">{{ numero(r.registros) }}</td>
                        <td class="whitespace-nowrap">{{ fecha(r.limite) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
