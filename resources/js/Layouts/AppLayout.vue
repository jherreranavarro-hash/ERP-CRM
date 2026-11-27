<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';
import Icono from '@/Components/Icono.vue';
import Avatar from '@/Components/Avatar.vue';

const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user!);
const menu = computed(() => page.props.menu);
const empresa = computed(() => page.props.empresa);
const flash = computed(() => page.props.flash);
// Aviso flotante de WhatsApp: visible en todas las pantallas para quien atiende la bandeja.
const whatsapp = computed(() => page.props.whatsapp);
const enBandeja = computed(() => page.url.startsWith('/ventas/whatsapp'));

const menuMovil = ref(false);
const menuUsuario = ref(false);
const rutaActual = computed(() => page.url.split('?')[0]);

// Un módulo abierto a la vez; se abre solo el que contiene la página actual.
const abierto = ref<string | null>(null);
function moduloDeRuta(): string | null {
    for (const m of menu.value) if (m.items.some((i) => esActivo(i.href))) return m.clave;
    return null;
}
watch(rutaActual, () => { abierto.value = moduloDeRuta() ?? abierto.value; menuMovil.value = false; }, { immediate: true });

function esActivo(href: string): boolean {
    return rutaActual.value === href || (href !== '/' && rutaActual.value.startsWith(href + '/'));
}

function cambiarEmpresa(e: Event) {
    router.post('/empresa-activa', { empresa_id: Number((e.target as HTMLSelectElement).value) });
}

const avisoVisible = ref(true);
const exitoVisible = ref(true);
watch(() => page.props.flash, () => { avisoVisible.value = true; exitoVisible.value = true; });
let timer: ReturnType<typeof setTimeout> | undefined;
watch(() => flash.value.exito, (v) => { clearTimeout(timer); if (v) timer = setTimeout(() => (exitoVisible.value = false), 6000); }, { immediate: true });
</script>

<template>
    <div class="min-h-screen lg:flex">
        <!-- Menú lateral carbón -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-[280px] flex-col bg-carbon text-hormigon transition-transform lg:sticky lg:top-0 lg:h-screen lg:translate-x-0"
            :class="menuMovil ? 'translate-x-0' : '-translate-x-full'"
            aria-label="Menú principal"
        >
            <div class="flex items-center justify-between px-6 pb-5 pt-6">
                <Link href="/" class="block" aria-label="Inicio">
                    <img src="/img/obmax-logo.png" alt="OBMAX Chile" class="h-[46px] w-auto" />
                </Link>
                <button class="rounded p-1 text-linea-fuerte hover:text-white lg:hidden" aria-label="Cerrar menú" @click="menuMovil = false"><Icono nombre="cerrar" /></button>
            </div>
            <div class="mx-6 border-t border-carbon-3" />

            <nav class="flex-1 overflow-y-auto px-3 py-4">
                <Link href="/" class="mb-1 flex items-center gap-3 rounded-boton px-3 py-2.5 text-[16px] font-semibold no-underline" :class="rutaActual === '/' ? 'bg-carbon-2 text-white' : 'text-linea-fuerte hover:bg-carbon-2 hover:text-white'">
                    <Icono nombre="panel" /> Inicio
                </Link>
                <div v-for="m in menu" :key="m.clave" class="mb-1">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-boton px-3 py-2.5 text-left text-[16px] font-semibold"
                        :class="abierto === m.clave ? 'text-white' : 'text-linea-fuerte hover:bg-carbon-2 hover:text-white'"
                        :aria-expanded="abierto === m.clave"
                        @click="abierto = abierto === m.clave ? null : m.clave"
                    >
                        <Icono :nombre="m.icono" />
                        <span class="flex-1">{{ m.nombre }}</span>
                        <Icono nombre="chevron" :tamano="16" class="transition-transform" :class="abierto === m.clave && 'rotate-180'" />
                    </button>
                    <ul v-if="abierto === m.clave" class="mb-2 ml-[22px] mt-0.5 border-l border-carbon-3 pl-3">
                        <li v-for="i in m.items" :key="i.clave">
                            <Link
                                :href="i.href"
                                class="flex items-center justify-between gap-2 rounded-md px-3 py-[7px] text-[15px] no-underline"
                                :class="esActivo(i.href) ? 'bg-naranja font-semibold text-carbon' : i.disponible ? 'text-linea hover:bg-carbon-2 hover:text-white' : 'text-grafito hover:bg-carbon-2 hover:text-linea-fuerte'"
                            >
                                <span>{{ i.nombre }}</span>
                                <span v-if="!i.disponible" class="rounded bg-carbon-2 px-1.5 text-[11px] font-semibold text-grafito" :title="`Se entrega en la fase ${i.fase}`">F{{ i.fase }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="border-t border-carbon-3 px-6 py-4 text-[13px] text-grafito">Obramax SpA · Sistema de Gestión</div>
        </aside>
        <div v-if="menuMovil" class="fixed inset-0 z-30 bg-carbon/50 lg:hidden" @click="menuMovil = false" />

        <!-- Contenido -->
        <div class="flex min-w-0 flex-1 flex-col">
            <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-linea bg-white/95 px-4 backdrop-blur sm:px-8">
                <button class="rounded p-2 text-carbon hover:bg-hormigon lg:hidden" aria-label="Abrir menú" @click="menuMovil = true"><Icono nombre="menu" /></button>

                <div v-if="empresa" class="flex min-w-0 items-center gap-2">
                    <Icono nombre="empresa" class="hidden shrink-0 text-grafito sm:block" />
                    <select
                        v-if="empresa.disponibles.length > 1"
                        :value="empresa.actual.id"
                        class="campo h-10 max-w-[260px] py-0 font-semibold"
                        aria-label="Empresa activa"
                        @change="cambiarEmpresa"
                    >
                        <option v-for="e in empresa.disponibles" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                    </select>
                    <span v-else class="truncate font-semibold">{{ empresa.actual.nombre }}</span>
                </div>

                <div class="ml-auto flex items-center gap-3">
                    <span v-if="!user.tiene_2fa" class="hidden md:inline">
                        <Link href="/perfil/seguridad" class="chip chip-sm bg-estado-alerta-bg text-estado-alerta no-underline">Activa la verificación en dos pasos</Link>
                    </span>
                    <div class="relative">
                        <button type="button" class="flex items-center gap-2 rounded-boton px-2 py-1 hover:bg-hormigon" :aria-expanded="menuUsuario" @click="menuUsuario = !menuUsuario">
                            <Avatar :iniciales="user.iniciales" oscuro />
                            <span class="hidden text-left leading-tight sm:block">
                                <span class="block text-[15px] font-semibold">{{ user.nombre }}</span>
                                <span class="block text-[13px] text-grafito">{{ user.email }}</span>
                            </span>
                            <Icono nombre="chevron" :tamano="16" class="text-grafito" />
                        </button>
                        <div v-if="menuUsuario" class="tarjeta absolute right-0 mt-1 w-60 py-1 shadow-lg" @click="menuUsuario = false">
                            <Link href="/perfil/seguridad" class="flex items-center gap-2 px-4 py-2 text-[15px] text-carbon no-underline hover:bg-hormigon"><Icono nombre="escudo" :tamano="18" /> Seguridad de mi cuenta</Link>
                            <Link href="/logout" method="post" as="button" class="flex w-full items-center gap-2 px-4 py-2 text-left text-[15px] text-carbon hover:bg-hormigon"><Icono nombre="salir" :tamano="18" /> Cerrar sesión</Link>
                        </div>
                    </div>
                </div>
            </header>

            <main class="mx-auto w-full max-w-[1400px] flex-1 px-4 py-6 sm:px-8 sm:py-8">
                <div v-if="flash.aviso && avisoVisible" class="mb-5 flex items-start gap-3 rounded-tarjeta border border-[#F3D79A] bg-estado-alerta-bg px-4 py-3 text-estado-alerta" role="status">
                    <Icono nombre="alerta" class="mt-0.5 shrink-0" />
                    <p class="flex-1 font-semibold">{{ flash.aviso }}</p>
                    <button aria-label="Cerrar aviso" @click="avisoVisible = false"><Icono nombre="cerrar" :tamano="18" /></button>
                </div>
                <div v-if="flash.error" class="mb-5 flex items-start gap-3 rounded-tarjeta border border-estado-error-borde bg-estado-error-bg px-4 py-3 text-estado-error" role="alert">
                    <Icono nombre="alerta" class="mt-0.5 shrink-0" />
                    <p class="flex-1 font-semibold">{{ flash.error }}</p>
                </div>
                <slot />
            </main>
        </div>

        <Link v-if="whatsapp !== null && whatsapp !== undefined && !enBandeja" href="/ventas/whatsapp?filtro=no_leidas" class="fixed bottom-5 right-5 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-carbon text-naranja no-underline shadow-xl hover:bg-black" :aria-label="`WhatsApp: ${whatsapp} conversaciones sin leer`" title="WhatsApp">
            <Icono nombre="mensaje" />
            <span v-if="whatsapp" class="num absolute -right-1 -top-1 min-w-5 rounded-full bg-naranja px-1.5 text-center text-[12px] font-bold leading-5 text-white">{{ whatsapp }}</span>
        </Link>

        <Transition enter-from-class="opacity-0 translate-y-2" leave-to-class="opacity-0" enter-active-class="transition" leave-active-class="transition">
            <div v-if="flash.exito && exitoVisible" class="fixed right-5 z-50 flex max-w-sm items-start gap-3 rounded-tarjeta bg-carbon px-4 py-3 text-hormigon shadow-xl" :class="whatsapp !== null && whatsapp !== undefined && !enBandeja ? 'bottom-20' : 'bottom-5'" role="status">
                <Icono nombre="check" class="mt-0.5 shrink-0 text-naranja" />
                <p class="flex-1">{{ flash.exito }}</p>
                <button class="text-linea-fuerte hover:text-white" aria-label="Cerrar" @click="exitoVisible = false"><Icono nombre="cerrar" :tamano="18" /></button>
            </div>
        </Transition>
    </div>
</template>
