<script setup lang="ts">
defineProps<{ titulo: string; texto?: string }>();
</script>

<template>
    <div class="px-6 py-14 text-center">
        <p class="text-[18px] font-bold">{{ titulo }}</p>
        <p v-if="texto" class="mx-auto mt-1 max-w-md text-grafito">{{ texto }}</p>
        <div v-if="$slots.default" class="mt-5"><slot /></div>
    </div>
</template>
