<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch, nextTick } from 'vue';
import Icono from './Icono.vue';

const props = withDefaults(defineProps<{ abierto: boolean; titulo: string; ancho?: 'md' | 'lg' | 'xl' }>(), { ancho: 'md' });
const emit = defineEmits<{ cerrar: [] }>();
const panel = ref<HTMLElement | null>(null);

function tecla(e: KeyboardEvent) {
    if (e.key === 'Escape' && props.abierto) emit('cerrar');
}
onMounted(() => document.addEventListener('keydown', tecla));
onBeforeUnmount(() => document.removeEventListener('keydown', tecla));
watch(
    () => props.abierto,
    async (v) => {
        document.body.style.overflow = v ? 'hidden' : '';
        if (v) {
            await nextTick();
            panel.value?.querySelector<HTMLElement>('input, select, textarea, button')?.focus();
        }
    },
);
</script>

<template>
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-carbon/50 p-4 sm:p-10" @mousedown.self="emit('cerrar')">
            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="titulo"
                class="tarjeta w-full shadow-xl"
                :class="{ 'max-w-lg': ancho === 'md', 'max-w-2xl': ancho === 'lg', 'max-w-4xl': ancho === 'xl' }"
            >
                <div class="flex items-center justify-between border-b border-linea px-6 py-4">
                    <h2 class="text-[20px] font-bold">{{ titulo }}</h2>
                    <button type="button" class="rounded p-1 text-grafito hover:bg-hormigon" aria-label="Cerrar" @click="emit('cerrar')"><Icono nombre="cerrar" /></button>
                </div>
                <div class="px-6 py-5"><slot /></div>
                <div v-if="$slots.pie" class="flex flex-wrap justify-end gap-3 border-t border-linea px-6 py-4"><slot name="pie" /></div>
            </div>
        </div>
    </Teleport>
</template>
