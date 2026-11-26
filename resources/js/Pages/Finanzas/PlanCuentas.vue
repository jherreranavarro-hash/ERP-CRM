<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Icono from '@/Components/Icono.vue';
import { usePermisos } from '@/lib/permisos';

interface Cuenta { id: number; codigo: string; nombre: string; tipo: string; clave: string | null; activa: boolean; movimientos: number }
const props = defineProps<{ cuentas: Cuenta[]; tipos: Record<string, string> }>();
const puede = usePermisos();
const grupos = computed(() => Object.entries(props.tipos).map(([k, n]) => ({ tipo: k, nombre: n, cuentas: props.cuentas.filter((c) => c.tipo === k) })));

const editando = ref<Cuenta | null>(null);
const abierto = ref(false);
const form = useForm({ codigo: '', nombre: '', tipo: 'gasto', activa: true });
function abrir(c: Cuenta | null) {
    editando.value = c;
    form.clearErrors();
    Object.assign(form, c ? { codigo: c.codigo, nombre: c.nombre, tipo: c.tipo, activa: c.activa } : { codigo: '', nombre: '', tipo: 'gasto', activa: true });
    abierto.value = true;
}
const opts = { preserveScroll: true, onSuccess: () => { abierto.value = false; } };
const guardar = () => (editando.value ? form.put(`/finanzas/plan-de-cuentas/${editando.value.id}`, opts) : form.post('/finanzas/plan-de-cuentas', opts));
</script>

<template>
    <Head title="Plan de cuentas" />
    <Encabezado titulo="Plan de cuentas" bajada="Las cuentas marcadas como automáticas las usan los asientos que genera el sistema (ventas, compras, pagos, honorarios).">
        <Link href="/finanzas/asientos" class="btn btn-secundario no-underline">Libro diario</Link>
        <button v-if="puede('finanzas.asientos-contables.editar')" class="btn btn-primario" @click="abrir(null)"><Icono nombre="mas" :tamano="18" /> Nueva cuenta</button>
    </Encabezado>

    <div class="grid gap-6 lg:grid-cols-2">
        <section v-for="g in grupos" :key="g.tipo" class="tarjeta">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">{{ g.nombre }}</h2>
            <ul class="divide-y divide-linea">
                <li v-for="c in g.cuentas" :key="c.id" class="flex items-center justify-between gap-3 px-4 py-3" :class="!c.activa && 'text-grafito'">
                    <div>
                        <span class="num mr-2 font-semibold">{{ c.codigo }}</span>{{ c.nombre }}
                        <Chip v-if="c.clave" chico tono="info" class="ml-2">Automática</Chip>
                        <Chip v-if="!c.activa" chico class="ml-2">Inactiva</Chip>
                    </div>
                    <div class="flex items-center gap-3">
                        <Link v-if="c.movimientos" :href="`/finanzas/balances?vista=mayor&cuenta_id=${c.id}`" class="text-[14px]">{{ c.movimientos }} mov.</Link>
                        <button v-if="puede('finanzas.asientos-contables.editar')" class="btn btn-secundario btn-sm" @click="abrir(c)">Editar</button>
                    </div>
                </li>
                <li v-if="!g.cuentas.length" class="p-4 text-grafito">Sin cuentas.</li>
            </ul>
        </section>
    </div>

    <Modal :abierto="abierto" :titulo="editando ? 'Editar cuenta' : 'Nueva cuenta'" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Código" id="pc-codigo" obligatorio :error="form.errors.codigo" ayuda="Ej. 5.1.10"><input id="pc-codigo" v-model="form.codigo" class="campo h-11" maxlength="20" /></Campo>
            <Campo etiqueta="Tipo" id="pc-tipo" obligatorio :error="form.errors.tipo">
                <select id="pc-tipo" v-model="form.tipo" class="campo h-11"><option v-for="(n, k) in tipos" :key="k" :value="k">{{ n }}</option></select>
            </Campo>
            <Campo etiqueta="Nombre" id="pc-nombre" obligatorio :error="form.errors.nombre" class="sm:col-span-2"><input id="pc-nombre" v-model="form.nombre" class="campo h-11" maxlength="100" /></Campo>
        </div>
        <div class="mt-4"><Interruptor v-model="form.activa" etiqueta="Activa" /></div>
        <p v-if="form.errors.activa" class="error mt-2" role="alert">{{ form.errors.activa }}</p>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
