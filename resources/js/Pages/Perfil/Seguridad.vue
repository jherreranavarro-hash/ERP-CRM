<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Chip from '@/Components/Chip.vue';
import { fecha } from '@/lib/formato';

const props = defineProps<{
    dosFactores: { activo: boolean; pendiente: boolean; obligatorio: boolean; qr: string | null; clave: string | null; codigos: string[] | null };
    politica: { largo_minimo: number };
    password_cambiada: string | null;
}>();

const confirmar2fa = useForm({ code: '' });
const password = useForm({ current_password: '', password: '', password_confirmation: '' });

function activar() {
    router.post('/user/two-factor-authentication', {}, { preserveScroll: true });
}
function desactivar() {
    if (confirm('¿Desactivar la verificación en dos pasos? Tu cuenta quedará menos protegida.')) router.delete('/user/two-factor-authentication', { preserveScroll: true });
}
function verCodigos() {
    router.post('/perfil/codigos', {}, { preserveScroll: true });
}
function regenerarCodigos() {
    router.post('/user/two-factor-recovery-codes', {}, { preserveScroll: true, onSuccess: () => verCodigos() });
}
function cambiarPassword() {
    password.put('/user/password', { errorBag: 'updatePassword', preserveScroll: true, onSuccess: () => password.reset() });
}
</script>

<template>
    <Head title="Seguridad de mi cuenta" />
    <Encabezado titulo="Seguridad de mi cuenta" bajada="Verificación en dos pasos, contraseña y sesiones abiertas." />

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="tarjeta p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 class="titulo-ancho text-[18px] font-bold">Verificación en dos pasos</h2>
                <Chip :tono="dosFactores.activo ? 'ok' : dosFactores.obligatorio ? 'error' : 'alerta'">
                    {{ dosFactores.activo ? 'Activa' : dosFactores.obligatorio ? 'Obligatoria para tu perfil' : 'Desactivada' }}
                </Chip>
            </div>

            <template v-if="dosFactores.activo">
                <p class="text-grafito">Al ingresar te pediremos el código de tu aplicación de autenticación (Google Authenticator, Microsoft Authenticator u otra).</p>
                <div v-if="dosFactores.codigos" class="mt-5 rounded-boton bg-hormigon p-4">
                    <p class="mb-2 font-semibold">Códigos de recuperación</p>
                    <p class="mb-3 text-[14px] text-grafito">Guárdalos en un lugar seguro. Cada uno sirve una sola vez si pierdes el teléfono.</p>
                    <ul class="num grid grid-cols-2 gap-1 font-mono text-[15px]"><li v-for="c in dosFactores.codigos" :key="c">{{ c }}</li></ul>
                </div>
                <div class="mt-5 flex flex-wrap gap-3">
                    <button class="btn btn-secundario" @click="dosFactores.codigos ? regenerarCodigos() : verCodigos()">{{ dosFactores.codigos ? 'Generar códigos nuevos' : 'Ver códigos de recuperación' }}</button>
                    <button v-if="!dosFactores.obligatorio" class="btn btn-peligro" @click="desactivar">Desactivar</button>
                </div>
            </template>

            <template v-else-if="dosFactores.pendiente && dosFactores.qr">
                <ol class="list-decimal space-y-1 pl-5 text-[15px]">
                    <li>Abre tu aplicación de autenticación y escanea el código.</li>
                    <li>Escribe el código de 6 dígitos que aparece para confirmar.</li>
                </ol>
                <div class="mt-4 flex flex-wrap items-start gap-6">
                    <div class="rounded-boton border border-linea bg-white p-3" v-html="dosFactores.qr" />
                    <div class="text-[14px] text-grafito">
                        <p>¿No puedes escanear? Ingresa esta clave:</p>
                        <p class="mt-1 break-all font-mono text-[15px] font-semibold text-carbon">{{ dosFactores.clave }}</p>
                    </div>
                </div>
                <form class="mt-5 flex flex-wrap items-end gap-3" @submit.prevent="confirmar2fa.post('/user/confirmed-two-factor-authentication', { errorBag: 'confirmTwoFactorAuthentication', preserveScroll: true, onSuccess: () => verCodigos() })">
                    <Campo etiqueta="Código" id="code" :error="confirmar2fa.errors.code" class="w-44">
                        <input id="code" v-model="confirmar2fa.code" class="campo num h-12 text-center text-[20px] tracking-[0.3em]" inputmode="numeric" maxlength="6" autocomplete="one-time-code" />
                    </Campo>
                    <button type="submit" class="btn btn-primario" :disabled="confirmar2fa.processing">Confirmar</button>
                </form>
            </template>

            <template v-else>
                <p class="text-grafito">Agrega un segundo paso al ingresar: además de tu contraseña, un código que cambia cada 30 segundos en tu teléfono.</p>
                <button class="btn btn-primario mt-5" @click="activar">Activar verificación en dos pasos</button>
            </template>
        </section>

        <section class="tarjeta p-6">
            <h2 class="titulo-ancho mb-1 text-[18px] font-bold">Contraseña</h2>
            <p class="mb-5 text-[14px] text-grafito">Último cambio: {{ fecha(password_cambiada) }}. Mínimo {{ politica.largo_minimo }} caracteres, con letras y números.</p>
            <form class="space-y-4" @submit.prevent="cambiarPassword">
                <Campo etiqueta="Contraseña actual" id="current_password" :error="password.errors.current_password">
                    <input id="current_password" v-model="password.current_password" type="password" class="campo h-12" autocomplete="current-password" />
                </Campo>
                <Campo etiqueta="Contraseña nueva" id="new_password" :error="password.errors.password">
                    <input id="new_password" v-model="password.password" type="password" class="campo h-12" autocomplete="new-password" />
                </Campo>
                <Campo etiqueta="Repite la contraseña nueva" id="password_confirmation">
                    <input id="password_confirmation" v-model="password.password_confirmation" type="password" class="campo h-12" autocomplete="new-password" />
                </Campo>
                <button type="submit" class="btn btn-oscuro" :disabled="password.processing">Cambiar contraseña</button>
            </form>
        </section>

        <section class="tarjeta p-6 xl:col-span-2">
            <h2 class="titulo-ancho mb-1 text-[18px] font-bold">Sesiones</h2>
            <p class="mb-4 text-grafito">Si dejaste la sesión abierta en otro equipo, ciérrala desde aquí.</p>
            <button class="btn btn-secundario" @click="router.post('/perfil/cerrar-otras-sesiones', {}, { preserveScroll: true })">Cerrar sesiones en otros dispositivos</button>
        </section>
    </div>
</template>
