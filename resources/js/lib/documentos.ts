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
