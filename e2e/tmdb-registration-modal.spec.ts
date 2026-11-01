import { loginAs } from './support/login.js';
import { test, expect, movie, results, search, ui } from './support/tmdb-search.js';

test.use({ serviceWorkers: 'block' });
test.beforeEach(async ({ page, tmdb }) => {
    await loginAs(page, 'e2e-admin@example.test', '/admin');
    await page.goto('/admin/tmdb/search');
    tmdb.reply('確認', { json: results([
        { ...movie(230, '確認作品'), overview: 'あ'.repeat(101) + '\n全文の末尾', genres: [{ id: 28, name: 'アクション' }] },
        { ...movie(231, '別作品'), overview: null },
    ]) });
    await search(page, '確認');
    await expect(ui(page).cards).toHaveCount(2);
});

for (const key of ['Enter', 'Space', 'click']) {
    test(`カードの${key}でモーダルと初期フォーカスが成立する`, async ({ page }) => {
        // Arrange：カード全体を覆う標準ボタンを対象にする。
        const trigger = ui(page).cards.first().getByRole('button');
        // Act：実際のマウスまたはキーボード操作で起動する。
        if (key === 'click') await trigger.click();
        else { await trigger.focus(); await trigger.press(key); }
        // Assert：単一のネイティブモーダルと見出しへのフォーカスを確認する。
        const dialog = page.getByRole('dialog');
        await expect(dialog).toHaveCount(1);
        await expect(dialog).toHaveAttribute('open', '');
        expect(await dialog.evaluate(el => el.matches(':modal'))).toBe(true);
        await expect(dialog).toHaveAttribute('aria-labelledby', 'tmdb-registration-title');
        await expect(page.locator('#tmdb-registration-title')).toBeFocused();
        await expect(page.locator('#tmdb-registration-movie-id')).toHaveText('230');
    });
}

test('全文・カテゴリー・編集破棄と各終了操作を確認する', async ({ page, tmdb }) => {
    // Arrange：固定候補を名称と順序で検証する。
    const categories = ['アクション', 'アドベンチャー', 'アニメーション', 'コメディ', 'ヒューマンドラマ', 'SF', 'ファンタジー', 'ホラー', 'サスペンス・ミステリー', '恋愛', 'ドキュメンタリー', 'その他'];
    const trigger = ui(page).cards.first().getByRole('button');
    const dialog = page.getByRole('dialog');
    const title = page.getByLabel('作品タイトル', { exact: true });
    const overview = page.getByLabel('概要', { exact: true });
    const category = page.getByLabel('アプリ独自カテゴリー');
    for (const close of ['閉じる', 'キャンセル', 'Escape']) {
        // Act：再表示のたびに元データを確認してから編集する。
        await trigger.click();
        await expect(title).toHaveValue('確認作品');
        await expect(overview).toHaveValue('あ'.repeat(101) + '\n全文の末尾');
        await expect(category).toHaveValue('');
        expect(await category.locator('option').evaluateAll(options => options.slice(1).map(o => ({ text: o.textContent, value: (o as HTMLOptionElement).value })))).toEqual(categories.map(name => ({ text: name, value: name })));
        await title.fill('編集済み');
        await overview.fill('変更');
        await category.selectOption('SF');
        await title.press('Enter');
        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('button', { name: '登録', exact: true })).toBeDisabled();
        if (close === 'Escape') await page.keyboard.press('Escape');
        else await dialog.getByRole('button', { name: close, exact: true }).click();
        // Assert：終了後は起動元へ戻り、追加通信を発生させない。
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
        expect(tmdb.searches).toBe(1);
    }
    await ui(page).cards.nth(1).getByRole('button').click();
    await expect(title).toHaveValue('別作品');
    await expect(overview).toHaveValue('');
    await expect(category).toHaveValue('');
});

test('TabとShift+Tabの複数周のフォーカスを記録する', async ({ page }, testInfo) => {
    // Arrange：見出しから開始し、DOM内の観測値だけを記録する。
    await ui(page).cards.first().getByRole('button').click();
    const observations = [];
    const order = ['close', 'editable-title', 'editable-overview', 'category', 'cancel'].map(suffix => 'tmdb-registration-' + suffix);
    // Act：両方向へ各18回移動する。ブラウザUIの行き先はこの計測では特定しない。
    for (const key of ['Tab', 'Shift+Tab']) {
        await page.locator('#tmdb-registration-title').focus();
        for (let step = 0; step < 18; step++) {
            await page.keyboard.press(key);
            observations.push(await page.evaluate(({ key, step }) => {
                const dialog = document.querySelector('dialog')!;
                const active = document.activeElement;
                return { key, step, tag: active?.tagName, id: active?.id, inside: dialog.contains(active), background: active === document.querySelector('#tmdb-query'), modal: dialog.matches(':modal'), hasFocus: document.hasFocus() };
            }, { key, step }));
        }
    }
    // Assert：失敗しても全観測値を残し、無効ボタンと背景への到達を検出する。
    await testInfo.attach('focus-observations', { body: JSON.stringify(observations, null, 2), contentType: 'application/json' });
    for (const state of observations) {
        const index = state.key === 'Tab' ? state.step % order.length : order.length - 1 - state.step % order.length;
        expect.soft(state.id, JSON.stringify(state)).toBe(order[index]);
        expect.soft(state.hasFocus).toBe(true);
        expect.soft(state.inside, JSON.stringify(state)).toBe(true);
        expect.soft(state.background).toBe(false);
        expect.soft(state.modal).toBe(true);
        expect.soft(state.id).not.toBe('tmdb-registration-submit');
    }
});

test('背景クリックとプログラム的focusでも検索入力を操作できない', async ({ page }) => {
    // Arrange：背景の余白が確保できるPC画面を使用する。
    await page.setViewportSize({ width: 1280, height: 900 });
    await ui(page).cards.first().getByRole('button').click();
    // Act：プログラム的focusとキー入力を試みる。
    await ui(page).input.evaluate(el => el.focus());
    await page.keyboard.type('background');
    // Assert：検索入力にフォーカス・入力が届かない。
    await expect(ui(page).input).not.toBeFocused();
    await expect(ui(page).input).toHaveValue('確認');
    // Arrange：開示後の矩形から、画面内かつdialog外の余白を算出する。
    const dialog = page.getByRole('dialog');
    await page.getByLabel('作品タイトル', { exact: true }).fill('保持する編集');
    const box = await dialog.boundingBox();
    expect(box).not.toBeNull();
    const viewport = page.viewportSize()!;
    expect(box!.x).toBeGreaterThan(0);
    const point = { x: box!.x / 2, y: box!.y + box!.height / 2 };
    expect(point.x).toBeGreaterThan(0);
    expect(point.x).toBeLessThan(viewport.width);
    expect(point.y).toBeGreaterThan(0);
    expect(point.y).toBeLessThan(viewport.height);
    expect(point.x).toBeLessThan(box!.x);
    // Act：保証した背景座標を押し、文字入力を試みる。
    await page.mouse.click(point.x, point.y);
    await page.keyboard.type('blocked');
    // Assert：背景クリックで閉じず、背景の値も変わらない。
    await expect(dialog).toBeVisible();
    expect(await dialog.evaluate(el => el.matches(':modal'))).toBe(true);
    await expect(page.getByLabel('作品タイトル', { exact: true })).toHaveValue('保持する編集');
    await expect(ui(page).input).not.toBeFocused();
    await expect(ui(page).input).toHaveValue('確認');
    await page.keyboard.press('Escape');
    await expect(ui(page).cards.first().getByRole('button')).toBeFocused();
});

test('同じ画像要素で繰り返し失敗してもNo Imageへ戻る', async ({ page, tmdb }) => {
    // Arrange：異なる2作品の画像をいずれも通信失敗にする。
    const urls = [1, 2].map(n => `https://image.tmdb.org/t/p/w342/failure-${n}.jpg`);
    urls.forEach(url => tmdb.poster(url, 'abort'));
    tmdb.reply('画像失敗', { json: results(urls.map((url, i) => movie(i + 1, `失敗${i}`, url))) });
    await search(page, '画像失敗');
    const image = page.locator('#tmdb-registration-poster');
    const original = await image.elementHandle();
    for (let i = 0; i < 2; i++) {
        // Act：画像を使い回しながら別作品を開く。
        await ui(page).cards.nth(i).getByRole('button').click();
        // Assert：毎回フォールバックし、同一ノードを維持する。
        await expect(image).toHaveAttribute('src', /\/images\/no-image\.png$/);
        await expect(image).toHaveAttribute('alt', 'ポスター画像なし');
        expect(await image.evaluate((el, previous) => el === previous, original)).toBe(true);
        await page.keyboard.press('Escape');
    }
    await original?.dispose();
});

test('小画面で長文とXSS風文字列を安全に編集できる', async ({ page, tmdb }) => {
    // Arrange：画面幅と非信頼文字列を用意する。実行されればハーネスがdialog違反を検出する。
    await page.setViewportSize({ width: 375, height: 667 });
    const payload = '<img src=x onerror=alert(1)>';
    const full = (payload + '\n').repeat(100);
    tmdb.reply('安全表示', { json: results([{ ...movie(240, payload), overview: full, genres: [{ id: 1, name: payload }] }]) });
    await search(page, '安全表示');
    // Act：狭い画面でカードを開き、長文を編集する。
    await ui(page).cards.first().getByRole('button').click();
    const dialog = page.getByRole('dialog');
    // Assert：HTMLを生成せず、全文と参照情報を文字として表示する。
    await expect(page.getByLabel('作品タイトル', { exact: true })).toHaveValue(payload);
    await expect(page.getByLabel('概要', { exact: true })).toHaveValue(full);
    await expect(page.locator('#tmdb-registration-genres')).toHaveText(payload);
    await expect(dialog.locator('[onerror], script')).toHaveCount(0);
    await expect(page.locator('#tmdb-registration-poster')).toHaveAttribute('src', /\/images\/no-image\.png$/);
    expect(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);
    expect(await dialog.evaluate(el => el.scrollHeight > el.clientHeight)).toBe(true);
    await page.getByLabel('概要', { exact: true }).fill('編集');
    await dialog.getByRole('button', { name: 'キャンセル', exact: true }).click();
    await expect(dialog).toBeHidden();
    await ui(page).cards.first().getByRole('button').click();
    await expect(page.getByLabel('概要', { exact: true })).toHaveValue(full);
});

test('Tab停止が非表示・無効の場合も現在の候補だけで循環する', async ({ page }) => {
    // Arrange：モーダルを開き、先頭を非表示、末尾を無効にする例外状態を用意する。
    await ui(page).cards.first().getByRole('button').click();
    await page.locator('#tmdb-registration-close').evaluate(el => { (el as HTMLElement).hidden = true; el.style.display = 'none'; });
    await page.locator('#tmdb-registration-cancel').evaluate(el => { (el as HTMLButtonElement).disabled = true; });
    // Act：残る末尾から前進し、先頭から逆進する。
    await page.getByLabel('アプリ独自カテゴリー').focus();
    await page.keyboard.press('Tab');
    // Assert：非表示・無効要素を除外した端点で循環する。
    await expect(page.getByLabel('作品タイトル', { exact: true })).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(page.getByLabel('アプリ独自カテゴリー')).toBeFocused();

    // Arrange：すべての操作要素を無効にしてTab列を空にする。
    await page.getByRole('dialog').locator('input, textarea, select').evaluateAll(elements => {
        elements.forEach(el => { (el as HTMLInputElement).disabled = true; });
    });
    await page.locator('#tmdb-registration-title').focus();
    // Act／Assert：両方向とも見出しに留まり、Escapeで通常どおり終了できる。
    for (const key of ['Tab', 'Shift+Tab']) {
        await page.keyboard.press(key);
        await expect(page.locator('#tmdb-registration-title')).toBeFocused();
    }
    await page.keyboard.press('Escape');
    await expect(ui(page).cards.first().getByRole('button')).toBeFocused();
});

test('カードの画像・見出し・概要・余白の実座標で起動できる', async ({ page }) => {
    // Arrange：各位置を押す直前に矩形を取り直す。
    const card = ui(page).cards.first();
    for (const target of [card.getByRole('img'), card.getByRole('heading'), card.locator('p').last(), card]) {
        await target.scrollIntoViewIfNeeded();
        const box = await target.boundingBox();
        expect(box).not.toBeNull();
        const viewport = page.viewportSize()!;
        const point = target === card ? { x: box!.x + box!.width - 8, y: box!.y + box!.height - 8 }
            : { x: box!.x + box!.width / 2, y: box!.y + box!.height / 2 };
        expect(point.x).toBeGreaterThanOrEqual(0);
        expect(point.y).toBeGreaterThanOrEqual(0);
        expect(point.x).toBeLessThan(viewport.width);
        expect(point.y).toBeLessThan(viewport.height);
        // Act：オーバーレイを迂回せず、実座標をクリックする。
        await page.mouse.click(point.x, point.y);
        // Assert：同一ページで正しい作品を開き、次の操作前に閉じる。
        await expect(page.locator('#tmdb-registration-movie-id')).toHaveText('230');
        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(page).toHaveURL('/admin/tmdb/search');
        await page.keyboard.press('Escape');
    }
});

test('カードのTab停止は各1回で全面のフォーカス枠が見える', async ({ page }) => {
    // Arrange：標準の検索ボタンから実Tab操作でカードへ進む。
    await ui(page).button.focus();
    for (const [index, name] of ['確認作品', '別作品'].entries()) {
        const card = ui(page).cards.nth(index);
        const button = card.getByRole('button', { name: `「${name}」の登録内容を確認`, exact: true });
        // Act：カードごとに1回のTabで移動する。
        await page.keyboard.press('Tab');
        // Assert：兄弟構造、操作対象数、表示された枠とカード全面の一致を確認する。
        await expect(button).toBeFocused();
        await expect(card.getByRole('button')).toHaveCount(1);
        expect(await card.evaluate(el => el.tagName)).toBe('ARTICLE');
        expect(await button.evaluate(el => el.parentElement?.tagName)).toBe('ARTICLE');
        await expect(card.locator('div > h2')).toHaveCount(1);
        const style = await button.evaluate(el => {
            const css = getComputedStyle(el);
            return { visible: el.matches(':focus-visible'), width: parseFloat(css.outlineWidth), style: css.outlineStyle, color: css.outlineColor, opacity: css.opacity };
        });
        expect(style.visible).toBe(true);
        expect(style.width).toBeGreaterThanOrEqual(2);
        expect(style.style).toBe('solid');
        expect(style.color).not.toBe('rgba(0, 0, 0, 0)');
        expect(style.opacity).toBe('1');
        const outer = await card.boundingBox();
        const inner = await button.boundingBox();
        expect(outer).not.toBeNull();
        expect(inner).not.toBeNull();
        expect(Math.abs(outer!.width - inner!.width)).toBeLessThanOrEqual(2);
        expect(Math.abs(outer!.height - inner!.height)).toBeLessThanOrEqual(2);
    }
});

test.describe('タッチ操作', () => {
    test.use({ hasTouch: true, viewport: { width: 390, height: 844 } });
    test('タッチ対応contextでカードをタップして開く', async ({ page }) => {
        // Arrange：タッチ機能と操作直前の表示矩形を確認する。
        expect(await page.evaluate(() => navigator.maxTouchPoints)).toBeGreaterThan(0);
        const button = ui(page).cards.first().getByRole('button');
        await button.scrollIntoViewIfNeeded();
        const box = await button.boundingBox();
        expect(box).not.toBeNull();
        const viewport = page.viewportSize()!;
        const point = { x: box!.x + box!.width / 2, y: Math.max(0, box!.y) + Math.min(box!.height, viewport.height - Math.max(0, box!.y)) / 2 };
        expect(point.x).toBeGreaterThan(0);
        expect(point.x).toBeLessThan(viewport.width);
        expect(point.y).toBeGreaterThan(0);
        expect(point.y).toBeLessThan(viewport.height);
        // Act：マウス操作の代用ではなくtouchscreenでタップする。
        await page.touchscreen.tap(point.x, point.y);
        // Assert：選択作品のモーダルを同じページで開く。
        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(page.locator('#tmdb-registration-movie-id')).toHaveText('230');
        await expect(page).toHaveURL('/admin/tmdb/search');
    });
});
