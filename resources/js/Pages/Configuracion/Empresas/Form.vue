<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import { rut as formatearRut } from '@/lib/formato';

interface Empresa {
    id: number; rut: string; razon_social: string; nombre_fantasia: string | null; giro: string | null; acteco: string | null;
    direccion: string | null; comuna: string | null; ciudad: string | null; telefono: string | null; email: string | null;
    resolucion_sii_numero: number | null; resolucion_sii_fecha: string | null; activo: boolean;
}
const props = defineProps<{ empresa: Empresa | null }>();

const form = useForm({
    rut: props.empresa ? formatearRut(props.empresa.rut) : '',
    razon_social: props.empresa?.razon_social ?? '',
    nombre_fantasia: props.empresa?.nombre_fantasia ?? '',
    giro: props.empresa?.giro ?? '',
    acteco: props.empresa?.acteco ?? '',
    direccion: props.empresa?.direccion ?? '',
    comuna: props.empresa?.comuna ?? '',
    ciudad: props.empresa?.ciudad ?? '',
    telefono: props.empresa?.telefono ?? '',
    email: props.empresa?.email ?? '',
    resolucion_sii_numero: props.empresa?.resolucion_sii_numero ?? null,
    resolucion_sii_fecha: props.empresa?.resolucion_sii_fecha ?? '',
    activo: props.empresa?.activo ?? true,
});

function guardar() {
    if (props.empresa) form.put(`/configuracion/empresas/${props.empresa.id}`);
    else form.post('/configuracion/empresas');
}
</script>

<template>
    <Head :title="empresa ? 'Editar empresa' : 'Nueva empresa'" />
    <Encabezado :titulo="empresa ? empresa.razon_social : 'Nueva empresa'" bajada="Datos del emisor. RUT, giro, ACTECO y resolución SII se usan al emitir documentos tributarios." volver="/configuracion/empresas" />

    <form class="tarjeta max-w-4xl p-6" @submit.prevent="guardar">
        <h2 class="titulo-ancho mb-4 text-[16px] font-bold text-grafito">Identificación</h2>
        <div class="grid gap-5 sm:grid-cols-2">
            <Campo etiqueta="RUT" id="rut" :error="form.errors.rut" obligatorio>
                <input id="rut" v-model="form.rut" class="campo num h-12" placeholder="76.123.456-0" @blur="form.rut = formatearRut(form.rut)" />
            </Campo>
            <Campo etiqueta="Razón social" id="razon_social" :error="form.errors.razon_social" obligatorio>
                <input id="razon_social" v-model="form.razon_social" class="campo h-12" />
            </Campo>
            <Campo etiqueta="Nombre de fantasía" id="nombre_fantasia" :error="form.errors.nombre_fantasia">
                <input id="nombre_fantasia" v-model="form.nombre_fantasia" class="campo h-12" />
            </Campo>
            <Campo etiqueta="Giro" id="giro" :error="form.errors.giro">
                <input id="giro" v-model="form.giro" class="campo h-12" />
            </Campo>
            <Campo etiqueta="Código de actividad (ACTECO)" id="acteco" :error="form.errors.acteco">
                <input id="acteco" v-model="form.acteco" class="campo num h-12" inputmode="numeric" />
            </Campo>
        </div>

        <h2 class="titulo-ancho mb-4 mt-8 text-[16px] font-bold text-grafito">Contacto y dirección</h2>
        <div class="grid gap-5 sm:grid-cols-2">
            <Campo etiqueta="Dirección" id="direccion" :error="form.errors.direccion" class="sm:col-span-2">
                <input id="direccion" v-model="form.direccion" class="campo h-12" />
            </Campo>
            <Campo etiqueta="Comuna" id="comuna" :error="form.errors.comuna"><input id="comuna" v-model="form.comuna" class="campo h-12" /></Campo>
            <Campo etiqueta="Ciudad" id="ciudad" :error="form.errors.ciudad"><input id="ciudad" v-model="form.ciudad" class="campo h-12" /></Campo>
            <Campo etiqueta="Teléfono" id="telefono" :error="form.errors.telefono"><input id="telefono" v-model="form.telefono" class="campo h-12" type="tel" /></Campo>
            <Campo etiqueta="Correo" id="email" :error="form.errors.email"><input id="email" v-model="form.email" class="campo h-12" type="email" /></Campo>
        </div>

        <h2 class="titulo-ancho mb-4 mt-8 text-[16px] font-bold text-grafito">Resolución SII</h2>
        <div class="grid gap-5 sm:grid-cols-2">
            <Campo etiqueta="Número de resolución" id="res_num" :error="form.errors.resolucion_sii_numero">
                <input id="res_num" v-model.number="form.resolucion_sii_numero" class="campo num h-12" type="number" min="0" />
            </Campo>
            <Campo etiqueta="Fecha de resolución" id="res_fecha" :error="form.errors.resolucion_sii_fecha">
                <input id="res_fecha" v-model="form.resolucion_sii_fecha" class="campo h-12" type="date" />
            </Campo>
        </div>

        <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-linea pt-5">
            <Interruptor v-model="form.activo" etiqueta="Empresa activa" />
            <button type="submit" class="btn btn-primario" :disabled="form.processing">{{ empresa ? 'Guardar cambios' : 'Crear empresa' }}</button>
        </div>
    </form>
</template>
