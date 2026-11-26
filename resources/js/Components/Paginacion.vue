<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import { numero } from '@/lib/formato';

defineProps<{ datos: Paginado<unknown>; nombre?: string }>();

function etiqueta(l: string): string {
    if (l.includes('Previous') || l.includes('&laquo;')) return '‹';
    if (l.includes('Next') || l.includes('&raquo;')) return '›';
    return l;
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-[14px] text-grafito">
        <span class="num">
            <template v-if="datos.total">Mostrando {{ numero(datos.from) }}–{{ numero(datos.to) }} de {{ numero(datos.total) }} {{ nombre ?? 'registros' }}</template>
            <template v-else>Sin resultados</template>
        </span>
        <nav v-if="datos.last_page > 1" class="flex flex-wrap gap-1" aria-label="Paginación">
            <template v-for="(l, i) in datos.links" :key="i">
                <Link
                    v-if="l.url"
                    :href="l.url"
                    preserve-scroll
                    class="num min-w-9 rounded-boton border px-3 py-1.5 text-center font-semibold no-underline"
                    :class="l.active ? 'border-carbon bg-carbon text-hormigon' : 'border-linea bg-white text-carbon hover:bg-hormigon'"
                >{{ etiqueta(l.label) }}</Link>
                <span v-else class="min-w-9 px-3 py-1.5 text-center text-linea-fuerte">{{ etiqueta(l.label) }}</span>
            </template>
        </nav>
    </div>
</template>
