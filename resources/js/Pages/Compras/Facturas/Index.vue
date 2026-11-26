<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, pesos } from '@/lib/formato';
import { estadoFacturaProveedor } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; nombre: string; proveedor: string | null; rut: string | null; emision: string; vencimiento: string; oc: number | null; total: number; saldo: number; estado: string }
const props = defineProps<{ facturas: Paginado<Fila>; filtros: Record<string, string | undefined>; estados: Record<string, string>; porRevisar: number }>();
const puede = usePermisos();

const f = reactive({ buscar: props.filtros.buscar ?? '', estado: props.filtros.estado ?? '' });
let t: ReturnType<typeof setTimeout>;
const limpio = () => Object.fromEntries(Object.entries(f).filter(([, v]) => v));
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/compras/facturas', limpio(), { preserveState: true, replace: true }), 300); });
const exportar = () => (window.location.href = `/compras/facturas?${new URLSearchParams({ ...limpio(), exportar: '1' })}`);
</script>

<template>
    <Head title="Facturas de proveedores" />
    <Encabezado titulo="Facturas de proveedores" bajada="Se cruzan con la orden de compra y lo recibido. Si calzan pasan a pago; si no, las revisa otra persona.">
        <button v-if="puede('compras.factura-de-proveedores.exportar')" class="btn btn-secundario" @click="exportar"><Icono nombre="descargar" :tamano="18" /> Exportar</button>
        <Link v-if="puede('compras.factura-de-proveedores.crear')" href="/compras/facturas/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Registrar factura</Link>
    </Encabezado>

    <button v-if="porRevisar" class="tarjeta mb-6 block w-full border-[#F0D58A] bg-[#FFF3D6] p-4 text-left text-[#8A5A00]" @click="f.estado = 'por_revisar'">
        {{ porRevisar === 1 ? 'Hay 1 factura con diferencias' : `Hay ${porRevisar} facturas con diferencias` }} esperando revisión.
    </button>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <div class="relative sm:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="Folio o proveedor" aria-label="Buscar" />
            </div>
            <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todos los estados</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
        </div>
        <div class="overflow-x-auto">
            <table v-if="facturas.data.length" class="tabla">
                <thead><tr><th>Documento</th><th>Proveedor</th><th>Emisión</th><th>Vence</th><th>OC</th><th class="text-right">Total</th><th class="text-right">Saldo</th><th>Estado</th></tr></thead>
                <tbody>
                    <tr v-for="x in facturas.data" :key="x.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/compras/facturas/${x.id}`)">
                        <td><Link :href="`/compras/facturas/${x.id}`" class="font-semibold">{{ x.nombre }}</Link></td>
                        <td>{{ x.proveedor }}</td>
                        <td class="num whitespace-nowrap">{{ fecha(x.emision) }}</td>
                        <td class="num whitespace-nowrap">{{ fecha(x.vencimiento) }}</td>
                        <td class="num">{{ x.oc ? `N° ${x.oc}` : '—' }}</td>
                        <td class="num text-right">{{ pesos(x.total) }}</td>
                        <td class="num text-right font-semibold" :class="x.saldo && x.estado === 'por_pagar' ? 'text-naranja-profundo' : 'text-grafito'">{{ pesos(x.saldo) }}</td>
                        <td><Chip chico :tono="estadoFacturaProveedor[x.estado][0]">{{ estadoFacturaProveedor[x.estado][1] }}</Chip></td>
                    </tr>
                </tbody>
            </table>
            <Vacio v-else titulo="Sin facturas de proveedor" texto="Registra la primera o ajusta los filtros." />
        </div>
        <Paginacion :datos="facturas" nombre="facturas" />
    </div>
</template>
