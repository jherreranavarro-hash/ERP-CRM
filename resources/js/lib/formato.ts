/**
 * Formatos regionales aislados en un único lugar (prompt: "no hardcodear formatos
 * de RUT/moneda/fecha en el código de negocio"). Montos siempre en pesos enteros.
 */
const zona = 'America/Santiago';

export function pesos(monto: number | null | undefined): string {
    if (monto === null || monto === undefined) return '—';
    const entero = Math.round(monto);
    // Nunca "−$0": el cero no lleva signo.
    const signo = entero < 0 ? '−' : '';
    return `${signo}$${Math.abs(entero).toLocaleString('es-CL', { maximumFractionDigits: 0 })}`;
}

export function numero(n: number | null | undefined): string {
    return n === null || n === undefined ? '—' : n.toLocaleString('es-CL');
}

export function fecha(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('es-CL', { timeZone: zona, day: '2-digit', month: '2-digit', year: 'numeric' });
}

export function fechaHora(iso: string | null | undefined): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('es-CL', { timeZone: zona, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export function haceCuanto(iso: string | null | undefined): string {
    if (!iso) return 'nunca';
    const seg = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (seg < 60) return 'hace un momento';
    if (seg < 3600) return `hace ${Math.floor(seg / 60)} min`;
    if (seg < 86400) return `hace ${Math.floor(seg / 3600)} h`;
    return `hace ${Math.floor(seg / 86400)} días`;
}

export function rut(valor: string): string {
    const limpio = valor.replace(/[^0-9kK]/g, '').toUpperCase();
    if (limpio.length < 2) return limpio;
    const cuerpo = limpio.slice(0, -1);
    return `${Number(cuerpo).toLocaleString('es-CL')}-${limpio.slice(-1)}`;
}
