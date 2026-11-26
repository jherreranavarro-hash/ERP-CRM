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
import Icono from '@/Components/Icono.vue';
import { pesos, rut } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Proveedor { id: number; rut: string; razon_social: string; giro: string | null; direccion: string | null; comuna: string | null; email: string | null; telefono: string | null; contacto: string | null; plazo_pago_dias: number; activo: boolean; deuda: number }
const props = defineProps<{ proveedores: Paginado<Proveedor>; filtros: { buscar?: string } }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/configuracion/proveedores', { ...f }, { preserveState: true, replace: true }), 300); });

const vacio = { rut: '', razon_social: '', giro: '', direccion: '', comuna: '', email: '', telefono: '', contacto: '', plazo_pago_dias: 30, activo: true };
const editando = ref<Proveedor | null>(null);
const abierto = ref(false);
const form = useForm({ ...vacio });

function abrir(p: Proveedor | null) {
    editando.value = p;
    form.clearErrors();
    Object.assign(form, p ? { ...vacio, ...Object.fromEntries(Object.entries(p).filter(([k]) => k in vacio).map(([k, v]) => [k, v ?? ''])) } : vacio);
    abierto.value = true;
}
function guardar() {
    const op = { preserveScroll: true, onSuccess: () => (abierto.value = false) };
    editando.value ? form.put(`/configuracion/proveedores/${editando.value.id}`, op) : form.post('/configuracion/proveedores', op);
}
function eliminar(p: Proveedor) {
    if (confirm(`¿Eliminar el proveedor ${p.razon_social}? Sus órdenes y facturas se conservan.`)) router.delete(`/configuracion/proveedores/${p.id}`, { preserveScroll: true });
}
const exportar = () => (window.location.href = `/configuracion/proveedores?${new URLSearchParams({ ...f, exportar: '1' })}`);
</script>

<template>
    <Head title="Proveedores" />
    <Encabezado titulo="Proveedores" bajada="A quién se compra. El plazo de pago define el vencimiento de sus facturas.">
        <button v-if="puede('configuracion.proveedores.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <button v-if="puede('configuracion.proveedores.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo proveedor</button>
    </Encabezado>

    <div class="tarjeta">
        <div class="border-b border-linea p-4">
            <div class="relative max-w-md">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Buscar por razón social o RUT" aria-label="Buscar" />
            </div>
        </div>
        <div class="overflow-x-auto">
            <table v-if="proveedores.data.length" class="tabla">
                <thead><tr><th>RUT</th><th>Razón social</th><th>Contacto</th><th class="text-right">Plazo</th><th class="text-right">Deuda</th><th>Estado</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="p in proveedores.data" :key="p.id">
                        <td class="num whitespace-nowrap font-semibold">{{ rut(p.rut) }}</td>
                        <td><div>{{ p.razon_social }}</div><div v-if="p.giro" class="text-[13px] text-grafito">{{ p.giro }}</div></td>
                        <td class="text-grafito"><div>{{ p.contacto || '—' }}</div><div v-if="p.email || p.telefono" class="text-[13px]">{{ [p.email, p.telefono].filter(Boolean).join(' · ') }}</div></td>
                        <td class="num text-right">{{ p.plazo_pago_dias === 0 ? 'Contado' : `${p.plazo_pago_dias} días` }}</td>
                        <td class="num text-right" :class="p.deuda ? 'font-semibold text-naranja-profundo' : 'text-grafito'">{{ p.deuda ? pesos(p.deuda) : '—' }}</td>
                        <td><Chip chico :tono="p.activo ? 'ok' : 'neutro'">{{ p.activo ? 'Activo' : 'Inactivo' }}</Chip></td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex justify-end gap-2">
                                <button v-if="puede('configuracion.proveedores.editar')" class="btn btn-secundario btn-sm" @click="abrir(p)">Editar</button>
                                <MenuAcciones v-if="puede('configuracion.proveedores.eliminar')" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(p) }]" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin proveedores" texto="Crea el primero o ajusta la búsqueda." />
        </div>
        <Paginacion :datos="proveedores" nombre="proveedores" />
    </div>

    <Modal :abierto="abierto" :titulo="editando ? 'Editar proveedor' : 'Nuevo proveedor'" ancho="lg" @cerrar="abierto = false">
        <form id="form-proveedor" class="grid gap-5 sm:grid-cols-2" @submit.prevent="guardar">
            <Campo etiqueta="RUT" id="rut" :error="form.errors.rut" obligatorio ayuda="Ej. 76.123.456-0"><input id="rut" v-model="form.rut" class="campo num h-11" autocomplete="off" /></Campo>
            <Campo etiqueta="Razón social" id="razon_social" :error="form.errors.razon_social" obligatorio><input id="razon_social" v-model="form.razon_social" class="campo h-11" maxlength="100" /></Campo>
            <Campo etiqueta="Giro" id="giro" :error="form.errors.giro"><input id="giro" v-model="form.giro" class="campo h-11" maxlength="80" /></Campo>
            <Campo etiqueta="Dirección" id="direccion" :error="form.errors.direccion"><input id="direccion" v-model="form.direccion" class="campo h-11" maxlength="100" /></Campo>
            <Campo etiqueta="Comuna" id="comuna" :error="form.errors.comuna"><input id="comuna" v-model="form.comuna" class="campo h-11" maxlength="40" /></Campo>
            <Campo etiqueta="Contacto" id="contacto" :error="form.errors.contacto"><input id="contacto" v-model="form.contacto" class="campo h-11" maxlength="100" /></Campo>
            <Campo etiqueta="Correo" id="email" :error="form.errors.email"><input id="email" v-model="form.email" type="email" class="campo h-11" /></Campo>
            <Campo etiqueta="Teléfono" id="telefono" :error="form.errors.telefono"><input id="telefono" v-model="form.telefono" class="campo h-11" /></Campo>
            <Campo etiqueta="Plazo de pago" id="plazo" :error="form.errors.plazo_pago_dias" obligatorio ayuda="Días desde la emisión de la factura."><input id="plazo" v-model.number="form.plazo_pago_dias" type="number" min="0" max="365" class="campo num h-11" /></Campo>
            <div class="flex items-end pb-2"><Interruptor v-model="form.activo" etiqueta="Proveedor activo" /></div>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button type="submit" form="form-proveedor" class="btn btn-primario" :disabled="form.processing">Guardar</button>
        </template>
    </Modal>
</template>
