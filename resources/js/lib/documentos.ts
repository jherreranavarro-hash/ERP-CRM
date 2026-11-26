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
