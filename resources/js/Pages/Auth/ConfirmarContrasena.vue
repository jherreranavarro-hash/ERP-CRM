<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Campo from '@/Components/Campo.vue';

const form = useForm({ password: '' });
</script>

<template>
    <Head title="Confirmar contraseña" />
    <GuestLayout titulo="Confirma tu contraseña" bajada="Es una acción sensible: confirma que eres tú antes de continuar.">
        <form class="space-y-5" @submit.prevent="form.post('/user/confirm-password', { onFinish: () => form.reset() })">
            <Campo etiqueta="Contraseña" id="password" :error="form.errors.password">
                <input id="password" v-model="form.password" type="password" class="campo h-12" autocomplete="current-password" required autofocus />
            </Campo>
            <button type="submit" class="btn btn-primario w-full" :disabled="form.processing">Confirmar</button>
        </form>
    </GuestLayout>
</template>
