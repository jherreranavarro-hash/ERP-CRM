<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Chip from '@/Components/Chip.vue';

defineProps<{ modulo: string; submodulo: string; fase: number }>();

const fases: Record<number, string> = {
    2: 'Facturación electrónica: documentos, folios, cotizaciones, caja, clientes y productos.',
    3: 'Módulos ERP: finanzas, RRHH, compras, inventario y ventas.',
    4: 'Contact center e integraciones: telefonía, WhatsApp, Meta, LinkedIn y mapas.',
};
</script>

<template>
    <Head :title="submodulo" />
    <div class="tarjeta mx-auto max-w-2xl px-8 py-12 text-center">
        <p class="text-[15px] text-grafito">{{ modulo }}</p>
        <h1 class="titulo-ancho mt-1 text-[28px] font-bold">{{ submodulo }}</h1>
        <div class="mt-4"><Chip tono="info">Se entrega en la fase {{ fase }}</Chip></div>
        <p class="mx-auto mt-5 max-w-md text-grafito">{{ fases[fase] }}</p>
        <Link href="/" class="btn btn-oscuro mt-8 no-underline">Volver al inicio</Link>
    </div>
</template>
