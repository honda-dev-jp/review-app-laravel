import { test, expect } from '@playwright/test';

test('一時検証: 失敗時screenshot artifactを確認する', async ({ page }) => {
    await page.goto('/login');

    expect(await page.title()).toBe('__E2E_ARTIFACT_FAILURE_PROBE__');
});
