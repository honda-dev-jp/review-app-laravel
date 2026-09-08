import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ command, mode }) => {
    const config = {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.js',
                ],
                refresh: true,
            }),
        ],
    };

    if (command !== 'serve') {
        return config;
    }

    const { APP_URL } = loadEnv(mode, process.cwd(), 'APP_URL');

    if (!APP_URL) {
        return config;
    }

    let appUrl;

    try {
        appUrl = new URL(APP_URL);
    } catch {
        throw new Error('APP_URL is not a valid URL origin.');
    }

    if (!['http:', 'https:'].includes(appUrl.protocol) || appUrl.origin !== APP_URL) {
        throw new Error('APP_URL must be an HTTP(S) origin without a path, query, fragment, or credentials.');
    }

    config.server = {
        hmr: {
            host: appUrl.hostname,
        },
    };

    return config;
});
