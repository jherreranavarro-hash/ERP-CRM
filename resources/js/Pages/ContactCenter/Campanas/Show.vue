<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Icono from '@/Components/Icono.vue';
import type { Paginado } from '@/types';
import { fecha, fechaHora, numero, pesos } from '@/lib/formato';
import { DIAS_SEMANA, estadoCampana, estadoLead } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Lead { id: number; nombre: string; telefono: string; comuna: string | null; estado: string; agente: string | null; intentos: number; ultimo: string | null }
const props = defineProps<{
    campana: { id: number; nombre: string; tipo: string; estado: string; descripcion: string | null; inicio: string; fin: string | null; horario: { dias: number[]; desde: string; hasta: string };
        max_intentos: number; asignacion: string; guion: string | null; cola: string | null; agentes: { id: number; nombre: string }[] };
    avance: { contactos: number; meta_contactos: number; cotizaciones: number; meta_cotizaciones: number; ventas: number; meta_ventas: number; llamadas: number };
    porEstado: Record<string, number>; leads: Paginado<Lead>; filtros: { estado?: string; buscar?: string };
    estadosLead: Record<string, string>; estados: Record<string, string>; tipos: Record<string, string>; asignaciones: Record<string, string>;
}>();
const puede = usePermisos();
const url = `/ventas/campanas/${props.campana.id}`;
const buscar = ref(props.filtros.buscar ?? '');
const filtrar = (estado?: string) => router.get(url, { ...(estado ? { estado } : {}), ...(buscar.value ? { buscar: buscar.value } : {}) }, { preserveState: true, preserveScroll: true, replace: true });
const transiciones: Record<string, [string, string][]> = { borrador: [['activa', 'Activar'], ['terminada', 'Terminar']], activa: [['pausada', 'Pausar'], ['terminada', 'Terminar']], pausada: [['activa', 'Reanudar'], ['terminada', 'Terminar']], terminada: [] };
const cambiar = (estado: string) => router.post(`${url}/estado`, { estado }, { preserveScroll: true });
const importando = ref(false);
const fi = useForm({ archivo: null as File | null });
const importar = () => fi.post(`${url}/importar`, { forceFormData: true, preserveScroll: true, onSuccess: () => { importando.value = false; fi.reset(); } });
const pct = (a: number, b: number) => (b ? Math.round((a / b) * 100) : 0);
</script>

<template>
    <Head :title="campana.nombre" />
    <Encabezado :titulo="campana.nombre" :bajada="`${tipos[campana.tipo]} · ${fecha(campana.inicio)}${campana.fin ? ` – ${fecha(campana.fin)}` : ''} · ${asignaciones[campana.asignacion]}`" volver="/ventas/campanas">
        <Chip :tono="estadoCampana[campana.estado][0]">{{ estadoCampana[campana.estado][1] }}</Chip>
        <template v-if="puede('ventascrm.campana.editar')">
            <button v-for="[e, n] in transiciones[campana.estado]" :key="e" class="btn" :class="e === 'terminada' ? 'btn-secundario' : 'btn-primario'" @click="cambiar(e)">{{ n }}</button>
            <Link v-if="campana.estado !== 'terminada'" :href="`${url}/editar`" class="btn btn-secundario no-underline">Editar</Link>
        </template>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Contactos" :valor="numero(avance.contactos)" :tono="avance.contactos >= avance.meta_contactos && avance.meta_contactos ? 'ok' : 'normal'">Meta {{ numero(avance.meta_contactos) }} · {{ pct(avance.contactos, avance.meta_contactos) }}%</Kpi>
        <Kpi etiqueta="Cotizaciones" :valor="numero(avance.cotizaciones)" :tono="avance.cotizaciones >= avance.meta_cotizaciones && avance.meta_cotizaciones ? 'ok' : 'normal'">Meta {{ numero(avance.meta_cotizaciones) }} · {{ pct(avance.cotizaciones, avance.meta_cotizaciones) }}%</Kpi>
        <Kpi etiqueta="Ventas netas" :valor="pesos(avance.ventas)" :tono="avance.ventas >= avance.meta_ventas && avance.meta_ventas ? 'ok' : 'naranja'">Meta {{ pesos(avance.meta_ventas) }}</Kpi>
        <Kpi etiqueta="Llamadas" :valor="numero(avance.llamadas)">Máximo {{ campana.max_intentos }} intentos por prospecto</Kpi>
    </div>

    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <div class="tarjeta p-4 text-[14px]">
            <p class="etiqueta">Horario de discado</p>
            <p>{{ campana.horario.dias.map((d) => DIAS_SEMANA[d]).join(' ') }} · {{ campana.horario.desde }} a {{ campana.horario.hasta }}</p>
            <p class="etiqueta mt-3">Guion y cola</p>
            <p>{{ campana.guion ?? 'Sin guion' }} · {{ campana.cola ?? 'Sin cola' }}</p>
        </div>
        <div class="tarjeta p-4 text-[14px] lg:col-span-2">
            <p class="etiqueta">Agentes</p>
            <p>{{ campana.agentes.map((a) => a.nombre).join(', ') || 'Sin agentes: asígnalos editando la campaña.' }}</p>
            <p v-if="campana.descripcion" class="etiqueta mt-3">Descripción</p>
            <p v-if="campana.descripcion" class="whitespace-pre-line">{{ campana.descripcion }}</p>
        </div>
    </div>

    <div class="tarjeta">
        <div class="flex flex-wrap items-center gap-2 border-b border-linea p-4">
            <h2 class="mr-auto text-[18px] font-bold">Prospectos</h2>
            <form class="flex gap-2" @submit.prevent="filtrar(filtros.estado)"><input v-model="buscar" class="campo h-9 w-56" placeholder="Nombre o teléfono" aria-label="Buscar prospecto" /><button class="btn btn-secundario btn-sm">Buscar</button></form>
            <Link v-if="puede('ventascrm.asignacion.ver')" :href="`/ventas/asignacion?campana=${campana.id}`" class="btn btn-secundario btn-sm no-underline">Asignar</Link>
            <button v-if="puede('ventascrm.campana.editar') && campana.estado !== 'terminada'" class="btn btn-primario btn-sm" @click="importando = true"><Icono nombre="subir" :tamano="16" /> Cargar CSV</button>
        </div>
        <div class="flex flex-wrap gap-2 border-b border-linea p-4">
            <button class="btn btn-sm" :class="!filtros.estado ? 'btn-oscuro' : 'btn-secundario'" @click="filtrar()">Todos</button>
            <button v-for="(n, k) in estadosLead" :key="k" class="btn btn-sm" :class="filtros.estado === k ? 'btn-oscuro' : 'btn-secundario'" @click="filtrar(String(k))">{{ n }} <span class="num">({{ porEstado[k] ?? 0 }})</span></button>
        </div>
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Prospecto</th><th>Teléfono</th><th>Estado</th><th>Agente</th><th class="text-right">Intentos</th><th>Último contacto</th></tr></thead>
                <tbody>
                    <tr v-for="l in leads.data" :key="l.id">
                        <td><p class="font-semibold">{{ l.nombre }}</p><p class="text-[13px] text-grafito">{{ l.comuna }}</p></td>
                        <td class="num">{{ l.telefono }}</td>
                        <td><Chip chico :tono="estadoLead[l.estado][0]">{{ estadoLead[l.estado][1] }}</Chip></td>
                        <td>{{ l.agente ?? '—' }}</td>
                        <td class="num text-right">{{ l.intentos }}</td>
                        <td class="num text-[14px]">{{ l.ultimo ? fechaHora(l.ultimo) : '—' }}</td>
                    </tr>
                    <tr v-if="!leads.data.length"><td colspan="6" class="text-center text-grafito">Sin prospectos con este filtro.</td></tr>
                </tbody>
            </table>
        </div>
        <Paginacion :datos="leads" nombre="prospectos" />
    </div>

    <Modal :abierto="importando" titulo="Cargar prospectos" @cerrar="importando = false">
        <p class="mb-3 text-[14px] text-grafito">CSV separado por punto y coma, con encabezado: <span class="num">nombre;telefono;rut;email;comuna;direccion</span>. Hasta 5.000 filas. Los teléfonos repetidos se omiten y los que están en lista negra quedan como «No contactar».</p>
        <Campo etiqueta="Archivo CSV" id="imp-arch" obligatorio :error="fi.errors.archivo"><input id="imp-arch" type="file" accept=".csv,.txt" class="campo h-auto py-2" @change="fi.archivo = ($event.target as HTMLInputElement).files?.[0] ?? null" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="importando = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fi.processing || !fi.archivo" @click="importar">Cargar</button>
        </template>
    </Modal>
</template>
