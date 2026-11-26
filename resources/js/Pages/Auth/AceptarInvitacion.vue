<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Campo from '@/Components/Campo.vue';

const props = defineProps<{ token: string; vigente: boolean; email: string | null; nombre: string | null; empresa: string | null }>();
const form = useForm({ nombre: props.nombre ?? '', password: '', password_confirmation: '' });
</script>

<template>
    <Head title="Activar cuenta" />
    <GuestLayout v-if="vigente" titulo="Activa tu cuenta" :bajada="`Te invitaron a ${empresa}. Crea tu contraseña para ${email}.`">
        <form class="space-y-5" @submit.prevent="form.post(`/invitacion/${token}`, { onFinish: () => form.reset('password', 'password_confirmation') })">
            <Campo etiqueta="Tu nombre" id="nombre" :error="form.errors.nombre">
                <input id="nombre" v-model="form.nombre" class="campo h-12" autocomplete="name" required />
            </Campo>
            <Campo etiqueta="Contraseña" id="password" :error="form.errors.password" ayuda="Mínimo 12 caracteres, con letras y números.">
                <input id="password" v-model="form.password" type="password" class="campo h-12" autocomplete="new-password" required />
            </Campo>
            <Campo etiqueta="Repite la contraseña" id="password_confirmation">
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" class="campo h-12" autocomplete="new-password" required />
            </Campo>
            <button type="submit" class="btn btn-primario w-full" :disabled="form.processing">Activar cuenta</button>
        </form>
    </GuestLayout>
    <GuestLayout v-else titulo="Invitación no válida" bajada="El enlace venció, ya se usó o fue revocado. Pide a tu administrador una invitación nueva.">
        <Link href="/login" class="btn btn-oscuro w-full no-underline">Ir a ingresar</Link>
    </GuestLayout>
</template>
