import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const vitePort = parseInt(env.VITE_PORT || '5173');
    const appUrl = new URL(env.APP_URL || 'http://localhost');
    // Remote dev: serve the Vite dev server behind a reverse proxy by setting
    // VITE_DEV_ORIGIN (e.g. https://dm-vite.example.com). Unset = local dev.
    const devOrigin = env.VITE_DEV_ORIGIN ? new URL(env.VITE_DEV_ORIGIN) : null;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port: 5173,
            // Behind a reverse proxy (VITE_DEV_ORIGIN set), assets and the HMR
            // socket must use the public origin; otherwise fall back to local dev.
            ...(devOrigin
                ? {
                      origin: devOrigin.origin,
                      cors: { origin: appUrl.origin },
                      allowedHosts: [devOrigin.hostname],
                      hmr: {
                          protocol: devOrigin.protocol === 'https:' ? 'wss' : 'ws',
                          host: devOrigin.hostname,
                          clientPort: devOrigin.protocol === 'https:' ? 443 : 80,
                      },
                  }
                : {
                      hmr: {
                          host: appUrl.hostname,
                          clientPort: vitePort,
                      },
                  }),
            watch: {
                // Vite already ignores node_modules by default, but not vendor.
                // Without this, vendor's ~8k files exhaust the inotify watcher limit in Docker.
                ignored: [
                        '**/vendor/**',
                        '**/storage/framework/views/**',
                    ],
            },
        },
    };
});
