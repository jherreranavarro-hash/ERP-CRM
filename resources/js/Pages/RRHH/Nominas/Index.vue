<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import type { Paginado } from '@/types';
import { fecha, pesos } from '@/lib/formato';
import { estadoNomina } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Nomina { id: number; periodo: string; estado: string; personas: number; haberes: number; liquido: number; costo: number; generador: string | null; aprobador: string | null; fecha_pago: string | null }
const props = defineProps<{ nominas: Paginado<Nomina>; sugerido: string; estados: Record<string, string> }>();
const puede = usePermisos();
const mes = (p: string) => new Date(`${p}-15T12:00:00`).toLocaleDateString('es-CL', { month: 'long', year: 'numeric' });
const abierto = ref(false);
const form = useForm({ periodo: props.sugerido });
const generar = () => form.post('/rrhh/nominas', { onSuccess: () => { abierto.value = false; } });
</script>

<template>
    <Head title="Nómina" />
    <Encabezado titulo="Nómina y liquidaciones" bajada="Remuneraciones mensuales calculadas con los parámetros vigentes (UF, UTM, AFP, salud, impuesto único). La aprueba una persona distinta de quien la calcula y queda contabilizada.">
        <Link v-if="puede('rrhh.parametros-de-nomina.ver')" href="/rrhh/parametros" class="btn btn-secundario no-underline">Parámetros</Link>
        <button v-if="puede('rrhh.nomina-y-liquidaciones.crear')" class="btn btn-primario" @click="abierto = true"><Icono nombre="mas" :tamano="18" /> Calcular nómina</button>
    </Encabezado>

    <div v-if="nominas.data.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Período</th><th>Estado</th><th class="text-right">Personas</th><th class="text-right">Haberes</th><th class="text-right">Líquido</th><th class="text-right">Costo empresa</th><th>Calculó / aprobó</th><th>Pago</th></tr></thead>
            <tbody>
                <tr v-for="n in nominas.data" :key="n.id">
                    <td><Link :href="`/rrhh/nominas/${n.id}`" class="font-semibold capitalize">{{ mes(n.periodo) }}</Link></td>
                    <td><Chip chico :tono="estadoNomina[n.estado][0]">{{ estadoNomina[n.estado][1] }}</Chip></td>
                    <td class="num text-right">{{ n.personas }}</td>
                    <td class="num text-right">{{ pesos(n.haberes) }}</td>
                    <td class="num text-right font-semibold">{{ pesos(n.liquido) }}</td>
                    <td class="num text-right">{{ pesos(n.costo) }}</td>
                    <td class="text-[14px] text-grafito">{{ n.generador }}<span v-if="n.aprobador"> / {{ n.aprobador }}</span></td>
                    <td class="num">{{ n.fecha_pago ? fecha(n.fecha_pago) : '—' }}</td>
                </tr>
            </tbody>
        </table>
        <Paginacion :datos="nominas" nombre="nóminas" />
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin nóminas" texto="Calcula la primera nómina del mes: toma contratos vigentes, asistencia, ausencias, comisiones, préstamos y anticipos." />

    <Modal :abierto="abierto" titulo="Calcular nómina" @cerrar="abierto = false">
        <p class="mb-4 text-grafito">Si el período ya tiene una nómina en borrador, se recalcula conservando los bonos ingresados.</p>
        <Campo etiqueta="Período" id="n-periodo" obligatorio :error="form.errors.periodo"><input id="n-periodo" v-model="form.periodo" type="month" class="campo h-11" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing || !form.periodo" @click="generar">Calcular</button>
        </template>
    </Modal>
</template>
