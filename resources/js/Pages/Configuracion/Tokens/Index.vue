<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Vacio from '@/Components/Vacio.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, haceCuanto } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Token { id: number; nombre: string; scopes: string[]; vence: string | null; ultimo_uso: string | null; ips: string[]; limite: number | null; revocado: boolean; vencido: boolean }
const props = defineProps<{ tokens: Token[]; scopes: Record<string, string>; maxDias: number; tokenNuevo: string | null }>();
const puede = usePermisos();

const abierto = ref(false);
const form = useForm({ nombre: '', scopes: [] as string[], dias: Math.min(90, props.maxDias), ips: [] as string[], limite: null as number | null });
const ipsTexto = ref('');
function crear() {
    form.transform((d) => ({ ...d, ips: ipsTexto.value.split(/[\n,]/).map((s) => s.trim()).filter(Boolean) }))
        .post('/configuracion/tokens', { preserveScroll: true, onSuccess: () => { abierto.value = false; form.reset(); ipsTexto.value = ''; } });
}
function revocar(t: Token) {
    if (confirm(`¿Revocar el token «${t.nombre}»? Las integraciones que lo usan dejarán de funcionar.`)) router.delete(`/configuracion/tokens/${t.id}`, { preserveScroll: true });
}
const copiado = ref(false);
async function copiar() {
    await navigator.clipboard.writeText(props.tokenNuevo ?? '');
    copiado.value = true;
}
</script>

<template>
    <Head title="Tokens de API" />
    <Encabezado titulo="Tokens de API" bajada="Para integraciones externas. La API nunca acepta la sesión del navegador: solo tokens con scope, vencimiento y, si quieres, IP permitidas.">
        <button v-if="puede('configuracion.tokens-api.crear')" class="btn btn-primario" @click="abierto = true"><Icono nombre="mas" :tamano="18" /> Nuevo token</button>
    </Encabezado>

    <div v-if="tokenNuevo" class="tarjeta mb-6 border-naranja p-5">
        <p class="font-bold">Copia el token ahora: no se volverá a mostrar.</p>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <code class="flex-1 break-all rounded-boton bg-carbon px-4 py-3 font-mono text-[14px] text-hormigon">{{ tokenNuevo }}</code>
            <button class="btn btn-oscuro" @click="copiar">{{ copiado ? 'Copiado' : 'Copiar' }}</button>
        </div>
    </div>

    <div class="tarjeta overflow-x-auto">
        <table v-if="tokens.length" class="tabla">
            <thead><tr><th>Nombre</th><th>Scopes</th><th>Vence</th><th>Último uso</th><th>Estado</th><th class="w-px"></th></tr></thead>
            <tbody>
                <tr v-for="t in tokens" :key="t.id">
                    <td><div class="font-semibold">{{ t.nombre }}</div><div v-if="t.ips.length" class="num text-[13px] text-grafito">IP: {{ t.ips.join(', ') }}</div></td>
                    <td><div class="flex flex-wrap gap-1"><Chip v-for="s in t.scopes" :key="s" chico>{{ s }}</Chip></div></td>
                    <td class="num">{{ fecha(t.vence) }}</td>
                    <td class="text-grafito">{{ haceCuanto(t.ultimo_uso) }}</td>
                    <td><Chip chico :tono="t.revocado || t.vencido ? 'error' : 'ok'">{{ t.revocado ? 'Revocado' : t.vencido ? 'Vencido' : 'Vigente' }}</Chip></td>
                    <td class="text-right"><button v-if="!t.revocado && !t.vencido && puede('configuracion.tokens-api.eliminar')" class="btn btn-peligro btn-sm" @click="revocar(t)">Revocar</button></td>
                </tr>
            </tbody>
        </table>
        <Vacio v-else titulo="Sin tokens" texto="Crea un token por cada sistema externo que necesite leer o escribir datos." />
    </div>

    <Modal :abierto="abierto" titulo="Nuevo token de API" ancho="lg" @cerrar="abierto = false">
        <form id="form-token" class="space-y-5" @submit.prevent="crear">
            <Campo etiqueta="Nombre" id="nombre" :error="form.errors.nombre" obligatorio ayuda="Qué sistema lo usará, por ejemplo «BI de gerencia».">
                <input id="nombre" v-model="form.nombre" class="campo h-11" />
            </Campo>
            <Campo etiqueta="Scopes" id="scopes" :error="form.errors.scopes" obligatorio>
                <div class="grid gap-2">
                    <label v-for="(desc, s) in scopes" :key="s" class="flex items-center gap-3">
                        <input v-model="form.scopes" type="checkbox" :value="s" class="h-5 w-5 rounded border-linea-fuerte text-carbon focus:ring-naranja" />
                        <span><span class="font-mono text-[14px] font-semibold">{{ s }}</span> · {{ desc }}</span>
                    </label>
                </div>
            </Campo>
            <div class="grid gap-5 sm:grid-cols-2">
                <Campo etiqueta="Vence en (días)" id="dias" :error="form.errors.dias" :ayuda="`Máximo ${maxDias} días.`">
                    <input id="dias" v-model.number="form.dias" type="number" min="1" :max="maxDias" class="campo num h-11" />
                </Campo>
                <Campo etiqueta="Solicitudes por minuto" id="limite" :error="form.errors.limite" ayuda="Vacío = límite general.">
                    <input id="limite" v-model.number="form.limite" type="number" min="1" class="campo num h-11" />
                </Campo>
            </div>
            <Campo etiqueta="IP permitidas" id="ips" :error="form.errors.ips" ayuda="Una por línea; acepta rangos (ej. 200.10.0.0/24). Vacío = cualquier IP.">
                <textarea id="ips" v-model="ipsTexto" rows="3" class="campo num" />
            </Campo>
        </form>
        <template #pie>
            <button class="btn btn-secundario" @click="abierto = false">Cancelar</button>
            <button type="submit" form="form-token" class="btn btn-primario" :disabled="form.processing">Crear token</button>
        </template>
    </Modal>
</template>
