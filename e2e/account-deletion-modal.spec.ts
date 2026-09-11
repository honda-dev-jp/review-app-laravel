import { test, expect, type Locator, type Page } from '@playwright/test';

import { loginAs } from './support/login.js';

const ACCOUNT_DIALOG_NAME = 'アカウントを削除して本当に大丈夫ですか？';

async function openAccountDeletionDialog(page: Page): Promise<{
    dialog: Locator;
    trigger: Locator;
}> {
    const trigger = page.getByRole('button', {
        name: 'アカウントを削除',
        exact: true,
    });

    await trigger.click();

    const dialog = page.getByRole('dialog', { name: ACCOUNT_DIALOG_NAME });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAccessibleName(ACCOUNT_DIALOG_NAME);
    await expect(dialog).toHaveAttribute('aria-modal', 'true');

    return { dialog, trigger };
}

test.beforeEach(async ({ page }) => {
    await loginAs(page, 'e2e-verified@example.test');
    await page.goto('/profile');
});

test('初期フォーカスを設定し、Tabを循環させ、Escape後に起動元へ戻す', async ({ page }) => {
    const { dialog, trigger } = await openAccountDeletionDialog(page);
    const password = dialog.getByLabel('現在のパスワード');
    const cancel = dialog.getByRole('button', { name: 'キャンセル', exact: true });
    const deleteButton = dialog.getByRole('button', {
        name: 'アカウントを削除',
        exact: true,
    });

    await expect(password).toBeFocused();

    await page.keyboard.press('Shift+Tab');
    await expect(deleteButton).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(password).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(cancel).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(deleteButton).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(password).toBeFocused();

    await page.keyboard.press('Escape');

    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('キャンセル後に起動元へフォーカスを戻す', async ({ page }) => {
    const { dialog, trigger } = await openAccountDeletionDialog(page);

    await dialog.getByRole('button', { name: 'キャンセル', exact: true }).click();

    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('背景クリック後に起動元へフォーカスを戻す', async ({ page }) => {
    const { dialog, trigger } = await openAccountDeletionDialog(page);
    const box = await dialog.boundingBox();

    if (box === null || box.y <= 1) {
        throw new Error('退会モーダルの背景クリック位置を特定できませんでした。');
    }

    await page.mouse.click(box.x + box.width / 2, box.y / 2);

    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});
