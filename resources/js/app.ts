import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { configureEcho } from '@laravel/echo-vue';
import { i18n } from './i18n';
import { pruneLocalData } from './lib/realtime';

pruneLocalData();

// WebSocket-Verbindung URL-agnostisch: nimmt die Domain der Seite, sofern keine
// VITE_REVERB_*-Werte gesetzt sind. So läuft ein einziges Image an jeder Domain.
// Port 80/443 → nginx proxied den Reverb-WS auf demselben Port (kein Extra-Port nötig).
const isHttps = typeof window !== 'undefined' && window.location.protocol === 'https:';
const reverbEnvPort = Number(import.meta.env.VITE_REVERB_PORT) || 0;

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY || 'schulquizz',
    wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
    wsPort: reverbEnvPort || (isHttps ? 443 : 80),
    wssPort: reverbEnvPort || 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME || (isHttps ? 'https' : 'http')) === 'https',
    enabledTransports: ['ws', 'wss'],
});

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
