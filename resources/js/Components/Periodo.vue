<script setup lang="ts">
import { router } from '@inertiajs/vue3';

/** Selector de período (desde/hasta) con atajos; recarga la página con los nuevos filtros. */
const props = defineProps<{ desde: string; hasta: string; url: string }>();
const iso = (d: Date) => d.toLocaleDateString('sv-SE');
const hoy = new Date();
const atajos = [
    { nombre: 'Este mes', desde: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)), hasta: iso(hoy) },
    { nombre: 'Mes anterior', desde: iso(new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1)), hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 0)) },
    { nombre: 'Últimos 30 días', desde: iso(new Date(hoy.getTime() - 29 * 864e5)), hasta: iso(hoy) },
    { nombre: 'Este año', desde: iso(new Date(hoy.getFullYear(), 0, 1)), hasta: iso(hoy) },
];
const ir = (desde: string, hasta: string) => router.get(props.url, { desde, hasta }, { preserveState: true, preserveScroll: true, replace: true });
</script>

<template>
    <div class="tarjeta mb-6 flex flex-wrap items-center gap-3 p-4">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Atajos de período">
            <button v-for="a in atajos" :key="a.nombre" class="btn btn-sm" :class="a.desde === desde && a.hasta === hasta ? 'btn-oscuro' : 'btn-secundario'" :aria-pressed="a.desde === desde && a.hasta === hasta" @click="ir(a.desde, a.hasta)">{{ a.nombre }}</button>
        </div>
        <div class="flex items-center gap-2 sm:ml-auto">
            <input :value="desde" type="date" class="campo h-10 w-40" aria-label="Desde" @change="ir(($event.target as HTMLInputElement).value, hasta)" />
            <span class="text-grafito">a</span>
            <input :value="hasta" type="date" class="campo h-10 w-40" aria-label="Hasta" @change="ir(desde, ($event.target as HTMLInputElement).value)" />
        </div>
    </div>
</template>
