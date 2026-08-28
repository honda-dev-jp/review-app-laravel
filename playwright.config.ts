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
