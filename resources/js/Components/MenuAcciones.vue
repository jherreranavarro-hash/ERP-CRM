<script setup lang="ts">
// "Una acción, un botón": la acción del momento va como botón; el resto queda aquí.
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Icono from './Icono.vue';

interface Accion {
    etiqueta: string;
    peligro?: boolean;
    accion: () => void;
}

defineProps<{ acciones: Accion[]; etiqueta?: string }>();
const abierto = ref(false);
const raiz = ref<HTMLElement | null>(null);

function fuera(e: MouseEvent) {
    if (raiz.value && !raiz.value.contains(e.target as Node)) abierto.value = false;
}
onMounted(() => document.addEventListener('click', fuera));
onBeforeUnmount(() => document.removeEventListener('click', fuera));
</script>

<template>
    <div ref="raiz" class="relative inline-block text-left">
        <button
            type="button"
            class="btn btn-secundario btn-sm w-10 px-0"
            :aria-label="etiqueta ?? 'Más acciones'"
            :aria-expanded="abierto"
            @click="abierto = !abierto"
        >
            <Icono nombre="puntos" />
        </button>
        <div v-if="abierto" class="tarjeta absolute right-0 z-30 mt-1 min-w-[200px] py-1 shadow-lg" role="menu">
            <button
                v-for="a in acciones"
                :key="a.etiqueta"
                type="button"
                role="menuitem"
                class="block w-full px-4 py-2 text-left text-[15px] hover:bg-hormigon"
                :class="a.peligro ? 'text-estado-error' : 'text-carbon'"
                @click="abierto = false; a.accion()"
            >
                {{ a.etiqueta }}
            </button>
        </div>
    </div>
</template>
