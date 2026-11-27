<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Icono from '@/Components/Icono.vue';
import Campo from '@/Components/Campo.vue';
import Interruptor from '@/Components/Interruptor.vue';
import Chip from '@/Components/Chip.vue';

interface Sub { clave: string; nombre: string; acciones: string[] }
interface Mod { clave: string; nombre: string; submodulos: Sub[] }
const props = defineProps<{
    rol: { id: number; nombre: string; descripcion: string | null; perfil_id: number; activo: boolean; permisos: string[]; pendientes: string[] } | null;
    perfiles: { id: number; nombre: string; clave: string }[];
    matriz: Mod[];
    acciones: Record<string, string>;
    restringidos: string[];
}>();

const form = useForm({
    nombre: props.rol?.nombre ?? '',
    descripcion: props.rol?.descripcion ?? '',
    perfil_id: props.rol?.perfil_id ?? ('' as number | ''),
    activo: props.rol?.activo ?? true,
    permisos: [...(props.rol?.permisos ?? [])] as string[],
    motivo: '',
});
const originales = new Set((props.rol?.permisos ?? []).filter((p) => !(props.rol?.pendientes ?? []).includes(p)));
const pendientes = new Set(props.rol?.pendientes ?? []);
const nuevos = computed(() => form.permisos.filter((p) => !originales.has(p)).length);

const sel = computed(() => new Set(form.permisos));
const columnas = Object.keys(props.acciones);
const moduloAbierto = ref<string>(props.matriz[0]?.clave ?? '');

function clave(m: string, s: string, a: string) { return `${m}.${s}.${a}`; }
function alternar(c: string) {
    form.permisos = sel.value.has(c) ? form.permisos.filter((p) => p !== c) : [...form.permisos, c];
}
function todosDelModulo(m: Mod, valor: boolean) {
    const claves = m.submodulos.flatMap((s) => s.acciones.map((a) => clave(m.clave, s.clave, a))).filter((c) => !props.restringidos.includes(c));
    form.permisos = valor ? [...new Set([...form.permisos, ...claves])] : form.permisos.filter((p) => !claves.includes(p));
}
function contar(m: Mod) {
    return form.permisos.filter((p) => p.startsWith(m.clave + '.')).length;
}
function total(m: Mod) {
    return m.submodulos.reduce((n, s) => n + s.acciones.length, 0);
}

function guardar() {
    if (props.rol) form.put(`/configuracion/roles/${props.rol.id}`);
    else form.post('/configuracion/roles');
}
</script>

<template>
    <Head :title="rol ? `Rol ${rol.nombre}` : 'Nuevo rol'" />
    <Encabezado :titulo="rol ? rol.nombre : 'Nuevo rol'" bajada="Marca lo que este rol puede hacer. Los permisos marcados con candado se asignan explícitamente y su uso queda auditado." volver="/configuracion/roles">
        <button class="btn btn-primario" :disabled="form.processing" @click="guardar">Guardar rol</button>
    </Encabezado>

    <div class="grid gap-6 xl:grid-cols-[340px_minmax(0,1fr)]">
        <section class="tarjeta h-fit space-y-4 p-5">
            <Campo etiqueta="Nombre" id="nombre" :error="form.errors.nombre" obligatorio><input id="nombre" v-model="form.nombre" class="campo h-11" /></Campo>
            <Campo etiqueta="Perfil" id="perfil" :error="form.errors.perfil_id" obligatorio ayuda="El perfil decide políticas como 2FA obligatorio o el tiempo de inactividad.">
                <select id="perfil" v-model="form.perfil_id" class="campo h-11"><option value="" disabled>Elige un perfil</option><option v-for="p in perfiles" :key="p.id" :value="p.id">{{ p.nombre }}</option></select>
            </Campo>
            <Campo etiqueta="Descripción" id="descripcion" :error="form.errors.descripcion"><textarea id="descripcion" v-model="form.descripcion" class="campo" rows="3" /></Campo>
            <Interruptor v-model="form.activo" etiqueta="Rol activo" />
            <p class="num text-[14px] text-grafito">{{ form.permisos.length }} permisos marcados</p>
            <div v-if="nuevos" class="rounded-lg border border-naranja bg-naranja/10 p-3 text-[14px]">
                <p class="mb-2"><strong class="num">{{ nuevos }}</strong> permiso(s) nuevo(s) quedarán pendientes hasta que otra persona los apruebe. Lo que quites rige al guardar.</p>
                <Campo etiqueta="Motivo del cambio" id="motivo" :error="form.errors.motivo"><textarea id="motivo" v-model="form.motivo" class="campo" rows="2" maxlength="500" placeholder="Ej.: apoyo en bodega durante el inventario" /></Campo>
            </div>
            <p v-if="form.errors.permisos" class="error">{{ form.errors.permisos }}</p>
        </section>

        <section class="space-y-3">
            <div v-for="m in matriz" :key="m.clave" class="tarjeta">
                <button type="button" class="flex w-full items-center justify-between gap-3 px-5 py-4 text-left" :aria-expanded="moduloAbierto === m.clave" @click="moduloAbierto = moduloAbierto === m.clave ? '' : m.clave">
                    <span class="titulo-ancho text-[16px] font-bold">{{ m.nombre }}</span>
                    <Chip chico :tono="contar(m) ? 'naranja' : 'neutro'"><span class="num">{{ contar(m) }} / {{ total(m) }}</span></Chip>
                </button>
                <div v-if="moduloAbierto === m.clave" class="border-t border-linea">
                    <div class="flex gap-4 px-5 py-2 text-[14px]">
                        <button type="button" class="btn-texto font-semibold" @click="todosDelModulo(m, true)">Marcar todo</button>
                        <button type="button" class="btn-texto font-semibold" @click="todosDelModulo(m, false)">Quitar todo</button>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="tabla">
                            <thead><tr><th>Submódulo</th><th v-for="a in columnas" :key="a" class="text-center">{{ acciones[a] }}</th></tr></thead>
                            <tbody>
                                <tr v-for="s in m.submodulos" :key="s.clave">
                                    <td class="font-semibold">{{ s.nombre }}</td>
                                    <td v-for="a in columnas" :key="a" class="text-center">
                                        <label v-if="s.acciones.includes(a)" class="inline-flex items-center gap-1" :title="restringidos.includes(clave(m.clave, s.clave, a)) ? 'Permiso restringido: se asigna explícitamente' : ''">
                                            <input
                                                type="checkbox"
                                                class="h-5 w-5 rounded border-linea-fuerte text-carbon focus:ring-naranja"
                                                :checked="sel.has(clave(m.clave, s.clave, a))"
                                                :aria-label="`${acciones[a]} en ${s.nombre}`"
                                                @change="alternar(clave(m.clave, s.clave, a))"
                                            />
                                            <Icono v-if="restringidos.includes(clave(m.clave, s.clave, a))" nombre="candado" :tamano="14" />
                                            <span v-if="pendientes.has(clave(m.clave, s.clave, a))" class="h-2 w-2 rounded-full bg-naranja" title="Pendiente de aprobación" aria-label="Pendiente de aprobación" />
                                        </label>
                                        <span v-else class="text-linea-fuerte">·</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
