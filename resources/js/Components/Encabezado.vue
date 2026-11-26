<script setup lang="ts">
defineProps<{ titulo: string; bajada?: string; volver?: string }>();
</script>

<template>
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <a v-if="volver" :href="volver" class="mb-1 inline-block text-[14px] font-semibold">← Volver</a>
            <h1 class="titulo-ancho text-[26px] font-bold leading-tight sm:text-[28px]">{{ titulo }}</h1>
            <p v-if="bajada" class="mt-1 max-w-3xl text-[16px] text-grafito">{{ bajada }}</p>
        </div>
        <div v-if="$slots.default" class="flex flex-wrap items-center gap-3"><slot /></div>
    </header>
</template>
