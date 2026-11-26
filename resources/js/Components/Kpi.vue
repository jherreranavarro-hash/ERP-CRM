<script setup lang="ts">
withDefaults(defineProps<{ etiqueta: string; valor: string | number; tono?: 'normal' | 'ok' | 'error' | 'naranja' }>(), { tono: 'normal' });
</script>

<template>
    <div class="rounded-boton bg-hormigon px-4 py-[14px]">
        <div class="text-[14px] text-grafito">{{ etiqueta }}</div>
        <div
            class="num text-[26px] font-bold leading-tight"
            :class="{ 'text-estado-ok': tono === 'ok', 'text-estado-error': tono === 'error', 'text-naranja-profundo': tono === 'naranja' }"
        >
            {{ valor }}
        </div>
        <div v-if="$slots.default" class="mt-1 text-[13px] text-grafito"><slot /></div>
    </div>
</template>
