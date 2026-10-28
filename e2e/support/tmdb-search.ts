import { test as base, expect, type Page, type Route } from '@playwright/test';

export interface Movie {
    id: number;
    title: string;
    original_title: string | null;
    release_date: string | null;
    overview: string | null;
    poster_url: string | null;
    genres: Array<{ id: number; name: string }>;
}

export function movie(id: number, title: string, poster: string | null = null): Movie {
    return { id, title, original_title: 'Synthetic Movie', release_date: '2026-01-01', overview: '合成データの概要です。', poster_url: poster, genres: [] };
}

export function results(movies: Movie[], checked = movies.length) {
    return { results: movies, summary: { checked, displayed: movies.length } };
}

interface Reply {
    status?: number;
    json?: unknown;
    body?: string;
    contentType?: string;
}

interface SearchPlan {
    query: string;
    handle: (route: Route) => Promise<void>;
}

// 外部画像をダウンロードせず、ブラウザで正常に読み込める合成画像を返す。
const POSTER = '<svg xmlns="http://www.w3.org/2000/svg" width="112" height="176"><rect width="112" height="176" fill="#345678"/></svg>';
const SEARCH_PATH = '/admin/tmdb/search/results';
// guest/adminレイアウトで確認したCSSの1リソースだけをモックする。ホスト単位の許可はしない。
const FONT_STYLESHEET = 'https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap';
// 外部fontファイルへの参照を作らず、ローカルfontへのフォールバックで画面操作を検証する。
const FONT_CSS = '@font-face { font-family: "Figtree"; font-style: normal; font-weight: 400 600; src: local("Arial"); }';

export class TmdbHarness {
    private readonly plans: SearchPlan[] = [];
    private readonly pending = new Set<Route>();
    private readonly posters = new Map<string, 'image' | 'abort'>();
    // URL・query・header・本文を記録せず、固定の分類名だけを失敗理由として残す。
    private readonly violations: string[] = [];
    private readonly posterCounts = new Map<string, number>();
    private failPlaceholder = false;
    searches = 0;
    placeholderRequests = 0;

    constructor(private readonly page: Page, private readonly origin: string) {}

    async install(): Promise<void> {
        // ダイアログの本文は記録せず、画面内エラーの契約違反として扱う。
        this.page.on('dialog', async (dialog) => {
            this.violations.push('想定外のブラウザダイアログ');
            await dialog.dismiss();
        });
        // page.routeが意図せず外れた場合や別ページの通信も、context側で遮断する。
        await this.page.context().route('**/*', (route) => this.route(route, false));
        await this.page.route('**/*', (route) => this.route(route, true));
    }

    reply(query: string, reply: Reply): void {
        this.plans.push({ query, handle: (route) => route.fulfill(reply) });
    }

    networkFailure(query: string): void {
        this.plans.push({ query, handle: (route) => route.abort('failed') });
    }

    hold(query: string) {
        let captured: Route | undefined;
        this.plans.push({ query, handle: async (route) => {
            captured = route;
            this.pending.add(route);
        } });
        return {
            received: () => captured !== undefined,
            release: async (reply: Reply): Promise<void> => {
                if (captured === undefined) throw new Error('検索モックが未到達です。');
                this.pending.delete(captured);
                await captured.fulfill(reply);
            },
        };
    }

    poster(url: string, mode: 'image' | 'abort' = 'image'): void {
        this.posters.set(url, mode);
    }

    posterRequests(url: string): number {
        return this.posterCounts.get(url) ?? 0;
    }

    rejectPlaceholder(): void {
        this.failPlaceholder = true;
    }

    private async reject(route: Route, category: string): Promise<void> {
        this.violations.push(category);
        await route.abort('blockedbyclient');
    }

    private async route(route: Route, pageMock: boolean): Promise<void> {
        const request = route.request();
        const url = new URL(request.url());

        if (url.hostname === 'api.themoviedb.org') {
            await this.reject(route, 'TMDB APIへの直接通信');
            return;
        }
        if (url.href === FONT_STYLESHEET && request.method() === 'GET' && request.resourceType() === 'stylesheet') {
            await route.fulfill({ contentType: 'text/css', body: FONT_CSS });
            return;
        }
        if (url.origin === 'https://image.tmdb.org') {
            const mode = this.posters.get(url.href);
            if (!pageMock || mode === undefined || request.method() !== 'GET' || request.resourceType() !== 'image') {
                await this.reject(route, '未登録の画像CDN通信');
                return;
            }
            this.posterCounts.set(url.href, this.posterRequests(url.href) + 1);
            if (mode === 'abort') await route.abort('failed');
            else await route.fulfill({ contentType: 'image/svg+xml', headers: { 'Cache-Control': 'no-store' }, body: POSTER });
            return;
        }
        // HTTP版CDN、別ホスト、別ポート等も外部通信として送信前に遮断する。
        if (url.origin !== this.origin) {
            // 固定分類だけを記録し、未登録fontとfont以外の通信を推測なしに区別できるようにする。
            await this.reject(route, url.origin === 'https://fonts.bunny.net'
                ? '未登録の外部フォント通信'
                : 'フォント以外の未登録外部通信');
            return;
        }
        if (url.pathname === SEARCH_PATH) {
            const plan = this.plans.shift();
            if (!pageMock || plan === undefined || request.method() !== 'GET'
                || !['xhr', 'fetch'].includes(request.resourceType())
                || url.searchParams.get('query') !== plan.query
                || [...url.searchParams.keys()].some((key) => key !== 'query')) {
                await this.reject(route, '検索GETのモック漏れ・経路または条件不一致');
                return;
            }
            this.searches++;
            await plan.handle(route);
            return;
        }
        // 想定外の検索経路やXHRもLaravelへ通さず、誤って実TMDBを呼ぶ経路を閉じる。
        if (['xhr', 'fetch'].includes(request.resourceType())
            || (url.pathname.startsWith('/admin/tmdb') && url.pathname !== '/admin/tmdb/search')) {
            await this.reject(route, '想定外の検索経路または非同期通信');
            return;
        }
        if (url.pathname === '/images/no-image.png') {
            this.placeholderRequests++;
            if (this.failPlaceholder) await route.abort('failed');
            else await route.fulfill({ contentType: 'image/svg+xml', headers: { 'Cache-Control': 'no-store' }, body: POSTER });
            return;
        }
        // 検索先が別のdocument URLに変わった場合も、実Laravelへ到達させない。
        const allowedDocument = request.resourceType() === 'document'
            && ['/', '/login', '/admin', '/admin/tmdb/search'].includes(url.pathname)
            && (request.method() === 'GET' || (url.pathname === '/login' && request.method() === 'POST'));
        const allowedAsset = ['script', 'stylesheet', 'image', 'font'].includes(request.resourceType())
            && request.method() === 'GET'
            && (/^\/(build|images|fonts|storage)\//.test(url.pathname) || url.pathname === '/favicon.ico');
        if (allowedDocument || allowedAsset) await route.continue();
        else await this.reject(route, '許可していない同一サイト通信');
    }

    async finish(): Promise<void> {
        // timeoutで保留したリクエストを片付ける。既にブラウザが切断済みの場合だけ無視する。
        for (const route of this.pending) {
            await route.abort('failed').catch(() => undefined);
        }
        expect(this.violations, '遮断した想定外通信の分類').toEqual([]);
        expect(this.plans.length, '未使用の検索モックがないこと').toBe(0);
    }
}

// 既存のBrowserContext独立性とsetup依存関係を維持し、このspecだけに通信ガードを適用する。
export const test = base.extend<{ tmdb: TmdbHarness }>({
    tmdb: [async ({ page, baseURL }, use) => {
        if (baseURL === undefined) throw new Error('E2EのbaseURLが未設定です。');
        const harness = new TmdbHarness(page, new URL(baseURL).origin);
        await harness.install();
        try {
            await use(harness);
        } finally {
            await harness.finish();
        }
    }, { auto: true }],
});

export { expect };

export function ui(page: Page) {
    return {
        input: page.getByLabel('映画タイトル', { exact: true }),
        button: page.getByRole('button', { name: '検索', exact: true }),
        status: page.getByRole('status'),
        results: page.locator('#tmdb-search-results'),
        cards: page.getByRole('article'),
        queryError: page.locator('#tmdb-query-error'),
        error: page.locator('#tmdb-search-error'),
    };
}

export async function expectReady(page: Page): Promise<void> {
    const view = ui(page);
    await expect(view.input).toBeEnabled();
    await expect(view.button).toBeEnabled();
    await expect(view.results).toHaveAttribute('aria-busy', 'false');
}

export async function search(page: Page, query: string): Promise<void> {
    await ui(page).input.fill(query);
    await ui(page).input.press('Enter');
}
