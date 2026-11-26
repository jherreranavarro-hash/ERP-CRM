<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Proveedor { id: number; nombre: string; tipo: string; host: string; puerto: number; protocolo: string; usuario_servicio: string; activo: boolean; salud: string | null; salud_at: string | null; tiene_secreto: boolean; rotado: string | null; rotar: boolean }
defineProps<{ proveedores: Proveedor[]; tipos: Record<string, string>; protocolos: Record<string, string> }>();
const puede = usePermisos();
const editando = ref<Proveedor | null>(null);
const abierto = ref(false);
const vacio = () => ({ nombre: '', tipo: 'asterisk', host: '', puerto: 5038, protocolo: 'ami', usuario_servicio: '', secreto: '', activo: true });
const form = useForm(vacio());
function abrir(p: Proveedor | null) {
    editando.value = p;
    form.clearErrors();
    Object.assign(form, p ? { nombre: p.nombre, tipo: p.tipo, host: p.host, puerto: p.puerto, protocolo: p.protocolo, usuario_servicio: p.usuario_servicio, secreto: '', activo: p.activo } : vacio());
    abierto.value = true;
}
const guardar = () => {
    const o = { preserveScroll: true, onSuccess: () => { abierto.value = false; form.secreto = ''; } };
    const f = form.transform((d) => ({ ...d, secreto: d.secreto || null }));
    editando.value ? f.put(`/configuracion/telefonia/proveedores/${editando.value.id}`, o) : f.post('/configuracion/telefonia/proveedores', o);
};
const probar = (p: Proveedor) => router.post(`/configuracion/telefonia/proveedores/${p.id}/probar`, {}, { preserveScroll: true });
const salud: Record<string, ['ok' | 'error' | 'neutro', string]> = { ok: ['ok', 'Conectado'], caida: ['error', 'Sin conexión'] };
</script>

<template>
    <Head title="Proveedores IP" />
    <Encabezado titulo="Proveedores IP" bajada="Centrales telefónicas (Asterisk, FreeSWITCH o VICIdial). La contraseña se guarda cifrada y nunca se vuelve a mostrar.">
        <button v-if="puede('configuracion.proveedor-ip.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo proveedor</button>
    </Encabezado>

    <div v-if="proveedores.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Proveedor</th><th>Servidor</th><th>Usuario</th><th>Contraseña</th><th>Estado</th><th></th></tr></thead>
            <tbody>
                <tr v-for="p in proveedores" :key="p.id" :class="{ 'text-grafito': !p.activo }">
                    <td><p class="font-semibold">{{ p.nombre }}</p><p class="text-[13px] text-grafito">{{ tipos[p.tipo] }} · {{ protocolos[p.protocolo] }}</p></td>
                    <td class="num">{{ p.host }}:{{ p.puerto }}</td>
                    <td>{{ p.usuario_servicio }}</td>
                    <td class="text-[14px]">
                        <template v-if="p.tiene_secreto">Guardada<template v-if="p.rotado"> el {{ fechaHora(p.rotado) }}</template></template><template v-else>—</template>
                        <p v-if="p.rotar" class="font-semibold text-naranja-profundo">Conviene cambiarla (más de 90 días)</p>
                    </td>
                    <td><Chip chico :tono="p.salud ? (salud[p.salud]?.[0] ?? 'neutro') : 'neutro'">{{ p.salud ? (salud[p.salud]?.[1] ?? p.salud) : 'Sin probar' }}</Chip><p v-if="p.salud_at" class="num text-[13px] text-grafito">{{ fechaHora(p.salud_at) }}</p></td>
                    <td class="whitespace-nowrap text-right">
                        <template v-if="puede('configuracion.proveedor-ip.editar')">
                            <button class="btn btn-texto btn-sm" @click="probar(p)">Probar</button>
                            <button class="btn btn-secundario btn-sm" @click="abrir(p)">Editar</button>
                        </template>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin proveedores" texto="Mientras no haya una central configurada, el sistema usa una central simulada para probar el flujo de llamadas." />

    <Modal :abierto="abierto" :titulo="editando ? `Editar ${editando.nombre}` : 'Nuevo proveedor'" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Nombre" id="pv-nom" obligatorio :error="form.errors.nombre" class="sm:col-span-2"><input id="pv-nom" v-model="form.nombre" class="campo h-11" maxlength="80" /></Campo>
            <Campo etiqueta="Tipo de central" id="pv-tipo" obligatorio :error="form.errors.tipo"><select id="pv-tipo" v-model="form.tipo" class="campo h-11"><option v-for="(n, k) in tipos" :key="k" :value="k">{{ n }}</option></select></Campo>
            <Campo etiqueta="Protocolo" id="pv-prot" obligatorio :error="form.errors.protocolo"><select id="pv-prot" v-model="form.protocolo" class="campo h-11"><option v-for="(n, k) in protocolos" :key="k" :value="k">{{ n }}</option></select></Campo>
            <Campo etiqueta="Servidor" id="pv-host" obligatorio :error="form.errors.host" ayuda="Nombre o IP del servidor, sin protocolo ni ruta."><input id="pv-host" v-model="form.host" class="campo num h-11" maxlength="120" autocomplete="off" /></Campo>
            <Campo etiqueta="Puerto" id="pv-port" obligatorio :error="form.errors.puerto"><input id="pv-port" v-model.number="form.puerto" type="number" min="1" max="65535" class="campo num h-11" /></Campo>
            <Campo etiqueta="Usuario de servicio" id="pv-usr" obligatorio :error="form.errors.usuario_servicio"><input id="pv-usr" v-model="form.usuario_servicio" class="campo h-11" maxlength="60" autocomplete="off" /></Campo>
            <Campo :etiqueta="editando ? 'Nueva contraseña' : 'Contraseña'" id="pv-sec" :obligatorio="!editando" :error="form.errors.secreto" :ayuda="editando ? 'Déjala vacía para mantener la actual.' : 'Mínimo 8 caracteres.'"><input id="pv-sec" v-model="form.secreto" type="password" class="campo h-11" autocomplete="new-password" maxlength="200" /></Campo>
        </div>
        <div class="mt-4"><Interruptor v-model="form.activo" etiqueta="Activo" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
