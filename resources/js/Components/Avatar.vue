<script setup lang="ts">
// Avatar generado localmente con iniciales: no se envía el nombre a servicios externos
// (reemplaza ui-avatars.com del sistema anterior).
defineProps<{ iniciales: string; oscuro?: boolean }>();
</script>

<template>
    <span
        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-[14px] font-bold"
        :class="oscuro ? 'bg-naranja text-carbon' : 'bg-estado-neutro-bg text-carbon-3'"
        aria-hidden="true"
    >{{ iniciales || '·' }}</span>
</template>
