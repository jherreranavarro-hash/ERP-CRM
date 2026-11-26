<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Avatar from '@/Components/Avatar.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora, haceCuanto } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Fila {
    id: number; user_id: number; nombre: string; email: string; iniciales: string; rol_id: number; rol: string | null; perfil: string | null;
    local_id: number | null; local: string | null; activo: boolean; tiene_2fa: boolean; es_superadmin: boolean; ultimo_acceso: string | null;
}
interface Opcion { id: number; nombre: string }
const props = defineProps<{
    usuarios: Paginado<Fila>;
    invitaciones: { id: number; email: string; nombre: string; rol: string; expira: string; vencida: boolean }[];
    roles: Opcion[];
    locales: Opcion[];
    filtros: { buscar?: string; estado?: string; rol_id?: string };
}>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '', rol_id: props.filtros.rol_id ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/configuracion/usuarios', { ...f }, { preserveState: true, replace: true }), 300); });

const invitar = useForm({ email: '', nombre: '', rol_id: '' as number | '', local_id: '' as number | '' });
const invitarAbierto = ref(false);
function enviarInvitacion() {
    invitar.transform((d) => ({ ...d, local_id: d.local_id || null })).post('/configuracion/usuarios/invitar', { preserveScroll: true, onSuccess: () => { invitarAbierto.value = false; invitar.reset(); } });
}

const editando = ref<Fila | null>(null);
const editar = useForm({ rol_id: 0, local_id: null as number | null, activo: true });
function abrirEdicion(u: Fila) {
    editando.value = u;
    editar.clearErrors();
    Object.assign(editar, { rol_id: u.rol_id, local_id: u.local_id, activo: u.activo });
}
function guardarEdicion() {
    editar.put(`/configuracion/usuarios/${editando.value!.id}`, { preserveScroll: true, onSuccess: () => (editando.value = null) });
}
function cerrarSesiones(u: Fila) {
    if (confirm(`¿Cerrar todas las sesiones de ${u.nombre}?`)) router.post(`/configuracion/usuarios/${u.id}/cerrar-sesiones`, {}, { preserveScroll: true });
}
const exportar = () => (window.location.href = `/configuracion/usuarios?${new URLSearchParams({ ...f, exportar: '1' })}`);
</script>

<template>
    <Head title="Usuarios" />
    <Encabezado titulo="Usuarios" bajada="Las cuentas se crean solo por invitación. Aquí se asigna el rol y el local de cada persona en esta empresa.">
        <button v-if="puede('configuracion.usuarios.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <button v-if="puede('configuracion.usuarios.crear')" class="btn btn-primario" @click="invitarAbierto = true"><Icono nombre="mas" :tamano="18" /> Invitar usuario</button>
    </Encabezado>

    <section v-if="invitaciones.length" class="tarjeta mb-6">
        <h2 class="px-5 pb-1 pt-4 font-bold">Invitaciones pendientes</h2>
        <table class="tabla">
            <tbody>
                <tr v-for="i in invitaciones" :key="i.id">
                    <td><div class="font-semibold">{{ i.nombre }}</div><div class="text-[14px] text-grafito">{{ i.email }}</div></td>
                    <td>{{ i.rol }}</td>
                    <td><Chip chico :tono="i.vencida ? 'error' : 'alerta'">{{ i.vencida ? 'Vencida' : `Vence ${fechaHora(i.expira)}` }}</Chip></td>
                    <td class="text-right"><button class="btn btn-secundario btn-sm" @click="router.delete(`/configuracion/invitaciones/${i.id}`, { preserveScroll: true })">Revocar</button></td>
                </tr>
            </tbody>
        </table>
    </section>

    <div class="tarjeta">
        <div class="flex flex-wrap gap-3 border-b border-linea p-4">
            <div class="relative min-w-[240px] flex-1">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Buscar por nombre o correo" aria-label="Buscar" />
            </div>
            <select v-model="f.rol_id" class="campo h-11 w-auto" aria-label="Rol">
                <option value="">Todos los roles</option>
                <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
            </select>
            <select v-model="f.estado" class="campo h-11 w-auto" aria-label="Estado">
                <option value="">Activos</option>
                <option value="inactivos">Inactivos</option>
                <option value="todos">Todos</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="usuarios.data.length" class="tabla">
                <thead><tr><th>Usuario</th><th>Rol</th><th>Local</th><th>Seguridad</th><th>Último acceso</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="u in usuarios.data" :key="u.id">
                        <td>
                            <div class="flex items-center gap-3">
                                <Avatar :iniciales="u.iniciales" />
                                <div><div class="font-semibold">{{ u.nombre }}</div><div class="text-[14px] text-grafito">{{ u.email }}</div></div>
                            </div>
                        </td>
                        <td><div>{{ u.rol ?? '—' }}</div><div class="text-[13px] text-grafito">{{ u.perfil }}</div></td>
                        <td>{{ u.local ?? 'Todos' }}</td>
                        <td class="space-x-1 whitespace-nowrap">
                            <Chip chico :tono="u.activo ? 'ok' : 'neutro'">{{ u.activo ? 'Activo' : 'Inactivo' }}</Chip>
                            <Chip chico :tono="u.tiene_2fa ? 'ok' : 'alerta'">{{ u.tiene_2fa ? '2FA activa' : 'Sin 2FA' }}</Chip>
                            <Chip v-if="u.es_superadmin" chico tono="naranja">SuperAdmin</Chip>
                        </td>
                        <td class="whitespace-nowrap text-grafito" :title="fechaHora(u.ultimo_acceso)">{{ haceCuanto(u.ultimo_acceso) }}</td>
                        <td class="whitespace-nowrap text-right">
                            <div v-if="puede('configuracion.usuarios.editar')" class="flex justify-end gap-2">
                                <button class="btn btn-secundario btn-sm" @click="abrirEdicion(u)">Editar</button>
                                <MenuAcciones :acciones="[{ etiqueta: 'Cerrar todas sus sesiones', accion: () => cerrarSesiones(u) }]" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin usuarios con ese filtro" />
        </div>
        <Paginacion :datos="usuarios" nombre="usuarios" />
    </div>

    <Modal :abierto="invitarAbierto" titulo="Invitar usuario" @cerrar="invitarAbierto = false">
        <form id="form-invitar" class="space-y-4" @submit.prevent="enviarInvitacion">
            <Campo etiqueta="Nombre" id="inv_nombre" :error="invitar.errors.nombre" obligatorio><input id="inv_nombre" v-model="invitar.nombre" class="campo h-11" /></Campo>
            <Campo etiqueta="Correo" id="inv_email" :error="invitar.errors.email" obligatorio ayuda="Recibirá un enlace para crear su contraseña."><input id="inv_email" v-model="invitar.email" type="email" class="campo h-11" /></Campo>
            <Campo etiqueta="Rol" id="inv_rol" :error="invitar.errors.rol_id" obligatorio>
                <select id="inv_rol" v-model="invitar.rol_id" class="campo h-11"><option value="" disabled>Elige un rol</option><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Local" id="inv_local" :error="invitar.errors.local_id" ayuda="Déjalo en «Todos» si trabaja en varios locales.">
                <select id="inv_local" v-model="invitar.local_id" class="campo h-11"><option value="">Todos</option><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
            </Campo>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="invitarAbierto = false">Cancelar</button>
            <button type="submit" form="form-invitar" class="btn btn-primario" :disabled="invitar.processing">Enviar invitación</button>
        </template>
    </Modal>

    <Modal :abierto="!!editando" :titulo="editando ? `Editar ${editando.nombre}` : ''" @cerrar="editando = null">
        <form id="form-editar" class="space-y-4" @submit.prevent="guardarEdicion">
            <Campo etiqueta="Rol" id="ed_rol" :error="editar.errors.rol_id">
                <select id="ed_rol" v-model="editar.rol_id" class="campo h-11"><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Local" id="ed_local" :error="editar.errors.local_id">
                <select id="ed_local" v-model="editar.local_id" class="campo h-11"><option :value="null">Todos</option><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
            </Campo>
            <Interruptor v-model="editar.activo" etiqueta="Activo en esta empresa" />
            <p v-if="editar.errors.activo" class="error">{{ editar.errors.activo }}</p>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="editando = null">Cancelar</button>
            <button type="submit" form="form-editar" class="btn btn-primario" :disabled="editar.processing">Guardar</button>
        </template>
    </Modal>
</template>
