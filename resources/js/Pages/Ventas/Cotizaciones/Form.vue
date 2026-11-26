<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import Encabezado from '@/Components/Encabezado.vue';
import Campo from '@/Components/Campo.vue';
import Chip from '@/Components/Chip.vue';
import Icono from '@/Components/Icono.vue';
import { pesos, rut } from '@/lib/formato';
import { http } from '@/lib/http';

interface ClienteBusqueda { id: number; rut: string; razon_social: string; giro: string | null; direccion: string | null; comuna: string | null; lista_precio: string; tiene_credito: boolean; cupo_credito?: number; plazo_dias?: number }
interface ProductoBusqueda { id: number; sku: string; nombre: string; unidad: string; precio_neto: string; precio_mayorista: string | null; exento: boolean }
interface Linea { producto_id: number | null; descripcion: string; cantidad: string; precio_unitario: string; descuento_pct: string; exento: boolean; precio_lista?: string | null; monto_item?: number; buscando?: string }
interface Cotizacion { id: number; numero: number; cliente: ClienteBusqueda | null; contacto_nombre: string | null; contacto_email: string | null; contacto_telefono: string | null; local_id: number | null; forma_pago: string; descuento_global_pct: string; valida_hasta: string; observaciones: string | null; lineas: (Linea & { precio_lista: string | null })[] }

const props = defineProps<{ cotizacion: Cotizacion | null; locales: { id: number; nombre: string }[]; descuentoMaximo: number; vigenciaDias: number }>();
const documento = props.cotizacion;
const enDias = (n: number) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };

const nueva = (): Linea => ({ producto_id: null, descripcion: '', cantidad: '1', precio_unitario: '', descuento_pct: '', exento: false });
const cliente = ref<ClienteBusqueda | null>(documento?.cliente ?? null);
const form = useForm({
    cliente_id: documento?.cliente?.id ?? (null as number | null),
    contacto_nombre: documento?.contacto_nombre ?? '',
    contacto_email: documento?.contacto_email ?? '',
    contacto_telefono: documento?.contacto_telefono ?? '',
    valida_hasta: documento && documento.valida_hasta >= enDias(0) ? documento.valida_hasta : enDias(props.vigenciaDias),
    local_id: documento?.local_id ?? (props.locales[0]?.id ?? null),
    forma_pago: documento?.forma_pago ?? 'contado',
    descuento_global_pct: documento?.descuento_global_pct === '0.00' ? '' : (documento?.descuento_global_pct ?? ''),
    observaciones: documento?.observaciones ?? '',
    // En una edición, el precio guardado se mantiene como precio explícito.
    lineas: (documento?.lineas.map((l) => ({ ...l, descuento_pct: l.descuento_pct === '0' ? '' : l.descuento_pct, precio_unitario: l.precio_lista !== null && l.precio_lista === l.precio_unitario ? '' : l.precio_unitario })) ?? [nueva()]) as Linea[],
});

// ── Búsqueda de cliente ──
const qCliente = ref('');
const clientes = ref<ClienteBusqueda[]>([]);
let tc: ReturnType<typeof setTimeout>;
watch(qCliente, (q) => {
    clearTimeout(tc);
    if (q.trim().length < 2) return void (clientes.value = []);
    tc = setTimeout(async () => (clientes.value = (await http.get('/buscar/clientes', { params: { q } })).data), 250);
});
function elegirCliente(c: ClienteBusqueda | null) {
    cliente.value = c;
    form.cliente_id = c?.id ?? null;
    if (!c?.tiene_credito) form.forma_pago = 'contado';
    qCliente.value = '';
    clientes.value = [];
}

// ── Búsqueda de producto por línea ──
const resultados = reactive<Record<number, ProductoBusqueda[]>>({});
const timers: Record<number, ReturnType<typeof setTimeout>> = {};
function buscarProducto(i: number, q: string) {
    form.lineas[i].buscando = q;
    clearTimeout(timers[i]);
    if (q.trim().length < 2) return void (resultados[i] = []);
    timers[i] = setTimeout(async () => (resultados[i] = (await http.get('/buscar/productos', { params: { q } })).data), 250);
}
function elegirProducto(i: number, p: ProductoBusqueda) {
    Object.assign(form.lineas[i], { producto_id: p.id, descripcion: p.nombre, exento: p.exento, precio_unitario: '', buscando: undefined });
    resultados[i] = [];
}
function quitarProducto(i: number) {
    Object.assign(form.lineas[i], { producto_id: null, precio_lista: null });
}
const agregar = () => form.lineas.push(nueva());
const quitar = (i: number) => form.lineas.length > 1 && form.lineas.splice(i, 1);

// ── Totales: siempre calculados por el backend ──
interface Calculo { lineas: { precio_unitario: string; precio_lista: string | null; monto_item: number; exento: boolean }[]; totales: { afecto: number; descuento_global_monto: number; monto_neto: number; monto_exento: number; monto_iva: number; monto_total: number }; motivos: string[] }
const calculo = ref<Calculo | null>(null);
const errorCalculo = ref('');
let tcalc: ReturnType<typeof setTimeout>;
const cargaCalculo = computed(() => JSON.stringify([form.cliente_id, form.forma_pago, form.descuento_global_pct, form.lineas.map((l) => [l.producto_id, l.descripcion, l.cantidad, l.precio_unitario, l.descuento_pct, l.exento])]));
watch(cargaCalculo, () => {
    clearTimeout(tcalc);
    tcalc = setTimeout(async () => {
        const lineas = form.lineas.filter((l) => l.producto_id || l.precio_unitario !== '').map((l) => ({ ...l, buscando: undefined }));
        if (!lineas.length) return void (calculo.value = null);
        try {
            calculo.value = (await http.post('/ventas/cotizaciones/calcular', { cliente_id: form.cliente_id, forma_pago: form.forma_pago, descuento_global_pct: form.descuento_global_pct || 0, lineas })).data;
            errorCalculo.value = '';
        } catch (e: any) {
            errorCalculo.value = e?.response?.data?.message ?? 'No se pudo calcular.';
        }
    }, 300);
}, { immediate: true });
// Muestra en cada línea el precio de lista y el monto que devolvió el backend.
const montoLinea = (i: number) => {
    const validas = form.lineas.map((l, j) => (l.producto_id || l.precio_unitario !== '' ? j : -1)).filter((j) => j >= 0);
    const k = validas.indexOf(i);
    return k >= 0 ? calculo.value?.lineas[k] : undefined;
};

function guardar() {
    form.transform((d) => ({ ...d, descuento_global_pct: d.descuento_global_pct || 0, lineas: d.lineas.map(({ buscando, monto_item, precio_lista, ...l }) => ({ ...l, precio_unitario: l.precio_unitario === '' ? null : l.precio_unitario, descuento_pct: l.descuento_pct === '' ? 0 : l.descuento_pct })) }));
    documento ? form.put(`/ventas/cotizaciones/${documento.id}`) : form.post('/ventas/cotizaciones');
}
const erroresForm = computed(() => form.errors as Record<string, string | undefined>);
const errorLinea = (i: number) => Object.entries(form.errors).filter(([k]) => k.startsWith(`lineas.${i}`)).map(([, v]) => v).join(' ');
</script>

<template>
    <Head :title="documento ? `Editar cotización N° ${documento.numero}` : 'Nueva cotización'" />
    <Encabezado :titulo="documento ? `Editar cotización N° ${documento.numero}` : 'Nueva cotización'" volver="/ventas/cotizaciones" bajada="Precios netos según la lista del cliente. Los totales los calcula el sistema; luego se envía y, aceptada, se convierte en factura o boleta." />

    <form class="grid gap-6 xl:grid-cols-[1fr_340px]" @submit.prevent="guardar">
        <div class="space-y-6">
            <section class="tarjeta p-5">
                <h2 class="titulo-ancho mb-4 text-[16px] font-bold">Cliente</h2>
                <div v-if="cliente" class="flex flex-wrap items-start justify-between gap-3 rounded-boton border border-linea p-4">
                    <div>
                        <div class="font-semibold">{{ cliente.razon_social }}</div>
                        <div class="num text-[14px] text-grafito">{{ rut(cliente.rut) }} · {{ cliente.lista_precio === 'mayorista' ? 'Lista mayorista' : 'Lista mesón' }}</div>
                        <div class="text-[14px] text-grafito">{{ [cliente.giro, cliente.direccion, cliente.comuna].filter(Boolean).join(' · ') }}</div>
                    </div>
                    <button type="button" class="btn btn-secundario btn-sm" @click="elegirCliente(null)">Cambiar</button>
                </div>
                <div v-else class="relative">
                    <Icono nombre="buscar" :tamano="18" class="absolute left-3 top-[22px] -translate-y-1/2 text-grafito" />
                    <input v-model="qCliente" class="campo h-11 pl-10" placeholder="Busca por razón social o RUT" aria-label="Buscar cliente" autocomplete="off" />
                    <ul v-if="clientes.length" class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                        <li v-for="c in clientes" :key="c.id"><button type="button" class="block w-full px-4 py-2 text-left hover:bg-hormigon" @click="elegirCliente(c)"><span class="font-semibold">{{ c.razon_social }}</span> <span class="num text-grafito">{{ rut(c.rut) }}</span></button></li>
                    </ul>
                    <p v-if="form.errors.cliente_id" class="error mt-1">{{ form.errors.cliente_id }}</p>
                </div>
                <div class="mt-5 grid gap-5 md:grid-cols-3">
                    <Campo etiqueta="Contacto" id="contacto_nombre" :error="form.errors.contacto_nombre" :ayuda="cliente ? '' : 'Obligatorio si el cliente aún no está registrado.'"><input id="contacto_nombre" v-model="form.contacto_nombre" class="campo h-11" maxlength="100" /></Campo>
                    <Campo etiqueta="Correo" id="contacto_email" :error="form.errors.contacto_email"><input id="contacto_email" v-model="form.contacto_email" type="email" class="campo h-11" maxlength="120" /></Campo>
                    <Campo etiqueta="Teléfono" id="contacto_telefono" :error="form.errors.contacto_telefono"><input id="contacto_telefono" v-model="form.contacto_telefono" class="campo h-11" maxlength="20" /></Campo>
                </div>
            </section>

            <section class="tarjeta p-5">
                <div class="grid gap-5 md:grid-cols-3">
                    <Campo etiqueta="Local" id="local_id" :error="form.errors.local_id">
                        <select id="local_id" v-model.number="form.local_id" class="campo h-11"><option v-for="l in locales" :key="l.id" :value="l.id">{{ l.nombre }}</option></select>
                    </Campo>
                    <Campo etiqueta="Forma de pago" id="forma_pago" :error="form.errors.forma_pago" :ayuda="cliente?.tiene_credito ? '' : 'Crédito solo para clientes con cuenta corriente.'">
                        <select id="forma_pago" v-model="form.forma_pago" class="campo h-11"><option value="contado">Contado</option><option value="credito" :disabled="!cliente?.tiene_credito">Crédito</option></select>
                    </Campo>
                    <Campo etiqueta="Válida hasta" id="valida_hasta" :error="form.errors.valida_hasta"><input id="valida_hasta" v-model="form.valida_hasta" type="date" :min="enDias(0)" class="campo h-11" /></Campo>
                </div>
            </section>
            <section class="tarjeta">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h2 class="titulo-ancho text-[16px] font-bold">Detalle</h2>
                    <span class="text-[14px] text-grafito">Precios netos</span>
                </div>
                <div class="overflow-x-auto p-2">
                    <table class="tabla">
                        <thead><tr><th class="min-w-[260px]">Producto o descripción</th><th class="w-24 text-right">Cant.</th><th class="w-36 text-right">Precio unit.</th><th class="w-24 text-right">Desc. %</th><th class="w-32 text-right">Monto</th><th class="w-px"></th></tr></thead>
                        <tbody>
                            <tr v-for="(l, i) in form.lineas" :key="i" class="align-top">
                                <td>
                                    <div class="relative">
                                        <input :value="l.buscando ?? l.descripcion" class="campo h-11" maxlength="80" placeholder="Busca producto o escribe una descripción" :aria-label="`Descripción línea ${i + 1}`" autocomplete="off"
                                            @input="(e) => { const v = (e.target as HTMLInputElement).value; if (l.producto_id) { l.descripcion = v; } else { l.descripcion = v; buscarProducto(i, v); } }" />
                                        <ul v-if="resultados[i]?.length" class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-boton border border-linea bg-white shadow-lg">
                                            <li v-for="p in resultados[i]" :key="p.id"><button type="button" class="block w-full px-4 py-2 text-left hover:bg-hormigon" @click="elegirProducto(i, p)"><span class="num text-grafito">{{ p.sku }}</span> {{ p.nombre }}</button></li>
                                        </ul>
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-[13px] text-grafito">
                                        <template v-if="l.producto_id"><Chip chico tono="info">Del catálogo</Chip><button type="button" class="underline" @click="quitarProducto(i)">desvincular</button></template>
                                        <Chip v-if="l.exento" chico>Exento</Chip>
                                        <span v-if="montoLinea(i)?.precio_lista && !l.precio_unitario">Lista: {{ pesos(Number(montoLinea(i)?.precio_lista)) }}</span>
                                    </div>
                                    <p v-if="errorLinea(i)" class="error mt-1">{{ errorLinea(i) }}</p>
                                </td>
                                <td><input v-model="l.cantidad" inputmode="decimal" class="campo num h-11 text-right" :aria-label="`Cantidad línea ${i + 1}`" /></td>
                                <td><input v-model="l.precio_unitario" inputmode="decimal" class="campo num h-11 text-right" :placeholder="montoLinea(i)?.precio_lista ? String(Math.round(Number(montoLinea(i)?.precio_lista))) : ''" :aria-label="`Precio línea ${i + 1}`" /></td>
                                <td><input v-model="l.descuento_pct" inputmode="decimal" class="campo num h-11 text-right" placeholder="0" :aria-label="`Descuento línea ${i + 1}`" /></td>
                                <td class="num pt-5 text-right font-semibold">{{ pesos(montoLinea(i)?.monto_item) }}</td>
                                <td class="pt-3"><button type="button" class="btn btn-secundario btn-sm" :disabled="form.lineas.length === 1" :aria-label="`Quitar línea ${i + 1}`" @click="quitar(i)"><Icono nombre="cerrar" :tamano="16" /></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-linea p-4">
                    <button type="button" class="btn btn-secundario" :disabled="form.lineas.length >= 60" @click="agregar"><Icono nombre="mas" :tamano="18" /> Agregar línea</button>
                    <p v-if="form.errors.lineas" class="error">{{ form.errors.lineas }}</p>
                </div>
            </section>

            <section class="tarjeta p-5">
                <Campo etiqueta="Observaciones" id="observaciones" :error="form.errors.observaciones"><textarea id="observaciones" v-model="form.observaciones" rows="2" class="campo" maxlength="500" /></Campo>
            </section>
        </div>

        <aside class="space-y-4 xl:sticky xl:top-6 xl:self-start">
            <section class="tarjeta p-5">
                <h2 class="titulo-ancho mb-4 text-[16px] font-bold">Totales</h2>
                <Campo etiqueta="Descuento global %" id="dg" :error="form.errors.descuento_global_pct" :ayuda="`Sobre lo afecto. Más de ${descuentoMaximo} % requiere aprobación.`">
                    <input id="dg" v-model="form.descuento_global_pct" inputmode="decimal" class="campo num h-11 text-right" placeholder="0" />
                </Campo>
                <dl class="mt-5 space-y-2 text-[15px]">
                    <div v-if="calculo?.totales.descuento_global_monto" class="flex justify-between"><dt class="text-grafito">Descuento global</dt><dd class="num">{{ pesos(-calculo.totales.descuento_global_monto) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-grafito">Neto</dt><dd class="num">{{ pesos(calculo?.totales.monto_neto ?? 0) }}</dd></div>
                    <div v-if="calculo?.totales.monto_exento" class="flex justify-between"><dt class="text-grafito">Exento</dt><dd class="num">{{ pesos(calculo.totales.monto_exento) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-grafito">IVA</dt><dd class="num">{{ pesos(calculo?.totales.monto_iva ?? 0) }}</dd></div>
                    <div class="flex items-baseline justify-between border-t border-linea pt-3"><dt class="font-semibold">Total</dt><dd class="num text-[28px] font-bold text-naranja-profundo">{{ pesos(calculo?.totales.monto_total ?? 0) }}</dd></div>
                </dl>
                <p v-if="errorCalculo" class="error mt-3">{{ errorCalculo }}</p>
                <div v-if="calculo?.motivos.length" class="mt-4 rounded-boton border border-[#F0D58A] bg-[#FFF3D6] p-3 text-[14px] text-[#8A5A00]">
                    <p class="font-semibold">Requerirá aprobación:</p>
                    <ul class="mt-1 list-disc pl-5"><li v-for="m in calculo.motivos" :key="m">{{ m }}</li></ul>
                </div>
            </section>
            <button type="submit" class="btn btn-primario w-full" :disabled="form.processing">Guardar cotización</button>
            <p v-if="erroresForm.cotizacion" class="error">{{ erroresForm.cotizacion }}</p>
        </aside>
    </form>
</template>
