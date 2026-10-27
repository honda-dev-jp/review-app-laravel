import { loginAs } from './support/login.js';
import { test, expect, expectReady, movie, results, search, ui } from './support/tmdb-search.js';

// Service Worker経由でrouteを迂回させない。このspec以外の既存設定には影響させない。
test.use({ serviceWorkers: 'block' });

test.beforeEach(async ({ page }) => {
    await loginAs(page, 'e2e-admin@example.test', '/admin');
});

// 目的：実Axiosの非同期検索、二重送信防止、入力境界での切り詰め防止とキーボード復帰を保証する。
test('正常検索は遷移せずカードを表示し、処理中の連打を抑止する', async ({ page, tmdb }) => {
    // Arrange：PCナビから移動し、制御可能な保留応答と合成ポスターを準備する。
    const navigation = page.getByRole('navigation', { name: '管理画面ナビゲーション', exact: true });
    await navigation.getByRole('link', { name: 'TMDB検索', exact: true }).click();
    await expect(navigation.getByRole('link', { name: 'TMDB検索', exact: true })).toHaveAttribute('aria-current', 'page');
    const poster = 'https://image.tmdb.org/t/p/w342/e2e-normal.png';
    tmdb.poster(poster);
    const pending = tmdb.hold('正常検索');
    const view = ui(page);
    let navigations = 0;
    page.on('framenavigated', (frame) => { if (frame === page.mainFrame()) navigations++; });

    // Act：Enter送信後、無効化中のクリック・Enter・直接submitを重ねる。
    await search(page, '正常検索');
    await expect.poll(pending.received).toBe(true);
    await expect(view.input).toBeDisabled();
    await expect(page.locator('#tmdb-search-button')).toBeDisabled();
    await expect(view.status).toHaveText('検索中...');
    await expect(view.results).toHaveAttribute('aria-busy', 'true');
    // 自動待機するclickでは無効化解除後をクリックしてしまうため、disabled要素のDOM clickを使う。
    await page.locator('#tmdb-search-button').evaluate((button: HTMLButtonElement) => { button.click(); button.click(); });
    await page.keyboard.press('Enter');
    await page.locator('#tmdb-search-form').evaluate((form) => {
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    await pending.release({ json: results([movie(1, '正常作品', poster)]) });

    // Assert：1回だけ通信し、同じ文書にカード・画像・状態を描画して操作を戻す。
    await expect(view.cards).toHaveCount(1);
    await expect(view.cards.getByRole('heading', { name: '正常作品', exact: true })).toBeVisible();
    const image = view.cards.getByRole('img', { name: '作品「正常作品」のポスター', exact: true });
    await expect(image).toHaveAttribute('src', poster);
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);
    await expect(view.status).toHaveText('1件の作品を表示しました。');
    await expectReady(page);
    await expect(view.input).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(view.button).toBeFocused();
    await expect(page).toHaveURL('/admin/tmdb/search');
    expect(navigations).toBe(0);
    expect(tmdb.searches).toBe(1);

    // Arrange：正式な文字数判定はFeatureへ任せ、ブラウザでは入力値と送信値だけを検証する。
    // 補助平面文字は1文字がUTF-16の2単位となるため、100/101文字は200/202単位になる。
    const boundaries = [
        { name: '前後全角空白付き100文字', input: `　${'あ'.repeat(100)}　`, query: 'あ'.repeat(100) },
        { name: '通常文字101文字', input: 'あ'.repeat(101), query: 'あ'.repeat(101) },
        { name: '補助平面文字100文字', input: '😀'.repeat(100), query: '😀'.repeat(100) },
        { name: '補助平面文字101文字', input: '😀'.repeat(101), query: '😀'.repeat(101) },
    ];
    await expect(view.input).not.toHaveAttribute('maxlength');
    for (const boundary of boundaries) {
        await test.step(`入力・送信を切り詰めない：${boundary.name}`, async () => {
            const response = tmdb.hold(boundary.query);

            // Act：DOMへのvalue代入ではなく文字入力操作を行い、maxlengthが残った場合の切り詰めも検出する。
            await view.input.fill('');
            await view.input.pressSequentially(boundary.input);
            await expect(view.input).toHaveValue(boundary.input);
            await view.input.press('Enter');

            // Assert：モック到達時のURL照合で、前後空白だけを除いた検索語が全量送信されたことを保証する。
            // 101文字にも同じ合成応答を返す。これはUI復帰用であり、Laravelが許可したという検証ではない。
            await expect.poll(response.received).toBe(true);
            await response.release({ json: results([]) });
            await expect(view.status).toHaveText('該当作品なし');
            await expectReady(page);
            await expect(view.input).toHaveValue(boundary.input);
        });
    }
    expect(tmdb.searches).toBe(1 + boundaries.length);
    expect(navigations).toBe(0);
});

// 目的：部分成功・0件・再検索の表示切替と、検索開始時の前回結果消去を保証する。
test('部分成功と0件を区別し、画像なしでも再検索できる', async ({ page, tmdb }) => {
    // Arrange：部分成功、0件、成功の順に合成応答を用意する。
    await page.goto('/admin/tmdb/search');
    tmdb.reply('部分成功', { json: results([movie(1, '画像なし作品')], 2) });
    const empty = tmdb.hold('0件');
    tmdb.reply('再検索', { json: results([movie(2, '再検索作品')]) });
    const view = ui(page);

    // Act：部分成功を表示してから、次の検索を応答前で止める。
    await search(page, '部分成功');
    await expect(view.status).toHaveText('2件中1件を表示しました。1件はデータ形式が不正だったため除外しました。');
    await expect(view.cards).toHaveCount(1);
    await expect(view.cards.getByRole('img', { name: 'ポスター画像なし', exact: true })).toHaveAttribute('src', '/images/no-image.png');
    await expectReady(page);
    await search(page, '0件');
    await expect.poll(empty.received).toBe(true);
    await expect(view.cards).toHaveCount(0);
    // 別の操作対象へフォーカスを移した場合、検索完了がフォーカスを奪わないことも確認する。
    const dashboard = page.getByRole('navigation', { name: '管理画面ナビゲーション', exact: true }).getByRole('link', { name: 'ダッシュボード', exact: true });
    await dashboard.focus();
    await empty.release({ json: results([]) });
    await expect(view.status).toHaveText('該当作品なし');
    await expectReady(page);
    await expect(dashboard).toBeFocused();
    await search(page, '再検索');

    // Assert：0件後も検索でき、今回のカードだけを表示する。
    await expect(view.cards).toHaveCount(1);
    await expect(view.cards.getByRole('heading', { name: '再検索作品', exact: true })).toBeVisible();
    await expect(view.status).toHaveText('1件の作品を表示しました。');
    await expectReady(page);
    expect(tmdb.searches).toBe(3);
});

// 目的：HTTPステータス変換はPHPUnitへ任せ、各応答を受けた画面内表示・復帰だけを確認する。
test('HTTPエラーを画面内に表示し、入力を保持して再検索できる', async ({ page, tmdb }) => {
    // Arrange：異なるエラーを同一シナリオ内のstepへまとめ、成功→失敗→復帰を各条件で確認する。
    await page.goto('/admin/tmdb/search');
    const cases = [
        { status: 401, message: 'ログインが必要です。再度ログインしてください。' },
        { status: 403, message: 'この機能を利用する権限がありません。' },
        { status: 422, message: '映画タイトルを1〜100文字で入力してください。' },
        { status: 502, message: '映画情報を取得できませんでした。' },
        { status: 503, message: 'しばらくしてから再度お試しください。' },
        { status: 503, message: '検索サービスを利用できません。' },
        { status: 503, message: '映画情報を取得できませんでした。' },
    ];
    const view = ui(page);
    tmdb.reply('初回', { json: results([movie(1, '前回作品')]) });
    await search(page, '初回');
    await expect(view.cards).toHaveCount(1);

    // Act：実Axiosへ各HTTPエラーを返し、続けて成功応答で再検索する。
    for (const [index, entry] of cases.entries()) {
        await test.step(`${entry.status}の表示と復帰 ${index + 1}`, async () => {
            const query = `エラー検索${index}`;
            const error = tmdb.hold(query);
            await search(page, query);
            await expect.poll(error.received).toBe(true);
            await expect(view.cards).toHaveCount(0);
            await error.release({ status: entry.status, json: {
                message: entry.message,
                ...(entry.status === 422 ? { errors: { query: [entry.message] } } : {}),
            } });

            // Assert：エラーの場所・ARIA・リンクと入力保持を確認し、alertダイアログや画面遷移は使わない。
            if (entry.status === 422) {
                await expect(view.queryError).toHaveAttribute('role', 'alert');
                await expect(view.queryError).toHaveText(entry.message);
                await expect(view.input).toHaveAttribute('aria-describedby', 'tmdb-query-error');
                await expect(view.input).toHaveAttribute('aria-invalid', 'true');
            } else {
                await expect(view.error).toHaveAttribute('role', 'alert');
                await expect(view.error).toContainText(entry.message);
                if (entry.status === 401) {
                    await expect(view.error.getByRole('link', { name: 'ログイン画面へ', exact: true })).toHaveAttribute('href', /\/login$/);
                } else {
                    await expect(view.error.getByRole('link')).toHaveCount(0);
                }
            }
            await expect(view.input).toHaveValue(query);
            await expectReady(page);
            const recovery = tmdb.hold(`復帰${index}`);
            await search(page, `復帰${index}`);
            await expect.poll(recovery.received).toBe(true);
            await expect(view.error).toBeHidden();
            await expect(view.queryError).toBeHidden();
            await recovery.release({ json: results([movie(2, '復帰作品')]) });
            await expect(view.cards.getByRole('heading', { name: '復帰作品', exact: true })).toBeVisible();
            await expectReady(page);
            await expect(view.input).not.toHaveAttribute('aria-invalid');
            await expect(page).toHaveURL('/admin/tmdb/search');
        });
    }
    expect(tmdb.searches).toBe(1 + cases.length * 2);
});

// 目的：疑似XSSと$&・$$を含む作品名を保持し、未知の502/503本文や例外情報を画面へ転用しない。
test('疑似XSSと未知のエラー本文を安全に扱う', async ({ page, tmdb }) => {
    // Arrange：HTMLとして解釈されると検出用属性が変わる合成文字列を用意する。
    await page.goto('/admin/tmdb/search');
    const payload = '<img src="/unexpected-xss" onerror="document.body.dataset.e2eXss=1">\'"</script>';
    // 文字列置換だと$&は一致部分、$$は単一の$へ変わるため、別々の作品でそのまま保たれるか確認する。
    const titles = [`${payload} $&`, `${payload} $$`];
    const poster = 'https://image.tmdb.org/t/p/w342/e2e-xss.png';
    tmdb.poster(poster);
    tmdb.reply('安全な描画', { json: results(titles.map((title, index) => ({
        ...movie(index + 1, title, poster), original_title: payload, overview: payload,
    }))) });
    const view = ui(page);

    // Act：合成文字列をカードへ描画した後、未知のJSON本文とHTMLエラーページを返す。
    await search(page, '安全な描画');
    // Assert：期待するaltは固定テンプレートから明示し、本番のreplace処理で計算しない。
    await expect(view.cards).toHaveCount(2);
    for (const title of titles) {
        const card = view.cards.filter({ has: page.getByRole('heading', { name: title, exact: true }) });
        await expect(card.getByRole('heading')).toHaveText(title);
        await expect(card.getByText(payload, { exact: true })).toHaveCount(2);
        await expect(card.getByRole('img')).toHaveAttribute('alt', `作品「${title}」のポスター`);
    }
    await expect(view.cards.locator('script, [onerror]')).toHaveCount(0);
    await expect(page.locator('body')).not.toHaveAttribute('data-e2e-xss');
    for (const status of [502, 503]) {
        for (const html of [false, true]) {
            const query = `未知本文${status}${html}`;
            tmdb.reply(query, html
                ? { status, contentType: 'text/html', body: `<html>PRIVATE_UPSTREAM_DETAIL ${payload}</html>` }
                : { status, json: { message: `PRIVATE_UPSTREAM_DETAIL ${payload}` } });
            await search(page, query);

            // Assert：未知の本文は安全な固定文言となり、HTML要素・実行結果・古いカードを残さない。
            await expect(view.error).toHaveText('映画情報を取得できませんでした。');
            await expect(page.getByText('PRIVATE_UPSTREAM_DETAIL', { exact: false })).toHaveCount(0);
            await expect(page.locator('body')).not.toHaveAttribute('data-e2e-xss');
            await expect(view.cards).toHaveCount(0);
            await expectReady(page);
        }
    }
    expect(tmdb.searches).toBe(5);
});

// 目的：通信失敗と実Axiosの20秒timeoutを区別し、どちらの終了後も操作復帰・再検索を保証する。
test('通信失敗と20秒タイムアウト後に再検索できる', async ({ page, tmdb }) => {
    // Arrange：このテストだけ上限を延長する。アプリのtimeout値やAxios実装は差し替えない。
    test.setTimeout(60_000);
    await page.goto('/admin/tmdb/search');
    tmdb.networkFailure('通信失敗');
    const view = ui(page);

    // Act：ネットワーク失敗、その後にモックで保留した検索による実際のXHR timeoutを発生させる。
    await search(page, '通信失敗');
    await expect(view.error).toHaveText('通信に失敗しました。接続を確認して再度お試しください。');
    await expect(view.input).toHaveValue('通信失敗');
    await expectReady(page);
    tmdb.reply('通信復帰', { json: results([]) });
    await search(page, '通信復帰');
    await expect(view.status).toHaveText('該当作品なし');
    const pending = tmdb.hold('タイムアウト');
    await search(page, 'タイムアウト');
    await expect.poll(pending.received).toBe(true);
    await expect(view.input).toBeDisabled();
    await expect(page.locator('#tmdb-search-button')).toBeDisabled();
    await expect(view.results).toHaveAttribute('aria-busy', 'true');

    // Assert：HTTPエラーではなくtimeout専用文言を表示し、入力を保持して操作可能に戻す。
    await expect(view.error).toHaveText('検索に時間がかかっています。しばらくしてから再度お試しください。', { timeout: 25_000 });
    await expect(view.input).toHaveValue('タイムアウト');
    await expectReady(page);
    tmdb.reply('時間切れ後の再検索', { json: results([movie(1, '復帰作品')]) });
    await search(page, '時間切れ後の再検索');
    await expect(view.cards.getByRole('heading', { name: '復帰作品', exact: true })).toBeVisible();
    await expect(view.error).toBeHidden();
    await expectReady(page);
    expect(tmdb.searches).toBe(4);
});

// 目的：モバイルの導線・active表示・キーボード操作と、画像障害が映画情報を消さないことを保証する。
test('モバイルから検索でき、ポスターと代替画像の失敗を再試行し続けない', async ({ page, tmdb }) => {
    // Arrange：既存の管理者ログインを維持し、モバイル幅でポスター失敗を準備する。
    await page.setViewportSize({ width: 390, height: 844 });
    const menuButton = page.getByRole('button', { name: '管理メニューを開閉する', exact: true });
    await menuButton.click();
    const menu = page.getByRole('navigation', { name: 'モバイル管理画面ナビゲーション', exact: true });
    await menu.getByRole('link', { name: 'TMDB検索', exact: true }).click();
    await expect(page).toHaveURL('/admin/tmdb/search');
    await menuButton.click();
    await expect(menu.getByRole('link', { name: 'TMDB検索', exact: true })).toHaveAttribute('aria-current', 'page');
    await page.keyboard.press('Escape');
    await expect(menuButton).toHaveAttribute('aria-expanded', 'false');
    const poster = 'https://image.tmdb.org/t/p/w342/e2e-broken.png';
    tmdb.poster(poster, 'abort');
    tmdb.reply('画像失敗', { json: results([movie(1, '画像失敗作品', poster)]) });
    const view = ui(page);

    // Act：TabとEnterで検索し、CDN失敗からNo Imageへの切替を待つ。
    await view.input.fill('画像失敗');
    await view.input.focus();
    await page.keyboard.press('Tab');
    await expect(view.button).toBeFocused();
    await page.keyboard.press('Enter');
    // lazy画像は失敗前のaltで存在するため、先にスクロールして読込を開始させる。
    const image = view.cards.getByRole('img');
    await image.scrollIntoViewIfNeeded();
    await expect(image).toHaveAttribute('src', '/images/no-image.png');
    await expect(image).toHaveAttribute('alt', 'ポスター画像なし');
    await expect.poll(() => image.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth > 0)).toBe(true);

    // Assert：正常な代替画像への通信が1回あり、最初の独立した状態で読込に成功したことを確認する。
    expect(tmdb.posterRequests(poster)).toBe(1);
    expect(tmdb.placeholderRequests).toBe(1);
    await expectReady(page);

    // Arrange：失敗モックを先に登録し、新しい文書で画像成功時のDOM・読込済み画像参照を破棄する。
    // HTTPキャッシュは画像Fakeのno-storeで抑止する。同じ文書で登録済み画像を失敗へ差し替えない。
    // ポスターも別の合成URLにし、失敗したポスター自体の再利用に検証が依存しないようにする。
    const failedPoster = 'https://image.tmdb.org/t/p/w342/e2e-broken-placeholder.png';
    tmdb.rejectPlaceholder();
    tmdb.poster(failedPoster, 'abort');
    tmdb.reply('代替画像も失敗', { json: results([movie(2, '文章は残る作品', failedPoster)]) });
    await page.reload();
    await expect(view.cards).toHaveCount(0);
    await expectReady(page);

    // Act：独立したページ状態でポスターと代替画像を両方失敗させる。
    await search(page, '代替画像も失敗');
    await expect(view.cards.getByRole('heading', { name: '文章は残る作品', exact: true })).toBeVisible();
    await view.cards.getByRole('img').scrollIntoViewIfNeeded();
    await expect.poll(() => tmdb.placeholderRequests).toBe(2);

    // Assert：代替画像も失敗してもカードと件数を保ち、error再発時にsrcを再設定しない。
    const broken = view.cards.getByRole('img', { name: 'ポスター画像なし', exact: true });
    await expect.poll(() => broken.evaluate((element: HTMLImageElement) => element.complete && element.naturalWidth === 0)).toBe(true);
    // 時間だけ待って「無限ではない」と判定せず、追加errorで属性再書込みが起きないことを直接観測する。
    const mutations = await broken.evaluate(async (element) => {
        const changes: MutationRecord[] = [];
        const observer = new MutationObserver((records) => changes.push(...records));
        observer.observe(element, { attributes: true, attributeFilter: ['src'] });
        element.dispatchEvent(new Event('error'));
        element.dispatchEvent(new Event('error'));
        await Promise.resolve();
        observer.disconnect();
        return changes.length;
    });
    expect(mutations).toBe(0);
    expect(tmdb.posterRequests(poster)).toBe(1);
    expect(tmdb.posterRequests(failedPoster)).toBe(1);
    expect(tmdb.placeholderRequests).toBe(2);
    await expect(view.status).toHaveText('1件の作品を表示しました。');
    await expectReady(page);
    expect(tmdb.searches).toBe(2);
});
