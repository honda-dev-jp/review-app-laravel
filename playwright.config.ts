import { defineConfig } from '@playwright/test';

const isCI = !!process.env.CI;

export default defineConfig({
    testDir: './e2e',
    workers: 1,
    retries: 0,
    timeout: 30_000,
    forbidOnly: isCI,
    reporter: isCI ? [['github'], ['list']] : undefined,

    use: {
        baseURL: isCI ? 'http://127.0.0.1:8000' : 'http://localhost:83',
        screenshot: 'only-on-failure',
        trace: isCI ? 'off' : 'retain-on-failure',
        video: 'off',
    },

    webServer: isCI
        ? {
              command: 'php artisan serve --host=127.0.0.1 --port=8000 --no-reload',
              url: 'http://127.0.0.1:8000/login',
              reuseExistingServer: false,
              timeout: 60_000,
          }
        : undefined,

    projects: [
        {
            name: 'setup',
            testMatch: /global\.setup\.ts/,
        },
        {
            name: 'chromium',
            dependencies: ['setup'],
            testIgnore: /global\.setup\.ts/,
            use: {
                browserName: 'chromium',
            },
        },
    ],
});
