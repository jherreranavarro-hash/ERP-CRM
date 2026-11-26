/** Representación legible del valor de un parámetro. */
export function mostrarValor(valor: unknown, unidad?: string | null): string {
    if (valor === null || valor === undefined) return '—';
    if (typeof valor === 'boolean') return valor ? 'Sí' : 'No';
    if (Array.isArray(valor)) return valor.length ? valor.join(', ') + (unidad && typeof valor[0] === 'number' ? ` ${unidad}` : '') : '(vacío)';
    if (typeof valor === 'number') {
        const n = valor.toLocaleString('es-CL');
        if (unidad === '$') return `$${n}`;
        if (unidad === '%') return `${n} %`;
        return unidad ? `${n} ${unidad}` : n;
    }
    return String(valor) || '(vacío)';
}
