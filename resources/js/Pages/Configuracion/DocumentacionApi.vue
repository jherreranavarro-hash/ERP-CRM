<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Chip from '@/Components/Chip.vue';
import { usePermisos } from '@/lib/permisos';

interface Parametro { nombre: string; en: string; requerido: boolean; tipo: string; valores: (string | number)[] | null; descripcion: string | null }
interface Endpoint { metodo: string; ruta: string; scope: string | null; documentada: boolean; tag: string; resumen: string; descripcion: string | null; parametros: Parametro[];
    cuerpo: unknown; codigo: string; respuesta: unknown }
const props = defineProps<{ base: string; endpoints: Endpoint[] }>();
const puede = usePermisos();
const grupos = computed(() => [...new Set(props.endpoints.map((e) => e.tag))].map((t) => ({ tag: t, endpoints: props.endpoints.filter((e) => e.tag === t) })));
const abierto = ref<string | null>(null);
const clave = (e: Endpoint) => `${e.metodo} ${e.ruta}`;
const json = (v: unknown) => JSON.stringify(v, null, 2);
function curl(e: Endpoint): string {
    const q = e.parametros.filter((p) => p.en === 'query' && p.requerido).map((p) => `${p.nombre}=${p.tipo === 'string' ? '2026-11-01' : '1'}`).join('&');
    const ruta = e.ruta.replace('{id}', '812') + (q ? `?${q}` : '');
    let c = `curl ${e.metodo !== 'GET' ? `-X ${e.metodo} ` : ''}"${props.base}${ruta}" \\\n  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"`;
    if (e.cuerpo) c += ` \\\n  -H "Content-Type: application/json" \\\n  -d '${JSON.stringify(e.cuerpo)}'`;
    return c;
}
const color: Record<string, string> = { GET: 'bg-estado-info-bg text-estado-info', POST: 'bg-estado-ok-bg text-estado-ok', PUT: 'bg-estado-alerta-bg text-estado-alerta', DELETE: 'bg-estado-error-bg text-estado-error' };
</script>

<template>
    <Head title="Documentación de la API" />
    <Encabezado titulo="Documentación de la API" bajada="Generada desde las rutas de la API v1: siempre al día con lo que el sistema expone. Solo tokens de API; la sesión del navegador no sirve.">
        <a class="btn btn-secundario" href="/configuracion/documentacion-api/openapi.json">Descargar OpenAPI</a>
        <a v-if="puede('configuracion.tokens-api.ver')" class="btn btn-primario" href="/configuracion/tokens">Tokens de API</a>
    </Encabezado>

    <section class="tarjeta mb-6 grid gap-4 p-5 text-[15px] md:grid-cols-3">
        <div><div class="text-[14px] text-grafito">Base</div><div class="num break-all font-semibold">{{ base }}</div></div>
        <div><div class="text-[14px] text-grafito">Autenticación</div><div><span class="num">Authorization: Bearer &lt;token&gt;</span>, con el scope de cada endpoint.</div></div>
        <div><div class="text-[14px] text-grafito">Formato</div><div>Montos en pesos enteros, fechas AAAA-MM-DD. Colecciones paginadas con <span class="num">page</span> y <span class="num">per_page</span> (máx. 200).</div></div>
    </section>

    <section v-for="g in grupos" :key="g.tag" class="mb-6">
        <h2 class="mb-3 text-[18px] font-bold">{{ g.tag }}</h2>
        <div class="tarjeta divide-y divide-linea">
            <article v-for="e in g.endpoints" :key="clave(e)">
                <button class="flex w-full flex-wrap items-center gap-3 px-5 py-4 text-left hover:bg-hormigon" :aria-expanded="abierto === clave(e)" @click="abierto = abierto === clave(e) ? null : clave(e)">
                    <span class="num w-16 rounded-boton px-2 py-1 text-center text-[13px] font-bold" :class="color[e.metodo]">{{ e.metodo }}</span>
                    <span class="num font-semibold">{{ e.ruta }}</span>
                    <span class="text-grafito">{{ e.resumen }}</span>
                    <span class="ml-auto flex gap-2">
                        <Chip v-if="!e.documentada" chico tono="error">Sin documentar</Chip>
                        <Chip chico :tono="e.scope ? 'naranja' : 'neutro'">{{ e.scope ?? 'sin scope' }}</Chip>
                    </span>
                </button>
                <div v-if="abierto === clave(e)" class="grid gap-5 border-t border-linea bg-hormigon/40 px-5 py-5 lg:grid-cols-2">
                    <div class="min-w-0">
                        <p v-if="e.descripcion" class="mb-4">{{ e.descripcion }}</p>
                        <template v-if="e.parametros.length">
                            <h3 class="mb-2 font-bold">Parámetros</h3>
                            <table class="tabla mb-4 bg-white">
                                <thead><tr><th>Nombre</th><th>Tipo</th><th>Descripción</th></tr></thead>
                                <tbody>
                                    <tr v-for="p in e.parametros" :key="p.nombre">
                                        <td class="num whitespace-nowrap font-semibold">{{ p.nombre }}<span v-if="p.requerido" class="text-naranja-profundo"> *</span></td>
                                        <td class="whitespace-nowrap text-grafito">{{ p.tipo }} · {{ p.en === 'path' ? 'ruta' : 'consulta' }}</td>
                                        <td>{{ p.descripcion }}<span v-if="p.valores" class="num text-[13px] text-grafito"> {{ p.valores.join(', ') }}</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </template>
                        <h3 class="mb-2 font-bold">Ejemplo</h3>
                        <pre class="num overflow-x-auto rounded-boton bg-carbon p-4 text-[13px] leading-relaxed text-white">{{ curl(e) }}</pre>
                    </div>
                    <div class="min-w-0">
                        <h3 class="mb-2 font-bold">Respuesta {{ e.codigo }}</h3>
                        <pre class="num max-h-[420px] overflow-auto rounded-boton bg-white p-4 text-[13px] leading-relaxed ring-1 ring-linea">{{ json(e.respuesta) }}</pre>
                        <p class="mt-2 text-[13px] text-grafito">Errores: 401 token inválido · 403 sin scope o IP no permitida · 422 datos inválidos · 429 límite excedido.</p>
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
