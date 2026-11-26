<script setup lang="ts">
// Estados con palabras: cada estado dice lo que pasa, no solo un color.
type Tono = 'neutro' | 'alerta' | 'info' | 'ok' | 'error' | 'naranja';
withDefaults(defineProps<{ tono?: Tono; chico?: boolean }>(), { tono: 'neutro', chico: false });

const clases: Record<Tono, string> = {
    neutro: 'bg-estado-neutro-bg text-estado-neutro',
    alerta: 'bg-estado-alerta-bg text-estado-alerta',
    info: 'bg-estado-info-bg text-estado-info',
    ok: 'bg-estado-ok-bg text-estado-ok',
    error: 'bg-estado-error-bg text-estado-error',
    naranja: 'bg-naranja/15 text-naranja-profundo',
};
</script>

<template>
    <span class="chip" :class="[clases[tono], chico && 'chip-sm']"><slot /></span>
</template>
