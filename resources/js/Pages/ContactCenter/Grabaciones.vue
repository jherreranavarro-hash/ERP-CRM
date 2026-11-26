<script setup lang="ts">
import { onBeforeUnmount, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Paginacion from '@/Components/Paginacion.vue';
import Icono from '@/Components/Icono.vue';
import type { Paginado } from '@/types';
import { fechaHora } from '@/lib/formato';
import { duracion } from '@/lib/documentos';
import { consulta, http } from '@/lib/http';
import { usePermisos } from '@/lib/permisos';

interface Fila { id: number; inicio: string; agente: string | null; campana: string | null; contacto: string | null; numero: string; direccion: string; duracion: number | null; resultado: string | null; puntaje: number | null }
const props = defineProps<{
    llamadas: Paginado<Fila>; accesos: { id: number; fecha: string; usuario: string | null; llamada_id: number; accion: string; motivo: string }[]; puedeEscuchar: boolean;
    agentes: { id: number; nombre: string }[]; campanas: { id: number; nombre: string }[]; resultados: Record<string, string>;
    filtrosActivos: { agente?: string; campana?: string; desde?: string; hasta?: string };
}>();
const puede = usePermisos();
const f = reactive({ agente: props.filtrosActivos.agente ?? '', campana: props.filtrosActivos.campana ?? '', desde: props.filtrosActivos.desde ?? '', hasta: props.filtrosActivos.hasta ?? '' });
const filtrar = () => router.get('/ventas/grabaciones', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true, replace: true });

// El audio se pide con motivo: el servidor registra el acceso y entrega un enlace de un solo uso.
const pidiendo = ref<{ fila: Fila; accion: 'escuchar' | 'descargar' } | null>(null);
const motivo = ref('');
const error = ref('');
const cargando = ref(false);
const audio = ref<{ id: number; url: string } | null>(null);
const liberar = () => { if (audio.value) URL.revokeObjectURL(audio.value.url); audio.value = null; };
onBeforeUnmount(liberar);
function pedir(fila: Fila, accion: 'escuchar' | 'descargar') { pidiendo.value = { fila, accion }; motivo.value = ''; error.value = ''; }
async function confirmar() {
    const p = pidiendo.value!;
    cargando.value = true;
    error.value = '';
    try {
        const { data } = await http.post<{ url: string }>(`/ventas/grabaciones/${p.fila.id}`, { motivo: motivo.value, accion: p.accion });
        const blob = (await http.get<Blob>(data.url, { responseType: 'blob' })).data;
        const url = URL.createObjectURL(blob);
        if (p.accion === 'descargar') {
            const a = document.createElement('a');
            a.href = url;
            a.download = `llamada-${p.fila.id}.wav`;
            a.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } else {
            liberar();
            audio.value = { id: p.fila.id, url };
        }
        pidiendo.value = null;
        router.reload({ only: ['accesos'] });
    } catch (e) {
        const r = (e as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }).response?.data;
        error.value = r?.errors ? Object.values(r.errors)[0][0] : r?.message ?? 'No se pudo obtener la grabación.';
    } finally {
        cargando.value = false;
    }
}
</script>

<template>
    <Head title="Grabaciones" />
    <Encabezado titulo="Grabaciones" bajada="Cada vez que alguien escucha o descarga una grabación queda registrado quién, cuándo y por qué.">
        <a v-if="puede('ventascrm.grabaciones.exportar')" :href="`/ventas/grabaciones?${consulta({ exportar: 1 })}`" class="btn btn-secundario no-underline"><Icono nombre="descargar" :tamano="18" /> Registro de accesos</a>
    </Encabezado>
    <p v-if="!puedeEscuchar" class="tarjeta mb-4 p-4 text-[14px] text-grafito">Puedes ver el listado, pero escuchar requiere un permiso específico que se otorga por persona.</p>

    <div v-if="audio" class="tarjeta mb-4 flex flex-wrap items-center gap-3 p-4">
        <span class="font-semibold">Llamada #{{ audio.id }}</span>
        <audio :src="audio.url" controls autoplay class="min-w-0 flex-1"></audio>
        <button class="btn btn-texto btn-sm" @click="liberar">Cerrar</button>
    </div>

    <form class="tarjeta mb-4 flex flex-wrap items-end gap-3 p-4" @submit.prevent="filtrar">
        <Campo etiqueta="Agente" id="fg-ag"><select id="fg-ag" v-model="f.agente" class="campo h-10 w-48"><option value="">Todos</option><option v-for="a in agentes" :key="a.id" :value="String(a.id)">{{ a.nombre }}</option></select></Campo>
        <Campo etiqueta="Campaña" id="fg-ca"><select id="fg-ca" v-model="f.campana" class="campo h-10 w-48"><option value="">Todas</option><option v-for="c in campanas" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option></select></Campo>
        <Campo etiqueta="Desde" id="fg-de"><input id="fg-de" v-model="f.desde" type="date" class="campo h-10" /></Campo>
        <Campo etiqueta="Hasta" id="fg-ha"><input id="fg-ha" v-model="f.hasta" type="date" class="campo h-10" /></Campo>
        <button class="btn btn-secundario">Filtrar</button>
    </form>

    <div class="tarjeta mb-6">
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Fecha</th><th>Agente</th><th>Contacto</th><th>Campaña</th><th class="text-right">Duración</th><th>Resultado</th><th></th></tr></thead>
                <tbody>
                    <tr v-for="l in llamadas.data" :key="l.id">
                        <td class="num text-[14px]">{{ fechaHora(l.inicio) }}</td>
                        <td>{{ l.agente ?? '—' }}</td>
                        <td>{{ l.contacto ?? '' }} <span class="num text-[13px] text-grafito">{{ l.numero }}</span></td>
                        <td>{{ l.campana ?? '—' }}</td>
                        <td class="num text-right">{{ duracion(l.duracion ?? 0) }}</td>
                        <td>{{ l.resultado ? resultados[l.resultado] : '—' }} <Chip v-if="l.puntaje !== null" chico tono="info">{{ l.puntaje }} pts</Chip></td>
                        <td class="whitespace-nowrap text-right">
                            <template v-if="puedeEscuchar">
                                <button class="btn btn-secundario btn-sm" @click="pedir(l, 'escuchar')">Escuchar</button>
                                <button class="btn btn-texto btn-sm" @click="pedir(l, 'descargar')">Descargar</button>
                            </template>
                        </td>
                    </tr>
                    <tr v-if="!llamadas.data.length"><td colspan="7" class="text-center text-grafito">Sin grabaciones en el filtro.</td></tr>
                </tbody>
            </table>
        </div>
        <Paginacion :datos="llamadas" nombre="grabaciones" />
    </div>

    <section class="tarjeta">
        <h2 class="border-b border-linea p-4 text-[18px] font-bold">Últimos accesos</h2>
        <div class="overflow-x-auto">
            <table class="tabla">
                <thead><tr><th>Fecha</th><th>Persona</th><th>Llamada</th><th>Acción</th><th>Motivo</th></tr></thead>
                <tbody>
                    <tr v-for="a in accesos" :key="a.id"><td class="num text-[14px]">{{ fechaHora(a.fecha) }}</td><td>{{ a.usuario }}</td><td class="num">#{{ a.llamada_id }}</td><td>{{ a.accion === 'descargar' ? 'Descarga' : 'Escucha' }}</td><td>{{ a.motivo }}</td></tr>
                    <tr v-if="!accesos.length"><td colspan="5" class="text-center text-grafito">Nadie ha accedido a grabaciones.</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <Modal :abierto="!!pidiendo" :titulo="pidiendo?.accion === 'descargar' ? 'Descargar grabación' : 'Escuchar grabación'" @cerrar="pidiendo = null">
        <p class="mb-3 text-[14px] text-grafito">El acceso queda registrado con tu nombre y el motivo.</p>
        <Campo etiqueta="Motivo" id="gr-mot" obligatorio :error="error"><input id="gr-mot" v-model="motivo" class="campo h-11" minlength="5" maxlength="200" placeholder="Ej.: reclamo del cliente N° 1234" /></Campo>
        <template #pie>
            <button class="btn btn-secundario" @click="pidiendo = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="cargando || motivo.trim().length < 5" @click="confirmar">Continuar</button>
        </template>
    </Modal>
</template>
