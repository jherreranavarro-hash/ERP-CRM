<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import { pesos } from '@/lib/formato';

interface Doc { id: number; nombre: string; total: number; cliente: string | null; direccion: string | null; comuna: string | null; telefono: string | null }
const props = defineProps<{ documento: Doc; transportistas: Record<string, string>; tarifa: number; gratisDesde: number; gratisRequiereAprobacion: boolean }>();
const d = props.documento;
const gratisSinAprobacion = d.total >= props.gratisDesde || !props.gratisRequiereAprobacion;
const form = useForm({
    documento_id: d.id, direccion: d.direccion ?? '', comuna: d.comuna ?? '', contacto: '', telefono: d.telefono ?? '',
    fecha_programada: new Date(Date.now() + 864e5).toLocaleDateString('sv-SE'), ventana: '' as '' | 'am' | 'pm', transportista: 'propio',
    cobro: gratisSinAprobacion ? 0 : props.tarifa, observaciones: '',
});
const requiereAprobacion = computed(() => Number(form.cobro) === 0 && !gratisSinAprobacion);
const err = (k: string) => (form.errors as Record<string, string>)[k];
</script>

<template>
    <Head title="Programar despacho" />
    <Encabezado titulo="Programar despacho" :volver="`/caja/documentos/${d.id}`" :bajada="`${d.nombre} · ${d.cliente ?? 'Consumidor final'} · ${pesos(d.total)}`" />

    <form class="tarjeta grid max-w-4xl gap-4 p-5 md:grid-cols-2" @submit.prevent="form.transform((x) => ({ ...x, ventana: x.ventana || null })).post('/inventario/despachos')">
        <div class="md:col-span-2"><Campo etiqueta="Dirección de entrega" id="dir" obligatorio :error="form.errors.direccion"><input id="dir" v-model="form.direccion" class="campo h-11" maxlength="150" /></Campo></div>
        <Campo etiqueta="Comuna" id="comuna" obligatorio :error="form.errors.comuna"><input id="comuna" v-model="form.comuna" class="campo h-11" maxlength="60" /></Campo>
        <Campo etiqueta="Fecha" id="fecha" obligatorio :error="form.errors.fecha_programada"><input id="fecha" v-model="form.fecha_programada" type="date" class="campo h-11" /></Campo>
        <Campo etiqueta="Contacto en destino" id="contacto" :error="form.errors.contacto"><input id="contacto" v-model="form.contacto" class="campo h-11" maxlength="100" /></Campo>
        <Campo etiqueta="Teléfono" id="tel" :error="form.errors.telefono"><input id="tel" v-model="form.telefono" class="campo h-11" maxlength="20" inputmode="tel" /></Campo>
        <Campo etiqueta="Ventana horaria" id="ventana" :error="form.errors.ventana">
            <select id="ventana" v-model="form.ventana" class="campo h-11"><option value="">Todo el día</option><option value="am">Mañana</option><option value="pm">Tarde</option></select>
        </Campo>
        <Campo etiqueta="Transportista" id="transp" :error="form.errors.transportista">
            <select id="transp" v-model="form.transportista" class="campo h-11"><option v-for="(n, k) in transportistas" :key="k" :value="k">{{ n }}</option></select>
        </Campo>
        <Campo etiqueta="Cargo de despacho" id="cobro" obligatorio :ayuda="`Sugerido ${pesos(tarifa)}. Gratis sin aprobación en ventas desde ${pesos(gratisDesde)}.`" :error="form.errors.cobro">
            <input id="cobro" v-model.number="form.cobro" type="number" min="0" step="1" class="campo num h-11" />
        </Campo>
        <div class="md:col-span-2"><Campo etiqueta="Observaciones" id="obs" :error="form.errors.observaciones"><textarea id="obs" v-model="form.observaciones" rows="2" class="campo" maxlength="300" placeholder="Referencias, horario de recepción de la obra…" /></Campo></div>
        <p v-if="requiereAprobacion" class="rounded-boton bg-[#FFF3D6] p-3 text-[#8A5A00] md:col-span-2">Despacho gratis en una venta bajo {{ pesos(gratisDesde) }}: quedará por aprobar por otra persona.</p>
        <p v-if="err('documento')" class="error md:col-span-2">{{ err('documento') }}</p>
        <div class="flex justify-end md:col-span-2"><button type="submit" class="btn btn-primario" :disabled="form.processing">{{ requiereAprobacion ? 'Enviar a aprobación' : 'Programar despacho' }}</button></div>
    </form>
</template>
