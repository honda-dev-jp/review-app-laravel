import { test, expect, type Locator, type Page } from '@playwright/test';

import { loginAs } from './support/login.js';

const REVIEW_DIALOG_NAME = 'レビューを削除しますか？';
const FIRST_REVIEW_BODY = 'E2E Review 11';
const TARGET_REVIEW_BODY = 'E2E Review 06';

function reviewArticle(page: Page, reviewBody: string): Locator {
    return page.getByRole('article').filter({
        has: page.getByText(reviewBody, { exact: true }),
    });
}

async function openReviewDeletionDialog(page: Page): Promise<{
    article: Locator;
    dialog: Locator;
    trigger: Locator;
}> {
    const article = reviewArticle(page, TARGET_REVIEW_BODY);
    await expect(article).toHaveCount(1);

    const trigger = article.getByRole('button', {
        name: 'レビューを削除する',
        exact: true,
    });
    await trigger.click();

    const dialog = article.getByRole('dialog', { name: REVIEW_DIALOG_NAME });
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAccessibleName(REVIEW_DIALOG_NAME);

    return { article, dialog, trigger };
}

test.beforeEach(async ({ page }) => {
    await loginAs(page, 'e2e-reviewer@example.test');
    await page.goto('/my-reviews');
});

test('対象レビューだけを開き、フォーカスを循環させ、Escape後に同じ起動元へ戻す', async ({ page }) => {
    const otherArticle = reviewArticle(page, FIRST_REVIEW_BODY);
    await expect(otherArticle).toHaveCount(1);

    const otherDialog = otherArticle.getByRole('dialog', {
        name: REVIEW_DIALOG_NAME,
        includeHidden: true,
    });
    const otherTrigger = otherArticle.getByRole('button', {
        name: 'レビューを削除する',
        exact: true,
    });
    await expect(otherDialog).toHaveCount(1);

    const { dialog, trigger } = await openReviewDeletionDialog(page);
    const closeButton = dialog.getByRole('button', { name: '閉じる', exact: true });
    const cancel = dialog.getByRole('button', { name: 'キャンセル', exact: true });
    const deleteButton = dialog.getByRole('button', { name: '削除する', exact: true });

    await expect(page.getByRole('dialog')).toHaveCount(1);
    await expect(otherDialog).toBeHidden();
    await expect(cancel).toBeFocused();

    await page.keyboard.press('Shift+Tab');
    await expect(closeButton).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(deleteButton).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(closeButton).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(cancel).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(deleteButton).toBeFocused();

    await page.keyboard.press('Escape');

    await expect(dialog).toBeHidden();
    await expect(otherDialog).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(otherTrigger).not.toBeFocused();
});

test('キャンセル後に操作したレビューの起動元へフォーカスを戻す', async ({ page }) => {
    const { dialog, trigger } = await openReviewDeletionDialog(page);

    await dialog.getByRole('button', { name: 'キャンセル', exact: true }).click();

    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('閉じるボタン後に操作したレビューの起動元へフォーカスを戻す', async ({ page }) => {
    const { dialog, trigger } = await openReviewDeletionDialog(page);

    await dialog.getByRole('button', { name: '閉じる', exact: true }).click();

    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});

test('背景クリック後に操作したレビューの起動元へフォーカスを戻す', async ({ page }) => {
    const { dialog, trigger } = await openReviewDeletionDialog(page);

    await dialog.click({ position: { x: 4, y: 4 } });

    await expect(dialog).toBeHidden();
    await expect(trigger).toBeFocused();
});
