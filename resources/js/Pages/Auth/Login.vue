<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Campo from '@/Components/Campo.vue';

defineProps<{ status?: string | null; captcha: { tipo: string; pregunta: string } | null }>();

const form = useForm({ email: '', password: '', captcha: '', remember: false });

function enviar() {
    form.post('/login', { onFinish: () => form.reset('password', 'captcha') });
}
</script>

<template>
    <Head title="Ingresar" />
    <GuestLayout titulo="Ingresar" bajada="Usa tu correo de la empresa. Las cuentas se crean solo por invitación.">
        <p v-if="status" class="mb-5 rounded-boton bg-estado-info-bg px-4 py-3 font-semibold text-estado-info" role="status">{{ status }}</p>

        <form class="space-y-5" novalidate @submit.prevent="enviar">
            <Campo etiqueta="Correo" id="email" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" class="campo h-12" autocomplete="username" required autofocus />
            </Campo>
            <Campo etiqueta="Contraseña" id="password" :error="form.errors.password">
                <input id="password" v-model="form.password" type="password" class="campo h-12" autocomplete="current-password" required />
            </Campo>
            <Campo v-if="captcha" :etiqueta="captcha.pregunta" id="captcha" :error="form.errors.captcha" ayuda="Verificación después de varios intentos fallidos.">
                <input id="captcha" v-model="form.captcha" type="text" inputmode="numeric" class="campo h-12" autocomplete="off" required />
            </Campo>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-[15px]">
                    <input v-model="form.remember" type="checkbox" class="rounded border-linea-fuerte text-carbon focus:ring-naranja" />
                    Recordarme en este equipo
                </label>
                <Link href="/forgot-password" class="text-[15px] font-semibold">¿Olvidaste tu contraseña?</Link>
            </div>

            <button type="submit" class="btn btn-primario w-full" :disabled="form.processing">Ingresar</button>
        </form>
    </GuestLayout>
</template>
