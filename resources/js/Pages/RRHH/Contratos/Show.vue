<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import { fecha, fechaHora, pesos } from '@/lib/formato';
import { estadoContrato } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

const props = defineProps<{ contrato: { id: number; empleado_id: number; empleado: string; rut: string; tipo: string; cargo: string; sueldo: number; inicio: string; termino: string | null; estado: string;
    jornada: number; gratificacion: boolean; colacion: number; movilizacion: number; comision: number; texto: string | null; plantilla: string | null; firma_id: string | null; firmado_at: string | null } }>();
const puede = usePermisos();
const tipos: Record<string, string> = { indefinido: 'Indefinido', plazo_fijo: 'Plazo fijo', obra: 'Obra o faena' };
const post = (accion: string) => router.post(`/rrhh/contratos/${props.contrato.id}/${accion}`, {}, { preserveScroll: true });
</script>

<template>
    <Head :title="`Contrato · ${contrato.empleado}`" />
    <Encabezado :titulo="contrato.cargo" :bajada="`Contrato de ${contrato.empleado}`" :volver="`/rrhh/personal/${contrato.empleado_id}`">
        <Chip :tono="estadoContrato[contrato.estado][0]">{{ estadoContrato[contrato.estado][1] }}</Chip>
        <template v-if="puede('rrhh.gestion-de-contratos.editar')">
            <Link v-if="contrato.estado === 'borrador'" :href="`/rrhh/contratos/${contrato.id}/editar`" class="btn btn-secundario no-underline">Editar</Link>
            <button v-if="contrato.estado === 'borrador'" class="btn btn-primario" @click="post('enviar')">Enviar a firma electrónica</button>
            <button v-if="contrato.estado === 'por_firmar'" class="btn btn-primario" @click="post('sincronizar')">Consultar firma</button>
        </template>
    </Encabezado>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="tarjeta self-start">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Condiciones</h2>
            <dl class="grid grid-cols-[140px_1fr] gap-x-3 gap-y-2 p-4 text-[15px]">
                <dt class="text-grafito">Tipo</dt><dd>{{ tipos[contrato.tipo] }}</dd>
                <dt class="text-grafito">Vigencia</dt><dd class="num">{{ fecha(contrato.inicio) }} – {{ contrato.termino ? fecha(contrato.termino) : 'indefinido' }}</dd>
                <dt class="text-grafito">Jornada</dt><dd>{{ contrato.jornada }} horas semanales</dd>
                <dt class="text-grafito">Sueldo base</dt><dd class="num font-semibold">{{ pesos(contrato.sueldo) }}</dd>
                <dt class="text-grafito">Gratificación</dt><dd>{{ contrato.gratificacion ? 'Legal (art. 50)' : 'No' }}</dd>
                <dt class="text-grafito">Colación</dt><dd class="num">{{ pesos(contrato.colacion) }}</dd>
                <dt class="text-grafito">Movilización</dt><dd class="num">{{ pesos(contrato.movilizacion) }}</dd>
                <dt class="text-grafito">Comisión</dt><dd>{{ contrato.comision ? `${contrato.comision.toLocaleString('es-CL')}%` : '—' }}</dd>
                <dt class="text-grafito">Plantilla</dt><dd>{{ contrato.plantilla ?? 'Texto libre' }}</dd>
                <dt class="text-grafito">Firma</dt><dd>{{ contrato.firmado_at ? fechaHora(contrato.firmado_at) : contrato.firma_id ? 'Pendiente' : '—' }}<div v-if="contrato.firma_id" class="num text-[13px] text-grafito">{{ contrato.firma_id }}</div></dd>
            </dl>
        </section>
        <article class="tarjeta p-6 xl:col-span-2">
            <pre v-if="contrato.texto" class="whitespace-pre-wrap font-sans text-[15px] leading-relaxed">{{ contrato.texto }}</pre>
            <p v-else class="text-grafito">Sin texto: edítalo y elige una plantilla.</p>
        </article>
    </div>
</template>
