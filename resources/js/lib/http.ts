import axios from 'axios';

/** Cliente HTTP para llamadas JSON del propio sitio (usa la cookie XSRF de Laravel). */
export const http = axios.create({ headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });

/** Arma una query string omitiendo valores vacíos. */
export function consulta(params: Record<string, unknown>): string {
    return new URLSearchParams(Object.entries(params).filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => [k, String(v)])).toString();
}
