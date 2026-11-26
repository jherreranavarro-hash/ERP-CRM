<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Icono from '@/Components/Icono.vue';
import { pesos } from '@/lib/formato';

defineProps<{ cuentas: { id: number; codigo: string; nombre: string; tipo: string }[]; centros: { id: number; codigo: string; nombre: string }[] }>();
interface Linea { cuenta_id: number | null; centro_costo_id: number | null; debe: number | null; haber: number | null; glosa: string }
const vacia = (): Linea => ({ cuenta_id: null, centro_costo_id: null, debe: null, haber: null, glosa: '' });
const hoy = new Date().toLocaleDateString('sv-SE', { timeZone: 'America/Santiago' });
const form = useForm({ fecha: hoy, glosa: '', lineas: [vacia(), vacia()] });
const debe = computed(() => form.lineas.reduce((s, l) => s + (Number(l.debe) || 0), 0));
const haber = computed(() => form.lineas.reduce((s, l) => s + (Number(l.haber) || 0), 0));
const diferencia = computed(() => debe.value - haber.value);
const err = (i: number, c: string) => (form.errors as Record<string, string>)[`lineas.${i}.${c}`];
// Al escribir en debe se limpia haber (y viceversa): una línea va a un solo lado.
const lado = (l: Linea, c: 'debe' | 'haber') => { if (Number(l[c])) l[c === 'debe' ? 'haber' : 'debe'] = null; };
const guardar = () => form.transform((d) => ({ ...d, lineas: d.lineas.map((l) => ({ ...l, debe: Number(l.debe) || 0, haber: Number(l.haber) || 0 })) })).post('/finanzas/asientos');
</script>

<template>
    <Head title="Nuevo asiento" />
    <Encabezado titulo="Nuevo asiento" bajada="Cada línea va al debe o al haber. El asiento se contabiliza solo si cuadra.">
        <Link href="/finanzas/asientos" class="btn btn-secundario no-underline">Volver</Link>
    </Encabezado>

    <div class="tarjeta mb-6 grid gap-4 p-4 sm:grid-cols-[200px_1fr]">
        <Campo etiqueta="Fecha" id="a-fecha" obligatorio :error="form.errors.fecha"><input id="a-fecha" v-model="form.fecha" type="date" class="campo h-11" /></Campo>
        <Campo etiqueta="Glosa" id="a-glosa" obligatorio :error="form.errors.glosa"><input id="a-glosa" v-model="form.glosa" class="campo h-11" maxlength="200" /></Campo>
    </div>

    <div class="tarjeta mb-6 overflow-x-auto">
        <table class="tabla">
            <thead><tr><th class="min-w-[260px]">Cuenta</th><th class="min-w-[170px]">Centro de costo</th><th class="min-w-[200px]">Detalle</th><th class="w-40 text-right">Debe</th><th class="w-40 text-right">Haber</th><th></th></tr></thead>
            <tbody>
                <tr v-for="(l, i) in form.lineas" :key="i">
                    <td>
                        <select v-model="l.cuenta_id" class="campo h-11" :aria-label="`Cuenta línea ${i + 1}`"><option :value="null">Elige una cuenta</option><option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select>
                        <p v-if="err(i, 'cuenta_id')" class="error">{{ err(i, 'cuenta_id') }}</p>
                    </td>
                    <td><select v-model="l.centro_costo_id" class="campo h-11" aria-label="Centro de costo"><option :value="null">—</option><option v-for="c in centros" :key="c.id" :value="c.id">{{ c.codigo }} · {{ c.nombre }}</option></select></td>
                    <td><input v-model="l.glosa" class="campo h-11" maxlength="200" aria-label="Detalle" /></td>
                    <td><input v-model.number="l.debe" type="number" min="0" class="campo num h-11 text-right" aria-label="Debe" @input="lado(l, 'debe')" /></td>
                    <td><input v-model.number="l.haber" type="number" min="0" class="campo num h-11 text-right" aria-label="Haber" @input="lado(l, 'haber')" /></td>
                    <td><button v-if="form.lineas.length > 2" class="btn btn-secundario btn-sm" aria-label="Quitar línea" @click="form.lineas.splice(i, 1)"><Icono nombre="cerrar" :tamano="16" /></button></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="font-semibold">
                    <td colspan="3"><button class="btn btn-texto" @click="form.lineas.push(vacia())"><Icono nombre="mas" :tamano="18" /> Agregar línea</button></td>
                    <td class="num text-right">{{ pesos(debe) }}</td>
                    <td class="num text-right">{{ pesos(haber) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p v-if="form.errors.lineas" class="error mb-4" role="alert">{{ form.errors.lineas }}</p>
    <div class="flex flex-wrap items-center justify-end gap-4">
        <span class="font-semibold" :class="diferencia === 0 && debe > 0 ? 'text-estado-ok' : 'text-estado-error'">{{ diferencia === 0 && debe > 0 ? 'Cuadrado' : `Diferencia ${pesos(Math.abs(diferencia))}` }}</span>
        <button class="btn btn-primario" :disabled="form.processing || diferencia !== 0 || debe === 0" @click="guardar">Contabilizar</button>
    </div>
</template>
