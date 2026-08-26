import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './e2e',
    workers: 1,

    use: {
        baseURL: 'http://localhost:83',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'off',
    },

    projects: [
        {
            name: 'chromium',
            use: {
                browserName: 'chromium',
            },
        },
    ],
});
