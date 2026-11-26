<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import type { Paginado } from '@/types';
import { fecha, pesos, rut } from '@/lib/formato';
import { consulta } from '@/lib/http';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; rut: string; nombre: string; facturado: number; notas_credito: number; saldo: number; vencido: number; cupo: number | null; ultima: string | null }
const props = defineProps<{ clientes: Paginado<Fila>; filtros: { buscar?: string; filtro?: string } }>();
const puede = usePermisos();
const f = reactive({ buscar: props.filtros.buscar ?? '', filtro: props.filtros.filtro ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get('/ventas/cuentas-corrientes', { buscar: f.buscar || undefined, filtro: f.filtro || undefined }, { preserveState: true, replace: true }), 300);
});
const exportar = () => `/ventas/cuentas-corrientes?${consulta({ ...f, exportar: 1 })}`;
</script>

<template>
    <Head title="Cuentas corrientes" />
    <Encabezado titulo="Cuentas corrientes por cliente" bajada="Lo facturado, las notas de crédito, los pagos y el saldo de cada cliente, con lo vencido y el cupo de crédito.">
        <a v-if="puede('ventascrm.cuentas-corrientes.exportar') || puede('configuracion.cuentas-corrientes.exportar')" :href="exportar()" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
    </Encabezado>

    <div class="tarjeta mb-6 flex flex-wrap items-center gap-3 p-4">
        <input v-model="f.buscar" type="search" class="campo h-10 sm:w-80" placeholder="Buscar por nombre o RUT" aria-label="Buscar" />
        <div class="flex gap-2 sm:ml-auto" role="group" aria-label="Filtro">
            <button class="btn btn-sm" :class="!f.filtro ? 'btn-oscuro' : 'btn-secundario'" @click="f.filtro = ''">Todos</button>
            <button class="btn btn-sm" :class="f.filtro === 'con_saldo' ? 'btn-oscuro' : 'btn-secundario'" @click="f.filtro = 'con_saldo'">Con saldo</button>
        </div>
    </div>

    <div v-if="clientes.data.length" class="tarjeta overflow-x-auto">
        <table class="tabla">
            <thead><tr><th>Cliente</th><th class="text-right">Facturado</th><th class="text-right">Notas de crédito</th><th class="text-right">Saldo</th><th class="text-right">Cupo</th><th>Última compra</th></tr></thead>
            <tbody>
                <tr v-for="c in clientes.data" :key="c.id">
                    <td><Link :href="`/ventas/cuentas-corrientes/${c.id}`" class="font-semibold">{{ c.nombre }}</Link><p class="num text-[13px] text-grafito">{{ rut(c.rut) }}</p></td>
                    <td class="num text-right">{{ pesos(c.facturado) }}</td>
                    <td class="num text-right">{{ c.notas_credito ? pesos(c.notas_credito) : '—' }}</td>
                    <td class="num text-right font-semibold">{{ pesos(c.saldo) }}<Chip v-if="c.vencido" chico tono="error" class="ml-2">Vencido {{ pesos(c.vencido) }}</Chip></td>
                    <td class="num text-right">
                        <template v-if="c.cupo !== null">{{ pesos(c.cupo) }}<span class="block text-[13px]" :class="c.saldo > c.cupo ? 'font-semibold text-estado-error' : 'text-grafito'">{{ c.saldo > c.cupo ? 'Excedido' : `Disponible ${pesos(c.cupo - c.saldo)}` }}</span></template>
                        <span v-else class="text-grafito">Contado</span>
                    </td>
                    <td class="num">{{ c.ultima ? fecha(c.ultima) : '—' }}</td>
                </tr>
            </tbody>
        </table>
        <Paginacion :datos="clientes" nombre="clientes" />
    </div>
    <Vacio v-else class="tarjeta" titulo="Sin cuentas corrientes" texto="Aparecen aquí los clientes con documentos emitidos a su nombre." />
</template>
