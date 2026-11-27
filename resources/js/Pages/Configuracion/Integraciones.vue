<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import { fechaHora } from '@/lib/formato';
import { usePermisos } from '@/lib/permisos';

interface Ficha { tipo: string; nombre: string; proposito: string; simulada: boolean; activa: boolean; ambiente: string; url: string | null; identificador: string | null; tiene_secreto: boolean;
    rotado: string | null; rotar: boolean; reintentos: number; timeout_seg: number; salud: string | null; salud_at: string | null }
defineProps<{ integraciones: Ficha[]; rotacionDias: number; webhooks: Record<string, string> }>();
const puede = usePermisos();
const editando = ref<Ficha | null>(null);
const form = useForm({ activa: false, ambiente: 'certificacion', url: '', identificador: '', secreto: '', reintentos: 5, timeout_seg: 15 });
function abrir(f: Ficha) {
    editando.value = f;
    form.clearErrors();
    Object.assign(form, { activa: f.activa, ambiente: f.ambiente, url: f.url ?? '', identificador: f.identificador ?? '', secreto: '', reintentos: f.reintentos, timeout_seg: f.timeout_seg });
}
const guardar = () => form.transform((d) => ({ ...d, url: d.url || null, identificador: d.identificador || null, secreto: d.secreto || null }))
    .put(`/configuracion/integraciones/${editando.value!.tipo}`, { preserveScroll: true, onSuccess: () => { editando.value = null; form.secreto = ''; } });
const probar = (f: Ficha) => router.post(`/configuracion/integraciones/${f.tipo}/probar`, {}, { preserveScroll: true });
const IDENTIFICADOR: Record<string, string> = { whatsapp: 'ID del número (phone_number_id)', meta_ads: 'ID de la cuenta publicitaria', linkedin: 'ID de la organización', dte: 'RUT del emisor', correo: 'Dominio remitente' };
</script>

<template>
    <Head title="Integraciones" />
    <Encabezado titulo="Integraciones" :bajada="`Una ficha por servicio externo. Los secretos se guardan cifrados y nunca se vuelven a mostrar: solo se reemplazan. Se avisa cuando un secreto cumple ${rotacionDias} días.`" />

    <div class="grid gap-4 lg:grid-cols-2">
        <article v-for="f in integraciones" :key="f.tipo" class="tarjeta p-5">
            <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
                <div>
                    <h2 class="text-[18px] font-bold">{{ f.nombre }}</h2>
                    <p class="text-[14px] text-grafito">{{ f.proposito }}</p>
                </div>
                <div class="flex flex-wrap gap-1">
                    <Chip chico :tono="f.activa ? 'ok' : 'neutro'">{{ f.activa ? 'Activa' : 'Inactiva' }}</Chip>
                    <Chip v-if="f.simulada" chico tono="info">Simulada</Chip>
                    <Chip v-if="f.activa" chico :tono="f.ambiente === 'produccion' ? 'alerta' : 'neutro'">{{ f.ambiente === 'produccion' ? 'Producción' : 'Certificación' }}</Chip>
                </div>
            </div>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-[14px]">
                <dt class="text-grafito">Secreto</dt><dd>{{ f.tiene_secreto ? `Guardado${f.rotado ? ' el ' + fechaHora(f.rotado) : ''}` : 'Sin secreto' }} <span v-if="f.rotar" class="font-semibold text-naranja-profundo">· conviene rotarlo</span></dd>
                <dt class="text-grafito">Reintentos</dt><dd class="num">{{ f.reintentos }} con espera exponencial · {{ f.timeout_seg }} s de espera</dd>
                <dt class="text-grafito">Salud</dt><dd><template v-if="f.salud">{{ f.salud === 'ok' ? 'Respondiendo' : 'Sin respuesta' }} · {{ fechaHora(f.salud_at) }}</template><template v-else>Sin probar</template></dd>
                <template v-if="webhooks[f.tipo]"><dt class="text-grafito">Webhook</dt><dd class="num break-all text-[13px]">{{ webhooks[f.tipo] }}</dd></template>
            </dl>
            <div v-if="puede('configuracion.integraciones.editar')" class="mt-3 flex gap-2">
                <button class="btn btn-secundario btn-sm" @click="abrir(f)">Configurar</button>
                <button class="btn btn-texto btn-sm" @click="probar(f)">Probar</button>
            </div>
        </article>
    </div>

    <Modal :abierto="!!editando" :titulo="editando?.nombre ?? ''" @cerrar="editando = null">
        <template v-if="editando">
            <div class="mb-4 flex flex-wrap gap-6"><Interruptor v-model="form.activa" etiqueta="Activa" /></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <Campo etiqueta="Ambiente" id="in-amb" obligatorio :error="form.errors.ambiente"><select id="in-amb" v-model="form.ambiente" class="campo h-11"><option value="certificacion">Certificación (pruebas)</option><option value="produccion">Producción</option></select></Campo>
                <Campo v-if="IDENTIFICADOR[editando.tipo]" :etiqueta="IDENTIFICADOR[editando.tipo]" id="in-id" :error="form.errors.identificador"><input id="in-id" v-model="form.identificador" class="campo num h-11" maxlength="80" autocomplete="off" /></Campo>
                <Campo etiqueta="URL del servicio" id="in-url" :error="form.errors.url" ayuda="Solo https." class="sm:col-span-2"><input id="in-url" v-model="form.url" class="campo h-11" maxlength="200" inputmode="url" /></Campo>
                <Campo :etiqueta="editando.tiene_secreto ? 'Reemplazar secreto' : 'Secreto o token'" id="in-sec" :error="form.errors.secreto" :ayuda="editando.tiene_secreto ? 'Vacío: se mantiene el actual.' : 'Se guarda cifrado.'" class="sm:col-span-2"><input id="in-sec" v-model="form.secreto" type="password" class="campo h-11" autocomplete="new-password" maxlength="2000" /></Campo>
                <Campo etiqueta="Reintentos" id="in-rei" obligatorio :error="form.errors.reintentos"><input id="in-rei" v-model.number="form.reintentos" type="number" min="0" max="20" class="campo num h-11" /></Campo>
                <Campo etiqueta="Tiempo de espera (s)" id="in-to" obligatorio :error="form.errors.timeout_seg"><input id="in-to" v-model.number="form.timeout_seg" type="number" min="1" max="120" class="campo num h-11" /></Campo>
            </div>
            <p v-if="editando.tipo === 'ia'" class="mt-3 text-[13px] text-grafito">Antes de consultar al proveedor, el sistema reemplaza RUT, teléfonos, correos y nombres por marcadores. Exige al proveedor una política de retención de datos.</p>
            <p v-if="editando.tipo === 'mapas'" class="mt-3 text-[13px] text-grafito">Al proveedor solo se envía dirección y comuna. Requiere acuerdo de tratamiento de datos.</p>
        </template>
        <template #pie>
            <button class="btn btn-secundario" @click="editando = null">Cancelar</button>
            <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar</button>
        </template>
    </Modal>
</template>
