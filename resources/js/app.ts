import '../css/app.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import AppLayout from '@/Layouts/AppLayout.vue';

createInertiaApp({
    title: (t) => (t ? `${t} · OBMAX` : 'OBMAX · Sistema de Gestión'),
    resolve: async (name) => {
        const page = await resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob<DefineComponent>('./Pages/**/*.vue'));
        // Layout por defecto: las páginas de Auth/ y Error usan el suyo.
        if (!name.startsWith('Auth/') && name !== 'Error') page.default.layout ??= AppLayout;
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#F7791E', showSpinner: false },
});
