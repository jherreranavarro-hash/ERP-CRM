import axios from 'axios';

/** Cliente HTTP para llamadas JSON del propio sitio (usa la cookie XSRF de Laravel). */
export const http = axios.create({ headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } });
