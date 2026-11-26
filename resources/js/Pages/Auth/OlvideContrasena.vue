<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Campo from '@/Components/Campo.vue';

defineProps<{ status?: string | null }>();
const form = useForm({ email: '' });
</script>

<template>
    <Head title="Recuperar contraseña" />
    <GuestLayout titulo="Recuperar contraseña" bajada="Te enviaremos un enlace para crear una contraseña nueva.">
        <p v-if="status" class="mb-5 rounded-boton bg-estado-ok-bg px-4 py-3 font-semibold text-estado-ok" role="status">{{ status }}</p>
        <form class="space-y-5" @submit.prevent="form.post('/forgot-password')">
            <Campo etiqueta="Correo" id="email" :error="form.errors.email">
                <input id="email" v-model="form.email" type="email" class="campo h-12" autocomplete="username" required autofocus />
            </Campo>
            <button type="submit" class="btn btn-primario w-full" :disabled="form.processing">Enviar enlace</button>
            <Link href="/login" class="block text-[15px] font-semibold">← Volver a ingresar</Link>
        </form>
    </GuestLayout>
</template>
