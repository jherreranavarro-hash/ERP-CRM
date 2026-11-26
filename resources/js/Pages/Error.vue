<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

const props = defineProps<{ status: number }>();
const textos: Record<number, [string, string]> = {
    403: ['Sin permiso', 'Tu rol no tiene acceso a esta acción. Si lo necesitas, pídelo a un administrador.'],
    404: ['No encontrado', 'La página o el registro no existe, o pertenece a otra empresa.'],
    429: ['Demasiadas solicitudes', 'Espera un momento antes de intentarlo de nuevo.'],
    500: ['Algo falló', 'Registramos el error. Intenta de nuevo en unos minutos.'],
    503: ['En mantención', 'Volvemos en unos minutos.'],
};
const [titulo, texto] = textos[props.status] ?? textos[500];
</script>

<template>
    <Head :title="titulo" />
    <div class="flex min-h-screen items-center justify-center bg-hormigon px-6">
        <div class="max-w-lg text-center">
            <img src="/img/obmax-logo.png" alt="OBMAX" class="mx-auto mb-8 h-14 rounded-md bg-carbon px-4 py-2" />
            <p class="num text-[64px] font-bold leading-none text-naranja-profundo">{{ status }}</p>
            <h1 class="titulo-ancho mt-3 text-[26px] font-bold">{{ titulo }}</h1>
            <p class="mt-2 text-grafito">{{ texto }}</p>
            <a href="/" class="btn btn-oscuro mt-8 no-underline">Ir al inicio</a>
        </div>
    </div>
</template>
