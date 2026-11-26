/** Estados de documentos como palabras con su tono de chip. */
export type Tono = 'neutro' | 'alerta' | 'info' | 'ok' | 'error';

export const estadoDoc: Record<string, [Tono, string]> = {
    borrador: ['neutro', 'Borrador'],
    por_aprobar: ['alerta', 'Por aprobar'],
    emitido: ['info', 'Emitido'],
    pagado: ['ok', 'Pagado'],
    anulado: ['error', 'Anulado'],
};

export const estadoSii: Record<string, [Tono, string]> = {
    pendiente: ['alerta', 'SII pendiente'],
    aceptado: ['ok', 'SII aceptado'],
    rechazado: ['error', 'SII rechazado'],
    error: ['error', 'SII con error'],
};

export const estadoCot: Record<string, [Tono, string]> = {
    borrador: ['neutro', 'Borrador'],
    por_aprobar: ['alerta', 'Por aprobar'],
    enviada: ['info', 'Enviada'],
    aceptada: ['ok', 'Aceptada'],
    rechazada: ['error', 'Rechazada'],
    convertida: ['ok', 'Convertida'],
    vencida: ['alerta', 'Vencida'],
};

/** Estado visible de un turno de caja. */
export function estadoTurno(t: { estado: string; diferencia: number | null; requiere_aprobacion: boolean; aprobado_por: string | null }): [Tono, string] {
    if (t.estado === 'abierto') return ['info', 'Abierto'];
    if (t.requiere_aprobacion && !t.aprobado_por) return ['alerta', 'Diferencia por aprobar'];
    if (t.requiere_aprobacion) return ['neutro', 'Diferencia aprobada'];
    if (t.diferencia) return ['neutro', 'Diferencia en tolerancia'];
    return ['ok', 'Cuadrado'];
}

export const estadoOrden: Record<string, [Tono, string]> = {
    confirmada: ['info', 'Confirmada'],
    vencida: ['alerta', 'Reserva vencida'],
    facturada: ['ok', 'Facturada'],
    anulada: ['error', 'Anulada'],
};

export const estadoMerma: Record<string, [Tono, string]> = {
    por_aprobar: ['alerta', 'Por aprobar'],
    registrada: ['ok', 'Registrada'],
    rechazada: ['error', 'Rechazada'],
};

/** Cantidad con decimales solo si los tiene. */
export const cantidad = (v: number | string) => Number(v).toLocaleString('es-CL', { maximumFractionDigits: 3 });

export const estadoCambioPrecio: Record<string, [Tono, string]> = {
    por_aprobar: ['alerta', 'Por aprobar'],
    aplicado: ['ok', 'Aplicado'],
    rechazado: ['error', 'Rechazado'],
};

export const estadoDespacho: Record<string, [Tono, string]> = {
    por_aprobar: ['alerta', 'Por aprobar'],
    programado: ['neutro', 'Programado'],
    en_ruta: ['info', 'En ruta'],
    entregado: ['ok', 'Entregado'],
    fallido: ['error', 'Entrega fallida'],
    anulado: ['neutro', 'Anulado'],
};

export const estadoDevolucion: Record<string, [Tono, string]> = {
    solicitada: ['alerta', 'Por aprobar'],
    aprobada: ['ok', 'Aprobada'],
    rechazada: ['error', 'Rechazada'],
};

export const estadoOC: Record<string, [Tono, string]> = {
    borrador: ['neutro', 'Borrador'],
    por_aprobar: ['alerta', 'Por aprobar'],
    aprobada: ['info', 'Aprobada'],
    parcial: ['alerta', 'Recibida en parte'],
    recibida: ['ok', 'Recibida'],
    cerrada: ['neutro', 'Cerrada'],
    anulada: ['neutro', 'Anulada'],
    rechazada: ['error', 'Rechazada'],
};

export const estadoFacturaProveedor: Record<string, [Tono, string]> = {
    por_revisar: ['alerta', 'Por revisar'],
    por_pagar: ['info', 'Por pagar'],
    pagada: ['ok', 'Pagada'],
    rechazada: ['error', 'Rechazada'],
};

export const estadoPagoProveedor: Record<string, [Tono, string]> = {
    por_aprobar: ['alerta', 'Por aprobar'],
    aplicado: ['ok', 'Aplicado'],
    rechazado: ['error', 'Rechazado'],
};

/* RRHH */
export const estadoContrato: Record<string, [Tono, string]> = {
    borrador: ['neutro', 'Borrador'],
    por_firmar: ['alerta', 'Por firmar'],
    vigente: ['ok', 'Vigente'],
    terminado: ['neutro', 'Terminado'],
};

export const estadoAusencia: Record<string, [Tono, string]> = {
    solicitada: ['alerta', 'Solicitada'],
    aprobada: ['ok', 'Aprobada'],
    rechazada: ['error', 'Rechazada'],
    anulada: ['neutro', 'Anulada'],
};

export const estadoNomina: Record<string, [Tono, string]> = {
    borrador: ['alerta', 'Borrador'],
    aprobada: ['info', 'Aprobada'],
    pagada: ['ok', 'Pagada'],
};

export const estadoPrestamo: Record<string, [Tono, string]> = {
    por_aprobar: ['alerta', 'Por aprobar'],
    vigente: ['info', 'Vigente'],
    pagado: ['ok', 'Pagado'],
    rechazado: ['error', 'Rechazado'],
};

export const estadoFiniquito: Record<string, [Tono, string]> = {
    borrador: ['alerta', 'Por aprobar'],
    aprobado: ['info', 'Aprobado'],
    firmado: ['ok', 'Firmado y pagado'],
    anulado: ['neutro', 'Anulado'],
};

export const estadoAgente: Record<string, [Tono, string]> = {
    disponible: ['ok', 'Disponible'],
    en_llamada: ['info', 'En llamada'],
    pausa: ['alerta', 'En pausa'],
    desconectado: ['neutro', 'Desconectado'],
};

export const estadoCampana: Record<string, [Tono, string]> = {
    borrador: ['neutro', 'Borrador'],
    activa: ['ok', 'Activa'],
    pausada: ['alerta', 'Pausada'],
    terminada: ['neutro', 'Terminada'],
};

export const estadoLead: Record<string, [Tono, string]> = {
    nuevo: ['neutro', 'Nuevo'],
    asignado: ['info', 'Asignado'],
    contactado: ['info', 'Contactado'],
    interesado: ['alerta', 'Interesado'],
    cotizado: ['alerta', 'Cotizado'],
    ganado: ['ok', 'Ganado'],
    perdido: ['error', 'Perdido'],
    no_contactar: ['error', 'No contactar'],
};

export const estadoLlamada: Record<string, [Tono, string]> = {
    marcando: ['alerta', 'Marcando'],
    en_cola: ['alerta', 'En cola'],
    en_curso: ['info', 'En curso'],
    terminada: ['ok', 'Terminada'],
    no_contesta: ['neutro', 'No contesta'],
    ocupado: ['neutro', 'Ocupado'],
    fallida: ['error', 'Fallida'],
};

export const DIAS_SEMANA = ['', 'Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];

/** Segundos como m:ss o h:mm:ss. */
export function duracion(seg: number): string {
    const h = Math.floor(seg / 3600);
    const m = Math.floor((seg % 3600) / 60);
    const s = String(seg % 60).padStart(2, '0');
    return h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
}
