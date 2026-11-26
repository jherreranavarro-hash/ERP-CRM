<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Campo from '@/Components/Campo.vue';

const usarRecuperacion = ref(false);
const form = useForm({ code: '', recovery_code: '' });

function enviar() {
    form.transform((d) => (usarRecuperacion.value ? { recovery_code: d.recovery_code } : { code: d.code })).post('/two-factor-challenge', {
        onFinish: () => form.reset(),
    });
}
</script>

<template>
    <Head title="Verificación en dos pasos" />
    <GuestLayout
        titulo="Verificación"
        :bajada="usarRecuperacion ? 'Ingresa uno de tus códigos de recuperación.' : 'Ingresa el código de 6 dígitos de tu aplicación de autenticación.'"
    >
        <form class="space-y-5" @submit.prevent="enviar">
            <Campo v-if="!usarRecuperacion" etiqueta="Código" id="code" :error="form.errors.code">
                <input id="code" v-model="form.code" class="campo num h-14 text-center text-[26px] tracking-[0.4em]" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus />
            </Campo>
            <Campo v-else etiqueta="Código de recuperación" id="recovery_code" :error="form.errors.recovery_code">
                <input id="recovery_code" v-model="form.recovery_code" class="campo h-12" autocomplete="off" autofocus />
            </Campo>
            <button type="submit" class="btn btn-primario w-full" :disabled="form.processing">Verificar</button>
            <button type="button" class="btn-texto block text-[15px] font-semibold" @click="usarRecuperacion = !usarRecuperacion">
                {{ usarRecuperacion ? 'Usar el código de la aplicación' : 'No tengo el teléfono: usar un código de recuperación' }}
            </button>
        </form>
    </GuestLayout>
</template>
