<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import type { PageProps } from '@/types';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Icono from '@/Components/Icono.vue';
import { fechaHora } from '@/lib/formato';
import { mostrarValor } from './valor';
import { usePermisos } from '@/lib/permisos';

interface Mant { clave: string; nombre: string; sensible: boolean; edita: string; piso: string; parametros: number; pendientes: number; entidad: string | null }
interface Cambio { id: number; etiqueta: string; mantenedor: string; mantenedor_clave: string; valor: unknown; unidad: string | null; alcance: string; motivo: string; propuesto_por: string; vigente_desde: string; fecha: string }
defineProps<{ grupos: { nombre: string; mantenedores: Mant[] }[]; totalMantenedores: number; porAprobar: Cambio[] }>();
const puede = usePermisos();
const page = usePage<PageProps>();

const resolviendo = ref<{ cambio: Cambio; aprobar: boolean } | null>(null);
const resolucion = useForm({ comentario: '' });
function resolver() {
    const r = resolviendo.value!;
    resolucion.post(`/configuracion/parametros/cambios/${r.cambio.id}/${r.aprobar ? 'aprobar' : 'rechazar'}`, { preserveScroll: true, onSuccess: () => { resolviendo.value = null; resolucion.reset(); } });
}

const importarAbierto = ref(false);
const importar = useForm<{ archivo: File | null; motivo: string }>({ archivo: null, motivo: '' });
</script>

<template>
    <Head title="Parámetros del sistema" />
    <Encabezado titulo="Parámetros del sistema" :bajada="`${totalMantenedores} mantenedores para ajustar seguridad, auditoría, integraciones, negocio y operación sin desplegar código. Ningún valor puede bajar de su piso de seguridad.`">
        <a v-if="puede('configuracion.parametros.exportar')" href="/configuracion/parametros/exportar" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Exportar</a>
        <button v-if="page.props.auth.user?.es_superadmin" class="btn btn-secundario" @click="importarAbierto = true"><Icono nombre="subir" :tamano="18" /> Importar</button>
    </Encabezado>

    <section v-if="porAprobar.length && puede('configuracion.parametros.aprobar')" class="tarjeta mb-8 border-naranja">
        <div class="flex items-center gap-3 px-5 pb-2 pt-4">
            <h2 class="titulo-ancho text-[18px] font-bold">Por aprobar</h2>
            <Chip chico tono="alerta">{{ porAprobar.length }}</Chip>
        </div>
        <p class="px-5 pb-3 text-[14px] text-grafito">Cambios en mantenedores sensibles propuestos por otra persona. Nadie aprueba su propio cambio.</p>
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Parámetro</th><th>Valor propuesto</th><th>Alcance</th><th>Propuso</th><th>Motivo</th><th class="w-px"></th></tr></thead>
                <tbody>
                    <tr v-for="c in porAprobar" :key="c.id">
                        <td><div class="font-semibold">{{ c.etiqueta }}</div><Link :href="`/configuracion/parametros/${c.mantenedor_clave}`" class="text-[14px]">{{ c.mantenedor }}</Link></td>
                        <td class="num font-semibold">{{ mostrarValor(c.valor, c.unidad) }}</td>
                        <td class="capitalize">{{ c.alcance }}</td>
                        <td><div>{{ c.propuesto_por }}</div><div class="num text-[13px] text-grafito">{{ fechaHora(c.fecha) }}</div></td>
                        <td class="max-w-xs text-[14px]">{{ c.motivo }}</td>
                        <td class="whitespace-nowrap text-right">
                            <div class="flex justify-end gap-2">
                                <button class="btn btn-primario btn-sm" @click="resolviendo = { cambio: c, aprobar: true }">Aprobar</button>
                                <button class="btn btn-peligro btn-sm" @click="resolviendo = { cambio: c, aprobar: false }">Rechazar</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="space-y-8">
        <section v-for="g in grupos" :key="g.nombre">
            <h2 class="titulo-ancho mb-3 text-[18px] font-bold">{{ g.nombre }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Link v-for="m in g.mantenedores" :key="m.clave" :href="`/configuracion/parametros/${m.clave}`" class="tarjeta flex flex-col p-5 text-carbon no-underline transition-colors hover:border-naranja">
                    <div class="flex items-start justify-between gap-3">
                        <span class="text-[18px] font-bold leading-snug">{{ m.nombre }}</span>
                        <Chip v-if="m.pendientes" chico tono="alerta">{{ m.pendientes }} por aprobar</Chip>
                    </div>
                    <p class="mt-2 flex-1 text-[14px] leading-relaxed text-carbon-3"><span class="font-semibold">Piso:</span> {{ m.piso }}</p>
                    <div class="mt-4 flex flex-wrap items-center gap-2 text-[13px] text-grafito">
                        <Chip v-if="m.sensible" chico tono="info">Doble aprobación</Chip>
                        <span>{{ m.parametros }} parámetros · edita {{ m.edita }}</span>
                    </div>
                </Link>
            </div>
        </section>
    </div>

    <Modal :abierto="!!resolviendo" :titulo="resolviendo?.aprobar ? 'Aprobar cambio' : 'Rechazar cambio'" @cerrar="resolviendo = null">
        <template v-if="resolviendo">
            <p class="mb-4"><span class="font-semibold">{{ resolviendo.cambio.etiqueta }}</span> pasa a <span class="num font-semibold">{{ mostrarValor(resolviendo.cambio.valor, resolviendo.cambio.unidad) }}</span> desde {{ fechaHora(resolviendo.cambio.vigente_desde) }}.</p>
            <Campo :etiqueta="resolviendo.aprobar ? 'Comentario (opcional)' : 'Motivo del rechazo'" id="comentario" :error="resolucion.errors.comentario || (resolucion.errors as Record<string, string | undefined>).cambio">
                <textarea id="comentario" v-model="resolucion.comentario" class="campo" rows="3" />
            </Campo>
        </template>
        <template #pie>
            <button class="btn btn-secundario" @click="resolviendo = null">Cancelar</button>
            <button :class="resolviendo?.aprobar ? 'btn btn-primario' : 'btn btn-peligro'" :disabled="resolucion.processing" @click="resolver">{{ resolviendo?.aprobar ? 'Aprobar' : 'Rechazar' }}</button>
        </template>
    </Modal>

    <Modal :abierto="importarAbierto" titulo="Importar configuración" @cerrar="importarAbierto = false">
        <form id="form-importar" class="space-y-4" @submit.prevent="importar.post('/configuracion/parametros/importar', { forceFormData: true, onSuccess: () => (importarAbierto = false) })">
            <p class="text-[15px] text-grafito">Sube un JSON exportado desde otro ambiente. Solo se acepta si su firma es válida, y cada cambio pasa por las mismas validaciones y aprobaciones.</p>
            <Campo etiqueta="Archivo JSON" id="archivo" :error="importar.errors.archivo">
                <input id="archivo" type="file" accept="application/json" class="block w-full text-[15px]" @change="importar.archivo = ($event.target as HTMLInputElement).files?.[0] ?? null" />
            </Campo>
            <Campo etiqueta="Motivo" id="motivo_imp" :error="importar.errors.motivo"><input id="motivo_imp" v-model="importar.motivo" class="campo h-11" /></Campo>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="importarAbierto = false">Cancelar</button>
            <button type="submit" form="form-importar" class="btn btn-primario" :disabled="importar.processing">Importar</button>
        </template>
    </Modal>
</template>
