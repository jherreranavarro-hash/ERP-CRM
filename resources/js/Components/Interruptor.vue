<script setup lang="ts">
const modelo = defineModel<boolean>({ required: true });
defineProps<{ etiqueta: string; deshabilitado?: boolean }>();
</script>

<template>
    <label class="inline-flex cursor-pointer select-none items-center gap-3" :class="deshabilitado && 'cursor-not-allowed opacity-60'">
        <button
            type="button"
            role="switch"
            :aria-checked="modelo"
            :disabled="deshabilitado"
            class="relative h-7 w-12 rounded-full transition-colors"
            :class="modelo ? 'bg-carbon' : 'bg-linea-fuerte'"
            @click="modelo = !modelo"
        >
            <span class="absolute top-1 h-5 w-5 rounded-full bg-white shadow transition-all" :class="modelo ? 'left-6' : 'left-1'" />
        </button>
        <span class="text-[15px]">{{ etiqueta }}</span>
    </label>
</template>
