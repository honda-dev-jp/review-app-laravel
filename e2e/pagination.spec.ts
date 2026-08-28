import { test, expect } from '@playwright/test';

import { loginAs } from './support/login.js';

test('作品一覧のページネーションから2ページ目へ遷移する', async ({ page }) => {
    await page.goto('/items');

    await expect(page.getByRole('heading', { name: '作品一覧', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'E2E Movie 11', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'E2E Movie 01', exact: true })).toBeHidden();

    const pagination = page.getByRole('navigation', { name: 'ページネーション' });
    await pagination.getByRole('link', { name: '次のページ', exact: true }).click();

    await expect(page).toHaveURL('/items?page=2');
    await expect(page.getByRole('heading', { name: 'E2E Movie 01', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'E2E Movie 11', exact: true })).toBeHidden();
});

test('本人レビュー一覧のページネーションから固定fixtureの2ページ目へ遷移する', async ({ page }) => {
    await loginAs(page, 'e2e-reviewer@example.test');
    await page.goto('/my-reviews');

    await expect(page.getByText('E2E Review 11', { exact: true })).toBeVisible();
    await expect(page.getByText('E2E Review 01', { exact: true })).toBeHidden();

    const pagination = page.getByRole('navigation', {
        name: 'Pagination Navigation',
        exact: true,
    });
    await pagination.getByRole('link', { name: '次 &raquo;', exact: true }).click();

    await expect(page).toHaveURL('/my-reviews?page=2');
    await expect(page.getByText('E2E Review 01', { exact: true })).toBeVisible();
    await expect(page.getByText('E2E Review 11', { exact: true })).toBeHidden();
});
