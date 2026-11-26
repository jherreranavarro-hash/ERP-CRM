<script setup lang="ts">
defineProps<{ etiqueta: string; error?: string; ayuda?: string; id?: string; obligatorio?: boolean }>();
</script>

<template>
    <div>
        <label :for="id" class="etiqueta">{{ etiqueta }}<span v-if="obligatorio" class="text-naranja-profundo"> *</span></label>
        <slot />
        <p v-if="error" class="error" role="alert">{{ error }}</p>
        <p v-else-if="ayuda" class="ayuda">{{ ayuda }}</p>
    </div>
</template>
