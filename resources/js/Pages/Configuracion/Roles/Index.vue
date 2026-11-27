<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import { fechaHora } from '@/lib/formato';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Icono from '@/Components/Icono.vue';
import { usePermisos } from '@/lib/permisos';

interface Rol { id: number; nombre: string; descripcion: string | null; perfil: string; activo: boolean; permisos: number; usuarios: number }
interface Solicitud { id: number; tipo: 'rol_permisos' | 'superadmin'; rol: string | null; usuario: string | null; permisos: string[]; motivo: string; solicitante: string | null; propia: boolean; fecha: string }
defineProps<{ roles: Rol[]; solicitudes: Solicitud[]; puedeAprobar: boolean }>();
const puede = usePermisos();
const rechazando = ref<Solicitud | null>(null);
const formRechazo = useForm({ comentario: '' });
const aprobar = (s: Solicitud) => router.post(`/configuracion/privilegios/${s.id}/aprobar`, {}, { preserveScroll: true });
const anular = (s: Solicitud) => router.post(`/configuracion/privilegios/${s.id}/anular`, {}, { preserveScroll: true });
const rechazar = () => formRechazo.post(`/configuracion/privilegios/${rechazando.value!.id}/rechazar`, { preserveScroll: true, onSuccess: () => { rechazando.value = null; formRechazo.reset(); } });

function eliminar(r: Rol) {
    if (confirm(`¿Eliminar el rol ${r.nombre}?`)) router.delete(`/configuracion/roles/${r.id}`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Perfiles y roles" />
    <Encabezado titulo="Perfiles y roles" bajada="Cada rol pertenece a un perfil y define qué puede hacer en cada submódulo. El backend verifica cada permiso; ocultar un menú no basta.">
        <Link v-if="puede('configuracion.perfiles.crear')" href="/configuracion/roles/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nuevo rol</Link>
    </Encabezado>

    <section v-if="solicitudes.length" class="mb-6">
        <h2 class="mb-1 text-[18px] font-bold">Solicitudes pendientes</h2>
        <p class="mb-3 text-[14px] text-grafito">Dar permisos a un rol u otorgar SuperAdmin requiere que otra persona lo apruebe. Quitar permisos rige al tiro.</p>
        <ul class="grid gap-3">
            <li v-for="s in solicitudes" :key="s.id" class="tarjeta flex flex-wrap items-start justify-between gap-3 p-4">
                <div class="min-w-0">
                    <p class="font-semibold">
                        <template v-if="s.tipo === 'rol_permisos'">Agregar {{ s.permisos.length }} permiso(s) al rol {{ s.rol }}</template>
                        <template v-else>Otorgar SuperAdmin a {{ s.usuario }}</template>
                    </p>
                    <p class="text-[14px] text-grafito">{{ s.motivo }} · pidió {{ s.solicitante }} · {{ fechaHora(s.fecha) }}</p>
                    <div v-if="s.permisos.length" class="mt-2 flex flex-wrap gap-1"><Chip v-for="c in s.permisos" :key="c" chico><span class="num">{{ c }}</span></Chip></div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <template v-if="s.propia">
                        <Chip chico tono="info">Esperando a otra persona</Chip>
                        <button class="btn btn-texto btn-sm" @click="anular(s)">Anular</button>
                    </template>
                    <template v-else-if="puedeAprobar">
                        <button class="btn btn-primario btn-sm" @click="aprobar(s)">Aprobar</button>
                        <button class="btn btn-secundario btn-sm" @click="rechazando = s">Rechazar</button>
                    </template>
                </div>
            </li>
        </ul>
    </section>

    <div class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Rol</th><th>Perfil</th><th class="text-right">Permisos</th><th class="text-right">Usuarios</th><th>Estado</th><th class="w-px"></th></tr></thead>
            <tbody>
                <tr v-for="r in roles" :key="r.id">
                    <td><div class="font-semibold">{{ r.nombre }}</div><div v-if="r.descripcion" class="text-[14px] text-grafito">{{ r.descripcion }}</div></td>
                    <td>{{ r.perfil }}</td>
                    <td class="num text-right">{{ r.permisos }}</td>
                    <td class="num text-right">{{ r.usuarios }}</td>
                    <td><Chip chico :tono="r.activo ? 'ok' : 'neutro'">{{ r.activo ? 'Activo' : 'Inactivo' }}</Chip></td>
                    <td class="whitespace-nowrap text-right">
                        <div class="flex justify-end gap-2">
                            <Link v-if="puede('configuracion.perfiles.editar')" :href="`/configuracion/roles/${r.id}/editar`" class="btn btn-secundario btn-sm no-underline">Editar permisos</Link>
                            <MenuAcciones v-if="puede('configuracion.perfiles.eliminar') && !r.usuarios" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(r) }]" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <Modal :abierto="!!rechazando" titulo="Rechazar solicitud" @cerrar="rechazando = null">
        <Campo etiqueta="Motivo del rechazo" id="rechazo" obligatorio :error="formRechazo.errors.comentario"><textarea id="rechazo" v-model="formRechazo.comentario" class="campo" rows="3" maxlength="500" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="rechazando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="formRechazo.processing" @click="rechazar">Rechazar</button>
        </template>
    </Modal>
</template>
