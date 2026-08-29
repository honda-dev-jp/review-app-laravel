import { expect, type Page } from '@playwright/test';

// E2E fixtureで利用する全ユーザーは、UserFactoryの既定passwordと一致している必要がある。
const E2E_USER_PASSWORD = 'password';

export async function loginAs(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('メールアドレス', { exact: true }).fill(email);
    await page.getByLabel('パスワード', { exact: true }).fill(E2E_USER_PASSWORD);
    await page.getByRole('button', { name: 'ログイン', exact: true }).click();

    await expect(page).toHaveURL('/');
}
