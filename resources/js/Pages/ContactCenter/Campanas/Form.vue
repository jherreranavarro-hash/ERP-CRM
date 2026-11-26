<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import { DIAS_SEMANA } from '@/lib/documentos';

interface Horario { dias: number[]; desde: string; hasta: string }
interface Campana { id: number; nombre: string; tipo: string; descripcion: string | null; meta_contactos: number; meta_cotizaciones: number; meta_ventas: number; horario: Horario; max_intentos: number;
    asignacion: string; guion_id: number | null; cola_id: number | null; fecha_inicio: string; fecha_fin: string | null; agentes: number[] }
const props = defineProps<{ campana: Campana | null; tipos: Record<string, string>; asignaciones: Record<string, string>; guiones: { id: number; nombre: string }[]; colas: { id: number; nombre: string }[];
    agentes: { id: number; nombre: string; extension: string }[] }>();

const c = props.campana;
const form = useForm({
    nombre: c?.nombre ?? '', tipo: c?.tipo ?? 'saliente', descripcion: c?.descripcion ?? '', fecha_inicio: c?.fecha_inicio ?? new Date().toISOString().slice(0, 10), fecha_fin: c?.fecha_fin ?? '',
    meta_contactos: c?.meta_contactos ?? 100, meta_cotizaciones: c?.meta_cotizaciones ?? 20, meta_ventas: c?.meta_ventas ?? 0,
    horario: { dias: [...(c?.horario.dias ?? [1, 2, 3, 4, 5])], desde: c?.horario.desde ?? '09:00', hasta: c?.horario.hasta ?? '18:00' },
    max_intentos: c?.max_intentos ?? 3, asignacion: c?.asignacion ?? 'round_robin', guion_id: c?.guion_id ?? null, cola_id: c?.cola_id ?? null, agentes: [...(c?.agentes ?? [])],
});
const errores = form.errors as Record<string, string>;
const alternar = <T,>(lista: T[], v: T) => (lista.includes(v) ? lista.filter((x) => x !== v) : [...lista, v]);
const guardar = () => {
    const f = form.transform((d) => ({ ...d, fecha_fin: d.fecha_fin || null, descripcion: d.descripcion || null }));
    c ? f.put(`/ventas/campanas/${c.id}`) : f.post('/ventas/campanas');
};
</script>

<template>
    <Head :title="c ? `Editar ${c.nombre}` : 'Nueva campaña'" />
    <Encabezado :titulo="c ? `Editar ${c.nombre}` : 'Nueva campaña'" :volver="c ? `/ventas/campanas/${c.id}` : '/ventas/campanas'" />

    <form class="tarjeta grid max-w-4xl gap-4 p-5 sm:grid-cols-2" @submit.prevent="guardar">
        <Campo etiqueta="Nombre" id="c-nom" obligatorio :error="form.errors.nombre" class="sm:col-span-2"><input id="c-nom" v-model="form.nombre" class="campo h-11" maxlength="100" /></Campo>
        <Campo etiqueta="Tipo" id="c-tipo" obligatorio :error="form.errors.tipo"><select id="c-tipo" v-model="form.tipo" class="campo h-11"><option v-for="(n, k) in tipos" :key="k" :value="k">{{ n }}</option></select></Campo>
        <Campo etiqueta="Asignación de prospectos" id="c-asig" obligatorio :error="form.errors.asignacion"><select id="c-asig" v-model="form.asignacion" class="campo h-11"><option v-for="(n, k) in asignaciones" :key="k" :value="k">{{ n }}</option></select></Campo>
        <Campo etiqueta="Inicio" id="c-ini" obligatorio :error="form.errors.fecha_inicio"><input id="c-ini" v-model="form.fecha_inicio" type="date" class="campo h-11" /></Campo>
        <Campo etiqueta="Término" id="c-fin" :error="form.errors.fecha_fin" ayuda="Vacío: sin fecha de término."><input id="c-fin" v-model="form.fecha_fin" type="date" class="campo h-11" /></Campo>
        <Campo etiqueta="Descripción" id="c-desc" :error="form.errors.descripcion" class="sm:col-span-2"><textarea id="c-desc" v-model="form.descripcion" rows="3" class="campo" maxlength="2000"></textarea></Campo>

        <h2 class="mt-2 text-[18px] font-bold sm:col-span-2">Metas</h2>
        <div class="grid gap-4 sm:col-span-2 sm:grid-cols-3">
            <Campo etiqueta="Contactos" id="c-mc" obligatorio :error="form.errors.meta_contactos"><input id="c-mc" v-model.number="form.meta_contactos" type="number" min="0" class="campo num h-11" /></Campo>
            <Campo etiqueta="Cotizaciones" id="c-mq" obligatorio :error="form.errors.meta_cotizaciones"><input id="c-mq" v-model.number="form.meta_cotizaciones" type="number" min="0" class="campo num h-11" /></Campo>
            <Campo etiqueta="Ventas netas ($)" id="c-mv" obligatorio :error="form.errors.meta_ventas"><input id="c-mv" v-model.number="form.meta_ventas" type="number" min="0" class="campo num h-11" /></Campo>
        </div>

        <h2 class="mt-2 text-[18px] font-bold sm:col-span-2">Horario de discado</h2>
        <div class="sm:col-span-2">
            <p class="etiqueta">Días</p>
            <div class="flex flex-wrap gap-2">
                <button v-for="d in 7" :key="d" type="button" class="btn btn-sm" :class="form.horario.dias.includes(d) ? 'btn-oscuro' : 'btn-secundario'" :aria-pressed="form.horario.dias.includes(d)" @click="form.horario.dias = alternar(form.horario.dias, d)">{{ DIAS_SEMANA[d] }}</button>
            </div>
            <p v-if="errores['horario.dias']" class="error">{{ errores['horario.dias'] }}</p>
        </div>
        <Campo etiqueta="Desde" id="c-hd" obligatorio :error="errores['horario.desde']"><input id="c-hd" v-model="form.horario.desde" type="time" class="campo h-11" /></Campo>
        <Campo etiqueta="Hasta" id="c-hh" obligatorio :error="errores['horario.hasta']"><input id="c-hh" v-model="form.horario.hasta" type="time" class="campo h-11" /></Campo>
        <Campo etiqueta="Intentos máximos por prospecto" id="c-int" obligatorio :error="form.errors.max_intentos"><input id="c-int" v-model.number="form.max_intentos" type="number" min="1" max="20" class="campo num h-11" /></Campo>

        <h2 class="mt-2 text-[18px] font-bold sm:col-span-2">Guion, cola y agentes</h2>
        <Campo etiqueta="Guion" id="c-guion" :error="form.errors.guion_id"><select id="c-guion" v-model="form.guion_id" class="campo h-11"><option :value="null">Sin guion</option><option v-for="g in guiones" :key="g.id" :value="g.id">{{ g.nombre }}</option></select></Campo>
        <Campo etiqueta="Cola (entrantes)" id="c-cola" :error="form.errors.cola_id"><select id="c-cola" v-model="form.cola_id" class="campo h-11"><option :value="null">Sin cola</option><option v-for="q in colas" :key="q.id" :value="q.id">{{ q.nombre }}</option></select></Campo>
        <div class="sm:col-span-2">
            <p class="etiqueta">Agentes</p>
            <div v-if="agentes.length" class="flex flex-wrap gap-2">
                <button v-for="a in agentes" :key="a.id" type="button" class="btn btn-sm" :class="form.agentes.includes(a.id) ? 'btn-oscuro' : 'btn-secundario'" :aria-pressed="form.agentes.includes(a.id)" @click="form.agentes = alternar(form.agentes, a.id)">{{ a.nombre }} · {{ a.extension }}</button>
            </div>
            <p v-else class="text-[14px] text-grafito">No hay agentes activos.</p>
        </div>
        <div class="flex gap-2 sm:col-span-2">
            <button type="submit" class="btn btn-primario" :disabled="form.processing">Guardar</button>
        </div>
    </form>
</template>
