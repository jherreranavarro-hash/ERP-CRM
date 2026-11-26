<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Campo from '@/Components/Campo.vue';

const props = defineProps<{ email: string; token: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
</script>

<template>
    <Head title="Nueva contraseña" />
    <GuestLayout titulo="Nueva contraseña" bajada="Mínimo 12 caracteres, con letras y números. Evita palabras obvias como el nombre de la empresa.">
        <form class="space-y-5" @submit.prevent="form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })">
            <Campo etiqueta="Correo" id="email" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" class="campo h-12" autocomplete="username" required />
            </Campo>
            <Campo etiqueta="Contraseña nueva" id="password" :error="form.errors.password">
                <input id="password" v-model="form.password" type="password" class="campo h-12" autocomplete="new-password" required />
            </Campo>
            <Campo etiqueta="Repite la contraseña" id="password_confirmation">
                <input id="password_confirmation" v-model="form.password_confirmation" type="password" class="campo h-12" autocomplete="new-password" required />
            </Campo>
            <button type="submit" class="btn btn-primario w-full" :disabled="form.processing">Guardar contraseña</button>
        </form>
    </GuestLayout>
</template>
