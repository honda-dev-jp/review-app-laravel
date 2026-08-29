import { test, expect } from '@playwright/test';

test('ログイン画面を表示できる', async ({ page }) => {
    await page.goto('/login');

    await expect(page.getByRole('button', { name: 'ログイン' })).toBeVisible();
});
