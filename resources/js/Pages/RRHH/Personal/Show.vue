<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import Kpi from '@/Components/Kpi.vue';
import Modal from '@/Components/Modal.vue';
import Campo from '@/Components/Campo.vue';
import Icono from '@/Components/Icono.vue';
import { fecha, fechaHora, pesos, rut } from '@/lib/formato';
import { estadoAusencia, estadoContrato, estadoPrestamo } from '@/lib/documentos';
import { usePermisos } from '@/lib/permisos';

interface Empleado { id: number; rut: string; nombre: string; cargo: string; estado: string; email: string | null; telefono: string | null; direccion: string; nacimiento: string | null; ingreso: string;
    egreso: string | null; afp: string; salud: string; local: string | null; centro: string | null; turno: string | null; usuario: string | null; banco: string | null }
const props = defineProps<{
    empleado: Empleado;
    contratos: { id: number; tipo: string; cargo: string; sueldo: number; inicio: string; termino: string | null; estado: string }[];
    documentos: { id: number; tipo: string; nombre: string; bytes: number; subidor: string | null; fecha: string }[];
    vacaciones: { devengados: number; progresivos: number; tomados: number; disponibles: number; anios: number };
    ausencias: { id: number; tipo: string; desde: string; hasta: string; dias: number; estado: string }[];
    prestamos: { id: number; monto: number; saldo: number; valor_cuota: number; estado: string }[];
    tiposDocumento: Record<string, string>;
}>();
const puede = usePermisos();
const subiendo = ref(false);
const fd = useForm({ archivo: null as File | null, tipo: 'cedula' });
const subir = () => fd.post(`/rrhh/personal/${props.empleado.id}/documentos`, { forceFormData: true, preserveScroll: true, onSuccess: () => { subiendo.value = false; fd.reset(); } });
const kb = (b: number) => (b > 1048576 ? `${(b / 1048576).toLocaleString('es-CL', { maximumFractionDigits: 1 })} MB` : `${Math.ceil(b / 1024)} KB`);
const dato = (v: string | null | undefined) => v || '—';
</script>

<template>
    <Head :title="empleado.nombre" />
    <Encabezado :titulo="empleado.nombre" :bajada="`${empleado.cargo} · RUT ${rut(empleado.rut)}`" volver="/rrhh/personal">
        <Chip v-if="empleado.estado === 'desvinculado'" tono="neutro">Desvinculada desde {{ fecha(empleado.egreso) }}</Chip>
        <Link v-if="puede('rrhh.ficha-de-personal.editar')" :href="`/rrhh/personal/${empleado.id}/editar`" class="btn btn-secundario no-underline">Editar ficha</Link>
        <Link v-if="empleado.estado === 'activo' && puede('rrhh.gestion-de-contratos.crear')" :href="`/rrhh/contratos/crear?empleado=${empleado.id}`" class="btn btn-primario no-underline"><Icono nombre="mas" :tamano="18" /> Contrato o anexo</Link>
    </Encabezado>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi etiqueta="Vacaciones disponibles" :valor="`${vacaciones.disponibles} días`" tono="naranja">{{ vacaciones.anios }} años de antigüedad<span v-if="vacaciones.progresivos"> · {{ vacaciones.progresivos }} progresivos</span></Kpi>
        <Kpi etiqueta="Días tomados o solicitados" :valor="vacaciones.tomados" />
        <Kpi etiqueta="Ingreso" :valor="fecha(empleado.ingreso)" />
        <Kpi etiqueta="Préstamos vigentes" :valor="pesos(prestamos.reduce((s, p) => s + p.saldo, 0))" />
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="tarjeta xl:col-span-1">
            <h2 class="border-b border-linea p-4 text-[18px] font-bold">Ficha</h2>
            <dl class="grid grid-cols-[130px_1fr] gap-x-3 gap-y-2 p-4 text-[15px]">
                <dt class="text-grafito">Correo</dt><dd class="break-all">{{ dato(empleado.email) }}</dd>
                <dt class="text-grafito">Teléfono</dt><dd>{{ dato(empleado.telefono) }}</dd>
                <dt class="text-grafito">Dirección</dt><dd>{{ dato(empleado.direccion) }}</dd>
                <dt class="text-grafito">Nacimiento</dt><dd class="num">{{ fecha(empleado.nacimiento) }}</dd>
                <dt class="text-grafito">Local</dt><dd>{{ dato(empleado.local) }}</dd>
                <dt class="text-grafito">Centro de costo</dt><dd>{{ dato(empleado.centro) }}</dd>
                <dt class="text-grafito">Turno</dt><dd>{{ dato(empleado.turno) }}</dd>
                <dt class="text-grafito">AFP</dt><dd>{{ empleado.afp }}</dd>
                <dt class="text-grafito">Salud</dt><dd>{{ empleado.salud }}</dd>
                <dt class="text-grafito">Cuenta</dt><dd>{{ dato(empleado.banco) }}</dd>
                <dt class="text-grafito">Usuario</dt><dd>{{ dato(empleado.usuario) }}</dd>
            </dl>
        </section>

        <div class="space-y-6 xl:col-span-2">
            <section class="tarjeta">
                <h2 class="border-b border-linea p-4 text-[18px] font-bold">Historial de contratos</h2>
                <div class="overflow-x-auto">
                    <table v-if="contratos.length" class="tabla">
                        <thead><tr><th>Cargo</th><th>Tipo</th><th class="text-right">Sueldo base</th><th>Desde</th><th>Hasta</th><th>Estado</th></tr></thead>
                        <tbody>
                            <tr v-for="c in contratos" :key="c.id">
                                <td><Link :href="`/rrhh/contratos/${c.id}`" class="font-semibold">{{ c.cargo }}</Link></td>
                                <td>{{ c.tipo }}</td>
                                <td class="num text-right">{{ pesos(c.sueldo) }}</td>
                                <td class="num">{{ fecha(c.inicio) }}</td>
                                <td class="num">{{ c.termino ? fecha(c.termino) : 'Indefinido' }}</td>
                                <td><Chip chico :tono="estadoContrato[c.estado][0]">{{ estadoContrato[c.estado][1] }}</Chip></td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="p-4 text-grafito">Aún no tiene contrato.</p>
                </div>
            </section>

            <section class="tarjeta">
                <div class="flex items-center justify-between border-b border-linea p-4">
                    <h2 class="text-[18px] font-bold">Documentos</h2>
                    <button v-if="puede('rrhh.ficha-de-personal.editar')" class="btn btn-secundario btn-sm" @click="subiendo = true"><Icono nombre="subir" :tamano="16" /> Subir</button>
                </div>
                <ul v-if="documentos.length" class="divide-y divide-linea">
                    <li v-for="d in documentos" :key="d.id" class="flex items-center justify-between gap-3 p-4">
                        <div><a :href="`/rrhh/documentos/${d.id}`" class="font-semibold">{{ d.nombre }}</a><p class="text-[13px] text-grafito">{{ d.tipo }} · {{ kb(d.bytes) }} · {{ d.subidor }} · {{ fechaHora(d.fecha) }}</p></div>
                        <Icono nombre="candado" :tamano="18" class="text-grafito" aria-label="Cifrado" />
                    </li>
                </ul>
                <p v-else class="p-4 text-grafito">Sin documentos. Se guardan cifrados.</p>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="tarjeta">
                    <div class="flex items-center justify-between border-b border-linea p-4"><h2 class="text-[18px] font-bold">Ausencias</h2><Link href="/rrhh/ausencias" class="text-[14px]">Ver todas</Link></div>
                    <ul v-if="ausencias.length" class="divide-y divide-linea">
                        <li v-for="a in ausencias" :key="a.id" class="flex items-center justify-between gap-3 p-4 text-[15px]">
                            <div>{{ a.tipo }}<p class="num text-[13px] text-grafito">{{ fecha(a.desde) }} – {{ fecha(a.hasta) }} · {{ a.dias }} días hábiles</p></div>
                            <Chip chico :tono="estadoAusencia[a.estado][0]">{{ estadoAusencia[a.estado][1] }}</Chip>
                        </li>
                    </ul>
                    <p v-else class="p-4 text-grafito">Sin ausencias registradas.</p>
                </section>
                <section class="tarjeta">
                    <div class="flex items-center justify-between border-b border-linea p-4"><h2 class="text-[18px] font-bold">Préstamos</h2><Link href="/rrhh/prestamos" class="text-[14px]">Ver todos</Link></div>
                    <ul v-if="prestamos.length" class="divide-y divide-linea">
                        <li v-for="p in prestamos" :key="p.id" class="flex items-center justify-between gap-3 p-4 text-[15px]">
                            <div class="num">{{ pesos(p.monto) }}<p class="text-[13px] text-grafito">Saldo {{ pesos(p.saldo) }} · cuota {{ pesos(p.valor_cuota) }}</p></div>
                            <Chip chico :tono="estadoPrestamo[p.estado][0]">{{ estadoPrestamo[p.estado][1] }}</Chip>
                        </li>
                    </ul>
                    <p v-else class="p-4 text-grafito">Sin préstamos vigentes.</p>
                </section>
            </div>
        </div>
    </div>

    <Modal :abierto="subiendo" titulo="Subir documento" @cerrar="subiendo = false">
        <div class="grid gap-4">
            <Campo etiqueta="Tipo" id="doc-tipo" obligatorio :error="fd.errors.tipo"><select id="doc-tipo" v-model="fd.tipo" class="campo h-11"><option v-for="(n, k) in tiposDocumento" :key="k" :value="k">{{ n }}</option></select></Campo>
            <Campo etiqueta="Archivo" id="doc-archivo" obligatorio :error="fd.errors.archivo" ayuda="PDF, imagen o Word, hasta 10 MB. Se guarda cifrado."><input id="doc-archivo" type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" class="campo h-auto py-2" @change="fd.archivo = ($event.target as HTMLInputElement).files?.[0] ?? null" /></Campo>
        </div>
        <template #pie>
            <button class="btn btn-secundario" @click="subiendo = false">Cancelar</button>
            <button class="btn btn-primario" :disabled="fd.processing || !fd.archivo" @click="subir">Subir</button>
        </template>
    </Modal>
</template>
