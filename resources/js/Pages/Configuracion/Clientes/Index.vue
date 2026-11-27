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

interface Cliente { id: number; rut: string; razon_social: string; giro: string | null; direccion: string | null; comuna: string | null; ciudad: string | null; email: string | null; telefono: string | null; lista_precio: string; tiene_credito: boolean; cupo_credito: number; plazo_dias: number; activo: boolean }
const props = defineProps<{ clientes: Paginado<Cliente>; filtros: { buscar?: string; credito?: string }; plazos: number[]; listas: { codigo: string; nombre: string }[] }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', credito: props.filtros.credito ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/configuracion/clientes', { ...f }, { preserveState: true, replace: true }), 300); });

const vacio = { rut: '', razon_social: '', giro: '', direccion: '', comuna: '', ciudad: '', email: '', telefono: '', lista_precio: 'meson' as string, tiene_credito: false, cupo_credito: 0, plazo_dias: 0, activo: true };
const editando = ref<Cliente | null>(null);
const abierto = ref(false);
const form = useForm({ ...vacio });

function abrir(c: Cliente | null) {
    editando.value = c;
    form.clearErrors();
    Object.assign(form, c ? { ...vacio, ...Object.fromEntries(Object.entries(c).map(([k, v]) => [k, v ?? ''])) } : vacio);
    abierto.value = true;
}
function guardar() {
    const op = { preserveScroll: true, onSuccess: () => (abierto.value = false) };
    editando.value ? form.put(`/configuracion/clientes/${editando.value.id}`, op) : form.post('/configuracion/clientes', op);
}
function eliminar(c: Cliente) {
    if (confirm(`¿Eliminar el cliente ${c.razon_social}? Sus documentos se conservan.`)) router.delete(`/configuracion/clientes/${c.id}`, { preserveScroll: true });
}
const exportar = () => (window.location.href = `/configuracion/clientes?${new URLSearchParams({ ...f, exportar: '1' })}`);
const editaCredito = puede('configuracion.cuentas-corrientes.editar');
</script>

<template>
    <Head title="Clientes" />
    <Encabezado titulo="Clientes" bajada="Receptores de facturas y cuentas corrientes. El RUT se valida con dígito verificador.">
        <button v-if="puede('configuracion.clientes.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <button v-if="puede('configuracion.clientes.crear')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nuevo cliente</button>
    </Encabezado>

    <div class="tarjeta">
        <div class="flex flex-wrap gap-3 border-b border-linea p-4">
            <div class="relative min-w-[240px] flex-1">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Buscar por razón social o RUT" aria-label="Buscar" />
            </div>
            <select v-model="f.credito" class="campo h-11 w-auto" aria-label="Crédito">
                <option value="">Todos</option>
                <option value="1">Con cuenta corriente</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="clientes.data.length" class="tabla">
                <thead><tr><th>RUT</th><th>Razón social</th><th>Comuna</th><th>Lista</th><th class="text-right">Cupo</th><th>Estado</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="c in clientes.data" :key="c.id">
                        <td class="num whitespace-nowrap font-semibold">{{ rut(c.rut) }}</td>
                        <td><div>{{ c.razon_social }}</div><div v-if="c.giro" class="text-[13px] text-grafito">{{ c.giro }}</div></td>
                        <td class="text-grafito">{{ c.comuna || '—' }}</td>
                        <td>{{ listas.find((l) => l.codigo === c.lista_precio)?.nombre ?? c.lista_precio }}</td>
                        <td class="num text-right">{{ c.tiene_credito ? pesos(c.cupo_credito) : '—' }}<div v-if="c.tiene_credito" class="text-[13px] text-grafito">{{ c.plazo_dias }} días</div></td>
                        <td><Chip chico :tono="c.activo ? 'ok' : 'neutro'">{{ c.activo ? 'Activo' : 'Inactivo' }}</Chip></td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex justify-end gap-2">
                                <button v-if="puede('configuracion.clientes.editar')" class="btn btn-secundario btn-sm" @click="abrir(c)">Editar</button>
                                <MenuAcciones v-if="puede('configuracion.clientes.eliminar')" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(c) }]" />
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin clientes" texto="Crea el primero o ajusta la búsqueda." />
        </div>
        <Paginacion :datos="clientes" nombre="clientes" />
    </div>

    <Modal :abierto="abierto" :titulo="editando ? 'Editar cliente' : 'Nuevo cliente'" ancho="lg" @cerrar="abierto = false">
        <form id="form-cliente" class="grid gap-5 sm:grid-cols-2" @submit.prevent="guardar">
            <Campo etiqueta="RUT" id="rut" :error="form.errors.rut" obligatorio ayuda="Ej. 76.123.456-0"><input id="rut" v-model="form.rut" class="campo num h-11" autocomplete="off" /></Campo>
            <Campo etiqueta="Razón social" id="razon_social" :error="form.errors.razon_social" obligatorio><input id="razon_social" v-model="form.razon_social" class="campo h-11" maxlength="100" /></Campo>
            <Campo etiqueta="Giro" id="giro" :error="form.errors.giro" ayuda="Obligatorio para emitir factura."><input id="giro" v-model="form.giro" class="campo h-11" maxlength="80" /></Campo>
            <Campo etiqueta="Dirección" id="direccion" :error="form.errors.direccion" ayuda="Obligatoria para emitir factura."><input id="direccion" v-model="form.direccion" class="campo h-11" maxlength="70" /></Campo>
            <Campo etiqueta="Comuna" id="comuna" :error="form.errors.comuna"><input id="comuna" v-model="form.comuna" class="campo h-11" maxlength="40" /></Campo>
            <Campo etiqueta="Ciudad" id="ciudad" :error="form.errors.ciudad"><input id="ciudad" v-model="form.ciudad" class="campo h-11" maxlength="40" /></Campo>
            <Campo etiqueta="Correo" id="email" :error="form.errors.email"><input id="email" v-model="form.email" type="email" class="campo h-11" /></Campo>
            <Campo etiqueta="Teléfono" id="telefono" :error="form.errors.telefono"><input id="telefono" v-model="form.telefono" class="campo h-11" /></Campo>
            <Campo etiqueta="Lista de precios" id="lista_precio" :error="form.errors.lista_precio">
                <select id="lista_precio" v-model="form.lista_precio" class="campo h-11"><option v-for="l in listas" :key="l.codigo" :value="l.codigo">{{ l.nombre }}</option></select>
            </Campo>
            <div class="flex items-end pb-2"><Interruptor v-model="form.activo" etiqueta="Cliente activo" /></div>
            <template v-if="editaCredito">
                <div class="sm:col-span-2 border-t border-linea pt-4"><Interruptor v-model="form.tiene_credito" etiqueta="Tiene cuenta corriente (venta a crédito)" /></div>
                <template v-if="form.tiene_credito">
                    <Campo etiqueta="Cupo de crédito" id="cupo" :error="form.errors.cupo_credito" ayuda="En pesos."><input id="cupo" v-model.number="form.cupo_credito" type="number" min="0" step="1" class="campo num h-11" /></Campo>
                    <Campo etiqueta="Plazo" id="plazo" :error="form.errors.plazo_dias">
                        <select id="plazo" v-model.number="form.plazo_dias" class="campo h-11"><option v-for="p in plazos" :key="p" :value="p">{{ p === 0 ? 'Contado' : `${p} días` }}</option></select>
                    </Campo>
                </template>
            </template>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button type="submit" form="form-cliente" class="btn btn-primario" :disabled="form.processing">Guardar</button>
        </template>
    </Modal>
</template>
