<script setup lang="ts">
defineProps<{ titulo: string; bajada?: string }>();
</script>

<template>
    <div class="grid min-h-screen lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <section class="flex flex-col justify-between bg-carbon px-8 py-10 text-hormigon sm:px-14 lg:py-14">
            <div class="flex items-center gap-7">
                <img src="/img/obmax-logo.png" alt="OBMAX Chile" class="h-[64px] w-auto sm:h-[84px]" />
            </div>
            <div class="mt-10 lg:mt-0">
                <div class="border-l border-carbon-3 pl-6">
                    <p class="titulo-ancho text-[24px] font-extrabold leading-tight sm:text-[30px]">Sistema de gestión</p>
                    <p class="mt-1.5 text-[18px] text-linea-fuerte">Control y facturación · Obramax SpA</p>
                </div>
                <p class="mt-8 hidden max-w-[420px] text-[17px] leading-relaxed text-linea-fuerte lg:block">
                    Hecho para el mesón y la bodega: claro de día, números grandes, un botón por cada tarea diaria.
                </p>
            </div>
            <p class="mt-10 hidden text-[13px] text-grafito lg:block">Acceso restringido a personal autorizado. Toda actividad queda registrada.</p>
        </section>

        <main class="flex items-center justify-center px-5 py-10 sm:px-10">
            <div class="w-full max-w-[440px]">
                <h1 class="titulo-ancho text-[26px] font-bold leading-tight">{{ titulo }}</h1>
                <p v-if="bajada" class="mt-2 text-[16px] text-grafito">{{ bajada }}</p>
                <div class="mt-7"><slot /></div>
            </div>
        </main>
    </div>
</template>
