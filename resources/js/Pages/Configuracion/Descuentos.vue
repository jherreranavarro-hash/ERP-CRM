<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import MenuAcciones from '@/Components/MenuAcciones.vue';
import Vacio from '@/Components/Vacio.vue';
import { fecha } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Regla { id: number; nombre: string; categoria_id: number | null; categoria: string | null; lista_precio: string | null; cantidad_minima: string; descuento_pct: string;
    activa: boolean; vigente_desde: string | null; vigente_hasta: string | null; vigente: boolean }
interface Nivel { id: number; hasta_pct: string; rol_id: number; rol: string }
const props = defineProps<{ maximo: string; reglas: Regla[]; niveles: Nivel[]; categorias: { id: number; nombre: string }[]; listas: { codigo: string; nombre: string }[]; roles: { id: number; nombre: string }[] }>();
const puede = usePermisos();
const nombreLista = (c: string | null) => (c ? props.listas.find((l) => l.codigo === c)?.nombre ?? c : 'Todas');

const editando = ref<Regla | null>(null);
const abierto = ref(false);
const form = useForm({ nombre: '', categoria_id: null as number | null, lista_precio: null as string | null, cantidad_minima: '0' as string | number, descuento_pct: '' as string | number, vigente_desde: '', vigente_hasta: '', activa: true });
function nueva() {
    editando.value = null;
    form.reset();
    form.clearErrors();
    abierto.value = true;
}
function abrir(r: Regla) {
    editando.value = r;
    form.clearErrors();
    Object.assign(form, { nombre: r.nombre, categoria_id: r.categoria_id, lista_precio: r.lista_precio, cantidad_minima: r.cantidad_minima, descuento_pct: r.descuento_pct,
        vigente_desde: r.vigente_desde ?? '', vigente_hasta: r.vigente_hasta ?? '', activa: r.activa });
    abierto.value = true;
}
function guardar() {
    const f = form.transform((d) => ({ ...d, vigente_desde: d.vigente_desde || null, vigente_hasta: d.vigente_hasta || null }));
    const op = { preserveScroll: true, onSuccess: () => (abierto.value = false) };
    editando.value ? f.put(`/configuracion/descuentos/reglas/${editando.value.id}`, op) : f.post('/configuracion/descuentos/reglas', op);
}
const eliminar = (r: Regla) => router.delete(`/configuracion/descuentos/reglas/${r.id}`, { preserveScroll: true });

const nivelForm = useForm({ hasta_pct: '' as string | number, rol_id: null as number | null });
const guardarNivel = () => nivelForm.post('/configuracion/descuentos/niveles', { preserveScroll: true, onSuccess: () => nivelForm.reset() });
const eliminarNivel = (n: Nivel) => router.delete(`/configuracion/descuentos/niveles/${n.id}`, { preserveScroll: true });

// Escalera completa: sin aprobación → escalones → no permitido
const escalera = computed(() => {
    const pasos: { rango: string; quien: string; tono: 'ok' | 'info' | 'error' }[] = [{ rango: `Hasta ${props.maximo} %`, quien: 'Sin aprobación', tono: 'ok' }];
    let desde = Number(props.maximo);
    for (const n of props.niveles) {
        pasos.push({ rango: `Sobre ${desde} % y hasta ${n.hasta_pct} %`, quien: `Aprueba ${n.rol} o superior`, tono: 'info' });
        desde = Number(n.hasta_pct);
    }
    pasos.push(props.niveles.length ? { rango: `Sobre ${desde} %`, quien: 'No se acepta', tono: 'error' } : { rango: `Sobre ${desde} %`, quien: 'Aprueba cualquiera con permiso de aprobar', tono: 'info' });
    return pasos;
});
</script>

<template>
    <Head title="Descuentos" />
    <Encabezado titulo="Descuentos" bajada="Reglas que aplican un descuento solo (por categoría, lista del cliente y cantidad) y escalones de aprobación por rol para los descuentos manuales. Nadie aprueba su propio descuento." />

    <div class="grid gap-6 lg:grid-cols-[1fr_1.5fr]">
        <section class="tarjeta p-5">
            <h2 class="mb-1 text-[18px] font-bold">Escalones de aprobación</h2>
            <p class="mb-4 text-[14px] text-grafito">El máximo sin aprobación ({{ maximo }} %) se cambia en Parámetros → Aprobaciones y umbrales.</p>
            <ol class="mb-4 space-y-2">
                <li v-for="(p, i) in escalera" :key="i" class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-boton bg-hormigon px-4 py-3">
                    <span class="num whitespace-nowrap font-semibold">{{ p.rango }}</span>
                    <Chip chico :tono="p.tono">{{ p.quien }}</Chip>
                </li>
            </ol>
            <ul v-if="niveles.length && puede('configuracion.descuentos.eliminar')" class="mb-4 text-[14px]">
                <li v-for="n in niveles" :key="n.id" class="flex items-center justify-between border-b border-linea py-2 last:border-0">
                    <span>Hasta <span class="num font-semibold">{{ n.hasta_pct }} %</span>: {{ n.rol }}</span>
                    <button class="btn btn-texto btn-sm" @click="eliminarNivel(n)">Quitar</button>
                </li>
            </ul>
            <div v-if="puede('configuracion.descuentos.editar')" class="flex flex-wrap items-end gap-3 border-t border-linea pt-4">
                <Campo etiqueta="Hasta %" id="nv-pct" :error="nivelForm.errors.hasta_pct"><input id="nv-pct" v-model="nivelForm.hasta_pct" type="number" min="0" max="100" step="0.5" class="campo num h-11 w-28" /></Campo>
                <Campo etiqueta="Rol que aprueba" id="nv-rol" :error="nivelForm.errors.rol_id">
                    <select id="nv-rol" v-model="nivelForm.rol_id" class="campo h-11"><option :value="null" disabled>Elige un rol</option><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option></select>
                </Campo>
                <button class="btn btn-secundario" :disabled="nivelForm.processing" @click="guardarNivel">Agregar escalón</button>
            </div>
        </section>

        <section class="tarjeta overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
                <div>
                    <h2 class="text-[18px] font-bold">Reglas automáticas</h2>
                    <p class="text-[14px] text-grafito">Se aplican cuando la línea no trae descuento, y autorizan ese % sin aprobación. Si varias calzan, gana la mayor.</p>
                </div>
                <button v-if="puede('configuracion.descuentos.crear')" class="btn btn-primario" @click="nueva">Nueva regla</button>
            </div>
            <div class="overflow-x-auto">
                <table v-if="reglas.length" class="tabla">
                    <thead><tr><th>Regla</th><th>Aplica a</th><th class="text-right">Desde</th><th class="text-right">Descuento</th><th>Estado</th><th class="w-px"></th></tr></thead>
                    <tbody>
                        <tr v-for="r in reglas" :key="r.id">
                            <td><div class="font-semibold">{{ r.nombre }}</div><div v-if="r.vigente_desde || r.vigente_hasta" class="text-[13px] text-grafito">{{ r.vigente_desde ? fecha(r.vigente_desde) : '…' }} al {{ r.vigente_hasta ? fecha(r.vigente_hasta) : '…' }}</div></td>
                            <td><div>{{ r.categoria ?? 'Todas las categorías' }}</div><div class="text-[13px] text-grafito">Lista: {{ nombreLista(r.lista_precio) }}</div></td>
                            <td class="num text-right">{{ Number(r.cantidad_minima) > 0 ? `${Number(r.cantidad_minima).toLocaleString('es-CL')} un.` : 'Cualquier cantidad' }}</td>
                            <td class="num text-right font-semibold">{{ r.descuento_pct }} %</td>
                            <td><Chip chico :tono="!r.activa ? 'neutro' : r.vigente ? 'ok' : 'alerta'">{{ !r.activa ? 'Inactiva' : r.vigente ? 'Vigente' : 'Fuera de vigencia' }}</Chip></td>
                            <td class="whitespace-nowrap text-right">
                                <div class="flex justify-end gap-2">
                                    <button v-if="puede('configuracion.descuentos.editar')" class="btn btn-secundario btn-sm" @click="abrir(r)">Editar</button>
                                    <MenuAcciones v-if="puede('configuracion.descuentos.eliminar')" :acciones="[{ etiqueta: 'Eliminar', peligro: true, accion: () => eliminar(r) }]" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <Vacio v-else titulo="Sin reglas" texto="Por ejemplo: 5 % en Cementos desde 50 unidades para la lista mayorista." />
            </div>
        </section>
    </div>

    <Modal :abierto="abierto" :titulo="editando ? 'Editar regla' : 'Nueva regla de descuento'" @cerrar="abierto = false">
        <div class="grid gap-4 sm:grid-cols-2">
            <Campo etiqueta="Nombre" id="rg-nombre" obligatorio :error="form.errors.nombre" class="sm:col-span-2"><input id="rg-nombre" v-model="form.nombre" class="campo h-11" maxlength="80" /></Campo>
            <Campo etiqueta="Categoría" id="rg-cat" :error="form.errors.categoria_id">
                <select id="rg-cat" v-model="form.categoria_id" class="campo h-11"><option :value="null">Todas</option><option v-for="c in categorias" :key="c.id" :value="c.id">{{ c.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Lista del cliente" id="rg-lista" :error="form.errors.lista_precio">
                <select id="rg-lista" v-model="form.lista_precio" class="campo h-11"><option :value="null">Todas</option><option v-for="l in listas" :key="l.codigo" :value="l.codigo">{{ l.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Cantidad mínima por línea" id="rg-cant" obligatorio :error="form.errors.cantidad_minima"><input id="rg-cant" v-model="form.cantidad_minima" type="number" min="0" class="campo num h-11" /></Campo>
            <Campo etiqueta="Descuento %" id="rg-pct" obligatorio :error="form.errors.descuento_pct"><input id="rg-pct" v-model="form.descuento_pct" type="number" min="0" max="90" step="0.5" class="campo num h-11" /></Campo>
            <Campo etiqueta="Vigente desde" id="rg-desde" :error="form.errors.vigente_desde"><input id="rg-desde" v-model="form.vigente_desde" type="date" class="campo h-11" /></Campo>
            <Campo etiqueta="Vigente hasta" id="rg-hasta" :error="form.errors.vigente_hasta"><input id="rg-hasta" v-model="form.vigente_hasta" type="date" class="campo h-11" /></Campo>
        </div>
        <div class="mt-4"><Interruptor v-model="form.activa" etiqueta="Activa" /></div>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
