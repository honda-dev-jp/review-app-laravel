import { test, expect } from '@playwright/test';

import { loginAs } from './support/login.js';

test.use({ viewport: { width: 390, height: 844 } });

test('モバイル管理メニューを開き、Escapeで閉じる', async ({ page }) => {
    await loginAs(page, 'e2e-admin@example.test', '/admin');

    const menuButton = page.getByRole('button', {
        name: '管理メニューを開閉する',
        exact: true,
    });
    const mobileMenu = page.locator('#admin-mobile-menu');

    await expect(menuButton).toBeVisible();
    await expect(mobileMenu).toBeHidden();
    await expect(menuButton).toHaveAttribute('aria-expanded', 'false');

    await menuButton.click();

    await expect(mobileMenu).toBeVisible();
    await expect(menuButton).toHaveAttribute('aria-expanded', 'true');
    await expect(mobileMenu.getByText('ダッシュボード', { exact: true })).toBeVisible();
    await expect(mobileMenu.getByText('登録済み作品一覧', { exact: true })).toBeVisible();
    await expect(mobileMenu.getByText('TMDB検索', { exact: true })).toBeVisible();

    await expect(
        mobileMenu.getByRole('button', { name: 'ログアウト', exact: true }),
    ).toBeVisible();

    await page.keyboard.press('Escape');

    await expect(mobileMenu).toBeHidden();
    await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
});
