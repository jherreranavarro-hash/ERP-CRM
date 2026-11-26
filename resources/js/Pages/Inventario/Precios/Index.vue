<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import type { Paginado } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora, pesos } from '@/lib/formato';
import { estadoCambioPrecio } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Solicitud { id: number; numero: number; fecha: string; descripcion: string; lineas: number; estado: string; solicitante: string | null; aprobador: string | null; motivos: string[] }
interface Historia { id: number; fecha: string; sku: string; producto: string; producto_id: number; campo: string; anterior: number | null; nuevo: number | null; origen: string; cambio_precio_id: number | null; usuario: string | null }
const props = defineProps<{ vista: 'solicitudes' | 'historial'; solicitudes: Paginado<Solicitud> | null; historial: Paginado<Historia> | null; filtros: Record<string, string | undefined>; estados: Record<string, string>; umbral: number; pendientes: number }>();
const puede = usePermisos();

const f = reactive({ vista: props.vista, estado: props.filtros.estado ?? '', buscar: props.filtros.buscar ?? '' });
let t: ReturnType<typeof setTimeout>;
watch(f, () => { clearTimeout(t); t = setTimeout(() => router.get('/inventario/precios', Object.fromEntries(Object.entries(f).filter(([k, v]) => v && !(k === 'vista' && v === 'solicitudes'))), { preserveState: true, replace: true }), 300); });
const variacion = (a: number | null, n: number | null) => (a && n !== null ? ((n - a) / a) * 100 : null);
</script>

<template>
    <Head title="Actualización de precios" />
    <Encabezado titulo="Actualización de precios" :bajada="`Cambios de precio de venta con historial. Sobre ${umbral.toLocaleString('es-CL')} % de variación, o bajo el costo, los aprueba otra persona.`">
        <Link v-if="puede('inventario.actualizacion-de-precios.crear')" href="/inventario/precios/crear" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Nuevo cambio</Link>
    </Encabezado>

    <button v-if="pendientes && vista === 'solicitudes'" class="tarjeta mb-6 block w-full border-[#F0D58A] bg-[#FFF3D6] p-4 text-left text-[#8A5A00]" @click="f.estado = 'por_aprobar'">
        {{ pendientes === 1 ? 'Hay 1 cambio de precios' : `Hay ${pendientes} cambios de precios` }} esperando aprobación.
    </button>

    <div class="mb-4 flex gap-2" role="tablist">
        <button role="tab" :aria-selected="f.vista === 'solicitudes'" class="btn" :class="f.vista === 'solicitudes' ? 'btn-primario' : 'btn-secundario'" @click="f.vista = 'solicitudes'">Solicitudes</button>
        <button role="tab" :aria-selected="f.vista === 'historial'" class="btn" :class="f.vista === 'historial' ? 'btn-primario' : 'btn-secundario'" @click="f.vista = 'historial'">Historial por producto</button>
    </div>

    <div class="tarjeta">
        <div class="grid gap-3 border-b border-linea p-4 sm:grid-cols-3">
            <template v-if="vista === 'solicitudes'">
                <select v-model="f.estado" class="campo h-11" aria-label="Estado"><option value="">Todos los estados</option><option v-for="(n, k) in estados" :key="k" :value="k">{{ n }}</option></select>
            </template>
            <div v-else class="relative sm:col-span-2">
                <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-grafito" />
                <input v-model="f.buscar" class="campo h-11 pl-10" placeholder="SKU o producto" aria-label="Buscar" />
            </div>
        </div>
        <div class="overflow-x-auto">
            <template v-if="vista === 'solicitudes' && solicitudes">
                <table v-if="solicitudes.data.length" class="tabla">
                    <thead><tr><th>N°</th><th>Fecha</th><th>Descripción</th><th class="text-right">Cambios</th><th>Solicitó</th><th>Estado</th></tr></thead>
                    <tbody>
                        <tr v-for="s in solicitudes.data" :key="s.id" class="cursor-pointer hover:bg-hormigon" @click="router.visit(`/inventario/precios/${s.id}`)">
                            <td><Link :href="`/inventario/precios/${s.id}`" class="num font-semibold">{{ s.numero }}</Link></td>
                            <td class="num whitespace-nowrap">{{ fechaHora(s.fecha) }}</td>
                            <td>{{ s.descripcion }}<div v-if="s.estado === 'por_aprobar'" class="text-[13px] text-[#8A5A00]">{{ s.motivos.join(' · ') }}</div></td>
                            <td class="num text-right">{{ s.lineas }}</td>
                            <td>{{ s.solicitante }}<div v-if="s.aprobador" class="text-[13px] text-grafito">Revisó {{ s.aprobador }}</div></td>
                            <td><Chip chico :tono="estadoCambioPrecio[s.estado][0]">{{ estadoCambioPrecio[s.estado][1] }}</Chip></td>
                        </tr>
                    </tbody>
                </table>
                <Vacio v-else titulo="Sin cambios de precio" texto="Crea un cambio individual o un ajuste por categoría." />
                <Paginacion :datos="solicitudes" nombre="solicitudes" />
            </template>
            <template v-else-if="historial">
                <table v-if="historial.data.length" class="tabla">
                    <thead><tr><th>Fecha</th><th>Producto</th><th>Campo</th><th class="text-right">Antes</th><th class="text-right">Después</th><th class="text-right">Variación</th><th>Origen</th><th>Usuario</th></tr></thead>
                    <tbody>
                        <tr v-for="h in historial.data" :key="h.id">
                            <td class="num whitespace-nowrap">{{ fechaHora(h.fecha) }}</td>
                            <td><div class="font-semibold">{{ h.producto }}</div><div class="num text-[13px] text-grafito">{{ h.sku }}</div></td>
                            <td>{{ h.campo }}</td>
                            <td class="num text-right">{{ h.anterior === null ? '—' : pesos(h.anterior) }}</td>
                            <td class="num text-right font-semibold">{{ h.nuevo === null ? '—' : pesos(h.nuevo) }}</td>
                            <td class="num text-right" :class="(variacion(h.anterior, h.nuevo) ?? 0) < 0 ? 'text-estado-error' : 'text-estado-ok'">
                                {{ variacion(h.anterior, h.nuevo) === null ? '—' : `${variacion(h.anterior, h.nuevo)!.toLocaleString('es-CL', { maximumFractionDigits: 1, signDisplay: 'always' })} %` }}
                            </td>
                            <td><Link v-if="h.cambio_precio_id" :href="`/inventario/precios/${h.cambio_precio_id}`">{{ h.origen }}</Link><span v-else>{{ h.origen }}</span></td>
                            <td>{{ h.usuario ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
                <Vacio v-else titulo="Sin historial" texto="Aún no hay cambios de precio o costo." />
                <Paginacion :datos="historial" nombre="cambios" />
            </template>
        </div>
    </div>
</template>
