<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Icono from '@/Components/Icono.vue';
import type { Paginado } from '@/types';
import { fecha, fechaHora } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Numero { id: number; numero: string; motivo: string; hasta: string | null; creador: string | null; fecha: string }
const props = defineProps<{ numeros: Paginado<Numero>; filtros: { buscar?: string } }>();
const puede = usePermisos();
const buscar = ref(props.filtros.buscar ?? '');
const filtrar = () => router.get('/configuracion/telefonia/lista-negra', buscar.value ? { buscar: buscar.value } : {}, { preserveState: true, replace: true });
const abierto = ref(false);
const form = useForm({ numero: '', motivo: '', hasta: '' });
const agregar = () => form.transform((d) => ({ ...d, hasta: d.hasta || null })).post('/configuracion/telefonia/lista-negra', { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset(); } });
const quitando = ref<Numero | null>(null);
const quitar = () => router.delete(`/configuracion/telefonia/lista-negra/${quitando.value!.id}`, { preserveScroll: true, onFinish: () => { quitando.value = null; } });
</script>

<template>
    <Head title="Lista negra" />
    <Encabezado titulo="Lista negra" bajada="Números a los que no se llama desde ninguna campaña. Se compara por los últimos 9 dígitos.">
        <button v-if="puede('configuracion.lista-negra.crear')" class="btn btn-primario" @click="abierto = true"><Icono nombre="mas" :tamano="18" /> Bloquear número</button>
    </Encabezado>

    <div class="tarjeta">
        <form class="flex gap-2 border-b border-linea p-4" @submit.prevent="filtrar"><input v-model="buscar" class="campo h-9 w-64" inputmode="tel" placeholder="Buscar número" aria-label="Buscar número" /><button class="btn btn-secundario btn-sm">Buscar</button></form>
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Número</th><th>Motivo</th><th>Vigente hasta</th><th>Agregado por</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="n in numeros.data" :key="n.id">
                        <td class="num font-semibold">{{ n.numero }}</td>
                        <td>{{ n.motivo }}</td>
                        <td class="num">{{ n.hasta ? fecha(n.hasta) : 'Indefinido' }}</td>
                        <td class="text-[14px]">{{ n.creador ?? '—' }}<p class="num text-[13px] text-grafito">{{ fechaHora(n.fecha) }}</p></td>
                        <td class="text-right"><button v-if="puede('configuracion.lista-negra.eliminar')" class="btn btn-texto btn-sm" @click="quitando = n">Quitar</button></td>
                    </tr>
                    <tr v-if="!numeros.data.length"><td colspan="5" class="text-center text-grafito">Sin números bloqueados.</td></tr>
                </tbody>
            </table>
        </div>
        <Paginacion :datos="numeros" nombre="números" />
    </div>

    <Modal :abierto="abierto" titulo="Bloquear número" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Número" id="ln-num" obligatorio :error="form.errors.numero"><input id="ln-num" v-model="form.numero" class="campo num h-11" inputmode="tel" maxlength="16" placeholder="+56912345678" /></Campo>
            <Campo etiqueta="Hasta" id="ln-hasta" :error="form.errors.hasta" ayuda="Vacío: indefinido."><input id="ln-hasta" v-model="form.hasta" type="date" class="campo h-11" /></Campo>
            <Campo etiqueta="Motivo" id="ln-mot" obligatorio :error="form.errors.motivo" class="sm:col-span-2"><input id="ln-mot" v-model="form.motivo" class="campo h-11" maxlength="200" placeholder="Ej.: pidió no ser llamado" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="agregar">Bloquear</button>
        </template>
    </Modal>

    <Modal :abierto="!!quitando" titulo="Quitar de la lista negra" @cerrar="quitando = null">
        <p>Se volverá a poder llamar al <span class="num font-semibold">{{ quitando?.numero }}</span>. El cambio queda en la auditoría.</p>
        <template #pie>
            <button class="btn btn-secundario" @click="quitando = null">Cancelar</button>
            <button class="btn btn-peligro" @click="quitar">Quitar</button>
        </template>
    </Modal>
</template>
