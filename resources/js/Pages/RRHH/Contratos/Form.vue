<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import { pesos } from '@/lib/formato';

interface Datos { tipo: string; cargo: string; jornada_horas: number; sueldo_base: number; gratificacion: boolean; colacion: number; movilizacion: number; comision_pct: number }
const props = defineProps<{
    empleado: { id: number; nombre: string; cargo: string };
    contrato: (Datos & { id: number; plantilla_id: number | null; texto: string | null; fecha_inicio: string; fecha_termino: string | null }) | null;
    base: Datos | null;
    plantillas: { id: number; nombre: string }[];
    tipos: Record<string, string>;
    jornadaMaxima: number;
    imm: number;
}>();
const c = props.contrato;
const b = c ?? props.base;
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Santiago' });
const form = useForm({
    empleado_id: props.empleado.id, tipo: b?.tipo ?? 'indefinido', cargo: b?.cargo ?? props.empleado.cargo, jornada_horas: b?.jornada_horas ?? props.jornadaMaxima,
    sueldo_base: b?.sueldo_base ?? props.imm, gratificacion: b?.gratificacion ?? true, colacion: b?.colacion ?? 0, movilizacion: b?.movilizacion ?? 0, comision_pct: b?.comision_pct ?? 0,
    fecha_inicio: c?.fecha_inicio ?? hoy, fecha_termino: c?.fecha_termino ?? '', plantilla_id: c?.plantilla_id ?? (props.plantillas[0]?.id ?? null), texto: c?.texto ?? '',
});
const minimo = computed(() => Math.ceil(props.imm * (Number(form.jornada_horas) || 0) / props.jornadaMaxima));
const guardar = () => (c ? form.put(`/rrhh/contratos/${c.id}`) : form.post('/rrhh/contratos'));
</script>

<template>
    <Head :title="c ? 'Editar contrato' : 'Nuevo contrato'" />
    <Encabezado :titulo="c ? 'Editar contrato' : 'Nuevo contrato'" :bajada="`${empleado.nombre}. ${base ? 'Parte de las condiciones del contrato vigente: al firmarse, el nuevo lo reemplaza.' : ''}`" :volver="`/rrhh/personal/${empleado.id}`" />

    <form class="space-y-6" @submit.prevent="guardar">
        <section class="tarjeta grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
            <Campo etiqueta="Tipo" id="tipo" obligatorio :error="form.errors.tipo"><select id="tipo" v-model="form.tipo" class="campo h-11"><option v-for="(n, k) in tipos" :key="k" :value="k">{{ n }}</option></select></Campo>
            <Campo etiqueta="Cargo" id="cargo" obligatorio :error="form.errors.cargo"><input id="cargo" v-model="form.cargo" class="campo h-11" maxlength="80" /></Campo>
            <Campo etiqueta="Inicio" id="inicio" obligatorio :error="form.errors.fecha_inicio"><input id="inicio" v-model="form.fecha_inicio" type="date" class="campo h-11" /></Campo>
            <Campo v-if="form.tipo !== 'indefinido'" etiqueta="Término" id="termino" obligatorio :error="form.errors.fecha_termino"><input id="termino" v-model="form.fecha_termino" type="date" class="campo h-11" /></Campo>
            <Campo etiqueta="Jornada semanal (horas)" id="jornada" obligatorio :error="form.errors.jornada_horas" :ayuda="`Máximo legal: ${jornadaMaxima} horas.`"><input id="jornada" v-model.number="form.jornada_horas" type="number" min="1" :max="jornadaMaxima" class="campo h-11" /></Campo>
            <Campo etiqueta="Sueldo base" id="sueldo" obligatorio :error="form.errors.sueldo_base" :ayuda="`Mínimo para esta jornada: ${pesos(minimo)}.`"><input id="sueldo" v-model.number="form.sueldo_base" type="number" min="1" class="campo num h-11" /></Campo>
            <Campo etiqueta="Colación (no imponible)" id="colacion" :error="form.errors.colacion"><input id="colacion" v-model.number="form.colacion" type="number" min="0" class="campo num h-11" /></Campo>
            <Campo etiqueta="Movilización (no imponible)" id="movil" :error="form.errors.movilizacion"><input id="movil" v-model.number="form.movilizacion" type="number" min="0" class="campo num h-11" /></Campo>
            <Campo etiqueta="Comisión sobre ventas (%)" id="comision" :error="form.errors.comision_pct" ayuda="Las reglas por categoría la reemplazan."><input id="comision" v-model.number="form.comision_pct" type="number" step="0.01" min="0" max="30" class="campo h-11" /></Campo>
            <div class="flex items-end pb-2"><Interruptor v-model="form.gratificacion" etiqueta="Gratificación legal (art. 50)" /></div>
            <Campo etiqueta="Plantilla" id="plantilla" :error="form.errors.plantilla_id" class="xl:col-span-2" ayuda="El texto se redacta con los datos de la ficha y del contrato.">
                <select id="plantilla" v-model="form.plantilla_id" class="campo h-11"><option :value="null">Sin plantilla (texto libre)</option><option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.nombre }}</option></select>
            </Campo>
        </section>
        <section v-if="!form.plantilla_id" class="tarjeta p-5">
            <Campo etiqueta="Texto del contrato" id="texto" :error="form.errors.texto"><textarea id="texto" v-model="form.texto" rows="14" class="campo py-3" /></Campo>
        </section>
        <p v-if="(form.errors as any).contrato" class="error">{{ (form.errors as any).contrato }}</p>
        <div class="flex justify-end"><button type="submit" class="btn btn-primario" :disabled="form.processing">{{ c ? 'Guardar' : 'Redactar contrato' }}</button></div>
    </form>
</template>
