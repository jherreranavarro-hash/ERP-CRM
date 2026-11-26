<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Kpi from '@/Components/Kpi.vue';
import Chip from '@/Components/Chip.vue';
import { fechaHora, numero } from '@/lib/formato';

const props = defineProps<{
    resumen: { locales: number; usuarios: number; por_aprobar: number | null };
    actividad: { id: number; accion: string; usuario: string | null; fecha: string }[];
}>();

const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user!);
const saludo = computed(() => {
    const h = Number(new Date().toLocaleString('es-CL', { timeZone: 'America/Santiago', hour: 'numeric', hour12: false }));
    return h < 12 ? 'Buenos días' : h < 20 ? 'Buenas tardes' : 'Buenas noches';
});

// Accesos rápidos: los submódulos ya disponibles para este usuario.
const accesos = computed(() => page.props.menu.flatMap((m) => m.items.filter((i) => i.disponible).map((i) => ({ ...i, modulo: m.nombre }))));
const totalSub = computed(() => page.props.menu.reduce((n, m) => n + m.items.length, 0));

const nombresAccion: Record<string, string> = {
    'login.exitoso': 'Ingresó al sistema',
    'login.fallido': 'Intento de ingreso fallido',
    logout: 'Cerró sesión',
    crear: 'Creó un registro',
    editar: 'Editó un registro',
    eliminar: 'Eliminó un registro',
    'parametro.cambiar': 'Cambió un parámetro',
    'parametro.proponer': 'Propuso un cambio de parámetro',
    'parametro.aprobar': 'Aprobó un cambio de parámetro',
    'invitacion.enviada': 'Invitó a un usuario',
    'rol.permisos': 'Cambió permisos de un rol',
};
</script>

<template>
    <Head title="Inicio" />
    <Encabezado :titulo="`${saludo}, ${user.nombre.split(' ')[0]}`" :bajada="page.props.empresa ? `Estás trabajando en ${page.props.empresa.actual.nombre}.` : undefined" />

    <div class="grid gap-6 xl:grid-cols-12">
        <section class="tarjeta p-6 xl:col-span-7">
            <h2 class="titulo-ancho mb-4 text-[18px] font-bold">Resumen</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <Kpi etiqueta="Locales activos" :valor="numero(resumen.locales)" />
                <Kpi etiqueta="Usuarios en la empresa" :valor="numero(resumen.usuarios)" />
                <Kpi v-if="resumen.por_aprobar !== null" etiqueta="Cambios por aprobar" :valor="numero(resumen.por_aprobar)" :tono="resumen.por_aprobar ? 'naranja' : 'normal'">
                    <Link v-if="resumen.por_aprobar" href="/configuracion/parametros">Revisar</Link>
                </Kpi>
            </div>
            <div class="mt-6 rounded-boton border border-dashed border-linea-fuerte p-4 text-[15px] text-grafito">
                Los indicadores comerciales (venta del día, margen, conversión) llegan con facturación y ventas en las fases 2 y 3.
                Hoy tienes {{ accesos.length }} de {{ totalSub }} pantallas disponibles.
            </div>
        </section>

        <section class="tarjeta p-6 xl:col-span-5">
            <h2 class="titulo-ancho mb-4 text-[18px] font-bold">Accesos rápidos</h2>
            <div v-if="accesos.length" class="grid gap-2 sm:grid-cols-2">
                <Link v-for="a in accesos" :key="a.href" :href="a.href" class="rounded-boton border border-linea px-4 py-3 text-carbon no-underline hover:border-naranja hover:bg-hormigon">
                    <span class="block text-[13px] text-grafito">{{ a.modulo }}</span>
                    <span class="font-semibold">{{ a.nombre }}</span>
                </Link>
            </div>
            <p v-else class="text-grafito">Tu rol aún no tiene pantallas disponibles en esta fase.</p>
        </section>

        <section v-if="actividad.length" class="tarjeta xl:col-span-12">
            <div class="flex items-center justify-between px-6 pb-2 pt-5">
                <h2 class="titulo-ancho text-[18px] font-bold">Actividad reciente</h2>
                <Link href="/configuracion/auditoria" class="text-[15px] font-semibold">Ver toda la trazabilidad</Link>
            </div>
            <table class="tabla">
                <tbody>
                    <tr v-for="a in actividad" :key="a.id">
                        <td class="w-48 num text-grafito">{{ fechaHora(a.fecha) }}</td>
                        <td class="font-semibold">{{ a.usuario ?? 'Sistema' }}</td>
                        <td>{{ nombresAccion[a.accion] ?? a.accion }}</td>
                        <td class="text-right"><Chip chico :tono="a.accion.includes('fallido') ? 'error' : 'neutro'">{{ a.accion }}</Chip></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
