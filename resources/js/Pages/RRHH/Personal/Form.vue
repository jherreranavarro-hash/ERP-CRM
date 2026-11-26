<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';

type Opcion = { id: number; nombre: string };
interface Empleado { id: number; rut: string; nombres: string; apellidos: string; email: string | null; telefono: string | null; direccion: string | null; comuna: string | null; nacionalidad: string;
    local_id: number | null; centro_costo_id: number | null; turno_id: number | null; user_id: number | null; cargo: string; anios_previos: number; afp: string; salud: string;
    banco: string | null; cuenta_banco: string | null; fecha_nacimiento: string | null; fecha_ingreso: string; plan_isapre_uf: number }
const props = defineProps<{ empleado: Empleado | null; locales: Opcion[]; centros: (Opcion & { codigo: string })[]; turnos: Opcion[]; usuarios: { id: number; name: string; email: string }[]; afps: Record<string, string> }>();
const e = props.empleado;
const form = useForm({
    rut: e?.rut ?? '', nombres: e?.nombres ?? '', apellidos: e?.apellidos ?? '', fecha_nacimiento: e?.fecha_nacimiento ?? '', email: e?.email ?? '', telefono: e?.telefono ?? '',
    direccion: e?.direccion ?? '', comuna: e?.comuna ?? '', nacionalidad: e?.nacionalidad ?? 'Chilena', local_id: e?.local_id ?? null, centro_costo_id: e?.centro_costo_id ?? null,
    turno_id: e?.turno_id ?? null, user_id: e?.user_id ?? null, cargo: e?.cargo ?? '', fecha_ingreso: e?.fecha_ingreso ?? new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Santiago' }),
    anios_previos: e?.anios_previos ?? 0, afp: e?.afp ?? 'habitat', salud: e?.salud ?? 'fonasa', plan_isapre_uf: e?.plan_isapre_uf ?? 0, banco: e?.banco ?? '', cuenta_banco: e?.cuenta_banco ?? '',
});
const guardar = () => (e ? form.put(`/rrhh/personal/${e.id}`) : form.post('/rrhh/personal'));
</script>

<template>
    <Head :title="e ? 'Editar ficha' : 'Nueva ficha'" />
    <Encabezado :titulo="e ? 'Editar ficha' : 'Nueva ficha de personal'" :volver="e ? `/rrhh/personal/${e.id}` : '/rrhh/personal'" />

    <form class="space-y-6" @submit.prevent="guardar">
        <section class="tarjeta p-5">
            <h2 class="mb-4 text-[18px] font-bold">Datos personales</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Campo etiqueta="RUT" id="rut" obligatorio :error="form.errors.rut"><input id="rut" v-model="form.rut" class="campo h-11" maxlength="12" /></Campo>
                <Campo etiqueta="Nombres" id="nombres" obligatorio :error="form.errors.nombres"><input id="nombres" v-model="form.nombres" class="campo h-11" maxlength="80" /></Campo>
                <Campo etiqueta="Apellidos" id="apellidos" obligatorio :error="form.errors.apellidos"><input id="apellidos" v-model="form.apellidos" class="campo h-11" maxlength="80" /></Campo>
                <Campo etiqueta="Fecha de nacimiento" id="nac" :error="form.errors.fecha_nacimiento"><input id="nac" v-model="form.fecha_nacimiento" type="date" class="campo h-11" /></Campo>
                <Campo etiqueta="Nacionalidad" id="nacionalidad" obligatorio :error="form.errors.nacionalidad"><input id="nacionalidad" v-model="form.nacionalidad" class="campo h-11" maxlength="40" /></Campo>
                <Campo etiqueta="Correo" id="email" :error="form.errors.email"><input id="email" v-model="form.email" type="email" class="campo h-11" /></Campo>
                <Campo etiqueta="Teléfono" id="tel" :error="form.errors.telefono"><input id="tel" v-model="form.telefono" class="campo h-11" maxlength="20" /></Campo>
                <Campo etiqueta="Dirección" id="dir" :error="form.errors.direccion"><input id="dir" v-model="form.direccion" class="campo h-11" maxlength="150" /></Campo>
                <Campo etiqueta="Comuna" id="comuna" :error="form.errors.comuna"><input id="comuna" v-model="form.comuna" class="campo h-11" maxlength="60" /></Campo>
            </div>
        </section>

        <section class="tarjeta p-5">
            <h2 class="mb-4 text-[18px] font-bold">Trabajo</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Campo etiqueta="Cargo" id="cargo" obligatorio :error="form.errors.cargo"><input id="cargo" v-model="form.cargo" class="campo h-11" maxlength="80" /></Campo>
                <Campo etiqueta="Fecha de ingreso" id="ingreso" obligatorio :error="form.errors.fecha_ingreso"><input id="ingreso" v-model="form.fecha_ingreso" type="date" class="campo h-11" /></Campo>
                <Campo etiqueta="Años cotizados con otros empleadores" id="previos" :error="form.errors.anios_previos" ayuda="Para el feriado progresivo (máximo 10)."><input id="previos" v-model.number="form.anios_previos" type="number" min="0" max="50" class="campo h-11" /></Campo>
                <Campo etiqueta="Local" id="local" :error="form.errors.local_id"><select id="local" v-model="form.local_id" class="campo h-11"><option :value="null">—</option><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select></Campo>
                <Campo etiqueta="Centro de costo" id="centro" :error="form.errors.centro_costo_id"><select id="centro" v-model="form.centro_costo_id" class="campo h-11"><option :value="null">—</option><option v-for="c in centros" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select></Campo>
                <Campo etiqueta="Turno" id="turno" :error="form.errors.turno_id"><select id="turno" v-model="form.turno_id" class="campo h-11"><option :value="null">Sin turno</option><option v-for="t in turnos" :key="t.id" :value="t.id">{{ t.nombre }}</option></select></Campo>
                <Campo etiqueta="Usuario del sistema" id="usuario" :error="form.errors.user_id" ayuda="Enlaza sus ventas para calcular comisiones." class="xl:col-span-2">
                    <select id="usuario" v-model="form.user_id" class="campo h-11"><option :value="null">Ninguno</option><option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }} · {{ u.email }}</option></select>
                </Campo>
            </div>
        </section>

        <section class="tarjeta p-5">
            <h2 class="mb-4 text-[18px] font-bold">Previsión y pago</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Campo etiqueta="AFP" id="afp" obligatorio :error="form.errors.afp"><select id="afp" v-model="form.afp" class="campo h-11"><option v-for="(n, k) in afps" :key="k" :value="k">{{ n }}</option></select></Campo>
                <Campo etiqueta="Salud" id="salud" obligatorio :error="form.errors.salud" ayuda="Escribe «fonasa» o el nombre de la isapre."><input id="salud" v-model="form.salud" class="campo h-11" maxlength="30" list="isapres" /></Campo>
                <datalist id="isapres"><option value="fonasa" /><option value="Banmédica" /><option value="Colmena" /><option value="Consalud" /><option value="Cruz Blanca" /><option value="Nueva Masvida" /><option value="Vida Tres" /></datalist>
                <Campo v-if="form.salud.toLowerCase() !== 'fonasa'" etiqueta="Plan de isapre (UF)" id="plan" :error="form.errors.plan_isapre_uf"><input id="plan" v-model.number="form.plan_isapre_uf" type="number" step="0.0001" min="0" class="campo h-11" /></Campo>
                <Campo etiqueta="Banco" id="banco" :error="form.errors.banco"><input id="banco" v-model="form.banco" class="campo h-11" maxlength="60" /></Campo>
                <Campo etiqueta="N° de cuenta" id="cuenta" :error="form.errors.cuenta_banco"><input id="cuenta" v-model="form.cuenta_banco" class="campo h-11" maxlength="30" /></Campo>
            </div>
        </section>

        <div class="flex justify-end"><button type="submit" class="btn btn-primario" :disabled="form.processing">Guardar ficha</button></div>
    </form>
</template>
