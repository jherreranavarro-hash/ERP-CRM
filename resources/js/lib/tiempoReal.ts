import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export interface Conexion { key: string; host: string; port: number; tls: boolean }

/** Crea la conexión WebSocket (Laravel Reverb) solo en las pantallas que la usan. */
export function conectar(c: Conexion): Echo<'reverb'> {
    (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;
    const xsrf = decodeURIComponent(document.cookie.split('; ').find((x) => x.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
    return new Echo({
        broadcaster: 'reverb',
        key: c.key,
        wsHost: c.host,
        wsPort: c.port,
        wssPort: c.port,
        forceTLS: c.tls,
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: { headers: { 'X-XSRF-TOKEN': xsrf, Accept: 'application/json' } },
    });
}
