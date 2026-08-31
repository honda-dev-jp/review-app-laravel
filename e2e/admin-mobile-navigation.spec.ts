import { test, expect } from '@playwright/test';

test.use({ viewport: { width: 390, height: 844 } });

test('モバイル管理メニューを開き、Escapeで閉じる', async ({ page }) => {
    await page.goto('/admin');

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

    await page.keyboard.press('Escape');

    await expect(mobileMenu).toBeHidden();
    await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
});
