<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import { fechaHora } from '@/lib/formato';
import { duracion, estadoAgente, estadoLead, estadoLlamada } from '@/lib/documentos';

interface Lead { id: number; nombre: string; telefono: string; comuna: string | null; estado: string; intentos: number; max: number; campana: string; activa: boolean; proximo: string | null; notas: string | null }
interface Paso { titulo: string; texto: string; respuestas?: { texto: string; siguiente: number | null }[] }
const props = defineProps<{
    agente: { id: number; nombre: string; extension: string; estado: string; motivo: string | null; desde: string | null; cola: string | null; llamada_id: number | null };
    pausas: Record<string, string>; leads: Lead[]; resultados: Record<string, string>; simulada: boolean; hoy: { llamadas: number; contactadas: number };
    llamada: null | { id: number; estado: string; direccion: string; numero: string; numero_completo: string; campana: string | null; inicio: string | null; contestada: string | null;
        resultado: string | null; notas: string | null; lead: null | { id: number; nombre: string; rut: string | null; email: string | null; comuna: string | null; cliente_id: number | null; notas: string | null } };
    guion: null | { nombre: string; pasos: Paso[] };
}>();

const opts = { preserveScroll: true };
const estado = (e: string, motivo?: string) => router.post('/ventas/consola/estado', { estado: e, motivo }, opts);
const motivoPausa = ref('colacion');
const llamar = (l: Lead) => router.post(`/ventas/consola/llamar/${l.id}`, {}, opts);
const activa = computed(() => props.llamada && ['marcando', 'en_cola', 'en_curso'].includes(props.llamada.estado));
const colgar = () => router.post(`/ventas/llamadas/${props.llamada!.id}/colgar`, {}, opts);
const simular = (evento: string) => router.post(`/ventas/llamadas/${props.llamada!.id}/simular`, { evento }, opts);

const ft = useForm({ resultado: '', notas: '', proximo_contacto: '' });
watch(() => props.llamada?.id, () => ft.reset(), { immediate: true });
const tipificar = () => ft.transform((d) => ({ ...d, proximo_contacto: d.resultado === 'volver_a_llamar' ? d.proximo_contacto : null }))
    .post(`/ventas/llamadas/${props.llamada!.id}/tipificar`, { preserveScroll: true, onSuccess: () => ft.reset() });

// Reloj de la llamada y del estado: solo cuenta segundos en pantalla, no consulta al servidor.
const ahora = ref(Date.now());
let reloj: ReturnType<typeof setInterval>;
onMounted(() => { reloj = setInterval(() => (ahora.value = Date.now()), 1000); });
onBeforeUnmount(() => clearInterval(reloj));
const desde = (iso: string | null) => (iso ? Math.max(0, Math.floor((ahora.value - new Date(iso).getTime()) / 1000)) : 0);

const paso = ref(0);
watch(() => props.llamada?.id, () => (paso.value = 0));
const pasoActual = computed(() => props.guion?.pasos[paso.value] ?? null);
</script>

<template>
    <Head title="Mi consola" />
    <Encabezado :titulo="`Consola · ${agente.nombre}`" :bajada="`Extensión ${agente.extension}${agente.cola ? ` · cola ${agente.cola}` : ''}. Hoy: ${hoy.llamadas} llamadas, ${hoy.contactadas} contactadas.`" />

    <div class="tarjeta mb-6 flex flex-wrap items-center gap-3 p-4">
        <Chip :tono="estadoAgente[agente.estado][0]">{{ estadoAgente[agente.estado][1] }}<template v-if="agente.motivo"> · {{ agente.motivo }}</template></Chip>
        <span class="num text-grafito">{{ duracion(desde(agente.desde)) }}</span>
        <div v-if="agente.estado !== 'en_llamada'" class="flex flex-wrap items-center gap-2 sm:ml-auto">
            <button v-if="agente.estado !== 'disponible'" class="btn btn-primario btn-sm" @click="estado('disponible')">Disponible</button>
            <template v-if="agente.estado === 'disponible'">
                <select v-model="motivoPausa" class="campo h-9 w-44" aria-label="Motivo de pausa"><option v-for="(n, k) in pausas" :key="k" :value="k">{{ n }}</option></select>
                <button class="btn btn-secundario btn-sm" @click="estado('pausa', motivoPausa)">Pausa</button>
            </template>
            <button v-if="agente.estado !== 'desconectado'" class="btn btn-secundario btn-sm" @click="estado('desconectado')">Desconectarme</button>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="xl:col-span-2">
            <div v-if="llamada" class="tarjeta mb-6 p-5">
                <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[14px] text-grafito">{{ llamada.direccion === 'entrante' ? 'Llamada entrante' : 'Llamada saliente' }}<template v-if="llamada.campana"> · {{ llamada.campana }}</template></p>
                        <h2 class="text-[22px] font-bold">{{ llamada.lead?.nombre ?? llamada.numero_completo }}</h2>
                        <p class="num text-grafito">{{ llamada.numero_completo }}<template v-if="llamada.lead?.comuna"> · {{ llamada.lead.comuna }}</template><template v-if="llamada.lead?.email"> · {{ llamada.lead.email }}</template></p>
                    </div>
                    <div class="text-right">
                        <Chip :tono="estadoLlamada[llamada.estado][0]">{{ estadoLlamada[llamada.estado][1] }}</Chip>
                        <p v-if="activa" class="num mt-1 text-[22px] font-bold">{{ duracion(desde(llamada.contestada ?? llamada.inicio)) }}</p>
                    </div>
                </div>
                <div v-if="activa" class="flex flex-wrap gap-2">
                    <button class="btn btn-peligro" @click="colgar">Colgar</button>
                    <template v-if="simulada && llamada.estado === 'marcando'">
                        <span class="self-center text-[13px] text-grafito">Central simulada:</span>
                        <button class="btn btn-secundario btn-sm" @click="simular('contestada')">Contesta</button>
                        <button class="btn btn-secundario btn-sm" @click="simular('no_contesta')">No contesta</button>
                        <button class="btn btn-secundario btn-sm" @click="simular('ocupado')">Ocupado</button>
                    </template>
                </div>
                <div v-else-if="!llamada.resultado" class="mt-2 border-t border-linea pt-4">
                    <h3 class="mb-3 font-bold">Resultado de la gestión</h3>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Campo etiqueta="Resultado" id="t-res" obligatorio :error="ft.errors.resultado"><select id="t-res" v-model="ft.resultado" class="campo h-11"><option value="" disabled>Selecciona…</option><option v-for="(n, k) in resultados" :key="k" :value="k">{{ n }}</option></select></Campo>
                        <Campo v-if="ft.resultado === 'volver_a_llamar'" etiqueta="Volver a llamar el" id="t-prox" obligatorio :error="ft.errors.proximo_contacto"><input id="t-prox" v-model="ft.proximo_contacto" type="datetime-local" class="campo h-11" /></Campo>
                        <Campo etiqueta="Notas" id="t-notas" :error="ft.errors.notas" class="sm:col-span-2"><textarea id="t-notas" v-model="ft.notas" rows="3" class="campo" maxlength="1000"></textarea></Campo>
                    </div>
                    <p v-if="(ft.errors as Record<string, string>).llamada" class="error">{{ (ft.errors as Record<string, string>).llamada }}</p>
                    <button class="btn btn-primario mt-3" :disabled="ft.processing || !ft.resultado" @click="tipificar">Registrar</button>
                </div>
                <p v-else class="text-grafito">Gestión registrada: {{ resultados[llamada.resultado] }}.</p>
            </div>

            <div class="tarjeta">
                <h2 class="border-b border-linea p-4 text-[18px] font-bold">Mis prospectos</h2>
                <ul v-if="leads.length" class="divide-y divide-linea">
                    <li v-for="l in leads" :key="l.id" class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ l.nombre }} <Chip chico :tono="estadoLead[l.estado][0]">{{ estadoLead[l.estado][1] }}</Chip></p>
                            <p class="num text-[14px] text-grafito">{{ l.telefono }}<template v-if="l.comuna"> · {{ l.comuna }}</template> · {{ l.campana }} · intento {{ l.intentos }} de {{ l.max }}</p>
                            <p v-if="l.proximo" class="text-[13px] font-semibold text-naranja-profundo">Volver a llamar: {{ fechaHora(l.proximo) }}</p>
                        </div>
                        <button class="btn btn-primario btn-sm" :disabled="agente.estado !== 'disponible' || !l.activa || l.intentos >= l.max" :title="!l.activa ? 'Campaña inactiva o fuera de horario' : undefined" @click="llamar(l)">Llamar</button>
                    </li>
                </ul>
                <Vacio v-else titulo="Sin prospectos asignados" texto="Cuando el supervisor te asigne prospectos aparecerán aquí." />
            </div>
        </section>

        <section class="tarjeta self-start p-5">
            <h2 class="mb-1 text-[18px] font-bold">Guion</h2>
            <template v-if="guion && pasoActual">
                <p class="mb-3 text-[14px] text-grafito">{{ guion.nombre }} · paso {{ paso + 1 }} de {{ guion.pasos.length }}</p>
                <h3 class="font-bold">{{ pasoActual.titulo }}</h3>
                <p class="mt-2 whitespace-pre-line">{{ pasoActual.texto }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <button v-for="(r, i) in pasoActual.respuestas ?? []" :key="i" class="btn btn-secundario btn-sm" @click="paso = r.siguiente ?? Math.min(paso + 1, guion.pasos.length - 1)">{{ r.texto }}</button>
                    <template v-if="!(pasoActual.respuestas ?? []).length">
                        <button class="btn btn-secundario btn-sm" :disabled="paso === 0" @click="paso--">Anterior</button>
                        <button class="btn btn-secundario btn-sm" :disabled="paso >= guion.pasos.length - 1" @click="paso++">Siguiente</button>
                    </template>
                </div>
                <button v-if="paso > 0" class="btn btn-texto btn-sm mt-3" @click="paso = 0">Volver al inicio</button>
            </template>
            <p v-else class="text-grafito">{{ llamada ? 'La campaña no tiene guion.' : 'El guion de la campaña aparece durante la llamada.' }}</p>
        </section>
    </div>
</template>
