/**
 * TMDB映画検索画面（Issue 212）のブラウザ側処理。
 *
 * 【役割分担】
 * - Blade：検索フォームのHTMLと、翻訳済み文言・URLを用意する。
 * - このJS：検索操作、Axios通信、結果描画、エラー表示を担当する。
 * - Controller：検索語の正式なバリデーションとHTTP応答を担当する。
 * - Service：TMDBとの通信、映画情報の検証・整形を担当する。
 *
 * 【大事なこと】
 * - このJSからTMDB APIへ直接アクセスしない。Laravelの検索結果APIだけ呼ぶ。
 * - TMDBのBearerトークンをブラウザへ渡さない。
 * - TMDB由来のタイトル・概要はinnerHTMLではなくtextContentで表示する。
 * - 翻訳文言をJSに直書きせず、Bladeから受け取ったmessagesを使う。
 *
 * 読み返し用メモ：
 * const = 原則、再代入しない変数。let = 後から値を変更する変数。
 * document = 今開いているHTML文書。DOM = JavaScriptから扱うHTML要素の木構造。
 */

// 公開ディレクトリに置かれた「画像なし」の代替画像。TMDBの画像がなくても映画情報は表示する。
const NO_IMAGE_URL = '/images/no-image.png';

/**
 * TMDB由来のジャンルから、画面に表示できる名称だけを元の順序で連結する。
 * 不正値や空配列はnullを返し、既存の「情報なし」表示に任せる。
 *
 * @param {unknown} genres 映画のジャンル一覧
 * @returns {string|null} 表示用のジャンル名
 */
function formatGenres(genres) {
    if (!Array.isArray(genres)) {
        return null;
    }

    // IDの厳密な検証はServiceの責務。ここでは描画可能な名称だけ確認する。
    const names = genres
        .filter((genre) =>
            genre !== null &&
            typeof genre === 'object' &&
            !Array.isArray(genre) &&
            typeof genre.name === 'string' &&
            genre.name.trim() !== ''
        )
        .map((genre) => genre.name);

    return names.length > 0 ? names.join('・') : null;
}

/**
 * 一覧カードの概要だけを、Unicodeの書記素クラスタ単位で100文字に省略する。
 * 元の映画データを書き換えず、Intl.Segmenter非対応時は全文を返す。
 *
 * @param {string|null|undefined} overview 概要の全文
 * @returns {string|null|undefined} 表示用の概要
 */
function truncateOverview(overview) {
    if (typeof overview !== 'string' || typeof Intl === 'undefined' || typeof Intl.Segmenter !== 'function') {
        return overview;
    }

    const segmenter = new Intl.Segmenter('ja-JP', { granularity: 'grapheme' });
    const segments = [];

    // 101文字目が見つかったときだけ省略する。100文字以内なら原文を返す。
    for (const { segment } of segmenter.segment(overview)) {
        if (segments.length === 100) {
            return `${segments.join('')}...`;
        }
        segments.push(segment);
    }

    return overview;
}

/**
 * 1件の映画データから、画面に挿入する「映画カード」を組み立てる。
 *
 * この関数は通信しない。受け取ったデータでDOM要素を作って返すだけ。
 *
 * @param {object} movie Controllerが返した正規化済み映画データ
 * @param {object} messages Bladeから渡された翻訳済み文言
 * @returns {HTMLElement} 完成したarticle要素（映画カード）
 */
function createMovieCard(movie, messages) {
    // createElement() はHTMLの「要素」を新規作成する（まだ画面には表示されない）。
    // classNameは表示デザイン用のTailwind CSSクラス。
    const card = document.createElement('article');
    card.className =
        'flex flex-col gap-4 rounded-lg border border-gray-200 bg-white p-5 sm:flex-row';

    // 作品のポスター画像用に<img>要素を作る。
    const image = document.createElement('img');
    image.className = 'h-44 w-28 shrink-0 rounded-md bg-gray-100 object-cover';
    image.width = 112;
    image.height = 176;
    image.loading = 'lazy'; // 画面外の画像は必要になってから読み込む。

    // 通信障害や画像URL切れなどでポスター読込に失敗した場合の保険。
    // addEventListener('error', ...) は画像読込失敗時に実行する処理を登録する。
    // { once: true } により一度だけ実行し、代替画像まで失敗した場合の無限再試行を防ぐ。
    image.addEventListener('error', () => {
        if (image.getAttribute('src') !== NO_IMAGE_URL) {
            image.src = NO_IMAGE_URL;
            image.alt = messages.noPosterAlt;
        }
    }, { once: true });

    // Serviceが生成したTMDB画像URLだけを使用する。
    // URLの厳密な検証はサーバー側Serviceの責務。ここは追加の簡易チェック。
    // typeof x === 'string' は「文字列か」を調べる書き方。
    if (
        typeof movie.poster_url === 'string' &&
        movie.poster_url.startsWith('https://image.tmdb.org/t/p/')
    ) {
        image.src = movie.poster_url;
        // :title はLaravelの翻訳テンプレート内の差し込み場所。
        // replace() は文字列だけを書き換える。HTMLを解析・実行しない。
        image.alt = messages.posterAlt.replace(':title', () => movie.title);
    } else {
        // poster_urlがnullなどの場合。画像なしでも作品は表示する。
        image.src = NO_IMAGE_URL;
        image.alt = messages.noPosterAlt;
    }

    // 映画タイトル・原題・日付・概要などを入れる要素。
    const details = document.createElement('div');
    details.className = 'min-w-0 flex-1 space-y-2';

    const heading = document.createElement('h2');
    heading.className = 'break-words text-lg font-semibold text-gray-900';
    // textContentは文字列を「テキスト」として入れる。
    // タイトルに<script>などが混ざってもHTMLとして実行させない（XSS対策）。
    heading.textContent = movie.title;
    details.append(heading); // append() で親要素detailsの末尾に追加する。

    /**
     * 「ラベル：値」の表示行を1行作る共通処理。
     *
     * 例：原題：Original Movie Title
     * 無効値・欠損を示すnullは、JSON自体を改変せず画面上だけ「情報なし」にする。
     *
     * @param {string} label 「原題」などの翻訳済みラベル
     * @param {string|number|null} value 作品データの値
     */
    const appendField = (label, value) => {
        const paragraph = document.createElement('p');
        paragraph.className =
            'whitespace-pre-line break-words text-sm leading-6 text-gray-700';

        const labelElement = document.createElement('strong');
        labelElement.className = 'font-semibold';
        // `${label}：` はPHPの文字列補間に近いJavaScriptのテンプレート文字列。
        labelElement.textContent = `${label}：`;

        const text = document.createElement('span');
        // == null はこの箇所では「null または undefined」の両方を判定する。
        // 0や空文字を「データなし」に変換しない点に注意。
        // String(value) はTMDB IDなど数値を表示用文字列へ変換する。
        text.textContent = value == null
            ? messages.notAvailable
            : String(value);

        paragraph.append(labelElement, text);
        details.append(paragraph);
    };

    // 取得した映画データの各項目を、同じ表示形式で追加する。
    appendField(messages.tmdbId, movie.id);
    appendField(messages.originalTitle, movie.original_title);
    appendField(messages.releaseDate, movie.release_date);
    appendField(messages.genres, formatGenres(movie.genres));
    appendField(messages.overview, truncateOverview(movie.overview));

    // カードに画像と文字情報を収めて返す。画面への配置は呼び出し元が行う。
    card.append(image, details);
    return card;
}

/**
 * TMDB検索フォームを初期化して、送信イベントを1回登録する。
 *
 * 初期化＝画面で使う要素・設定を取得し、イベント発生時の処理を準備すること。
 * 実際の検索通信は、この関数を呼んだ瞬間ではなくフォーム送信時に行う。
 */
function initializeTmdbSearch() {
    // getElementById() はHTMLのid属性から該当要素を探す。
    // app.jsは他のページでも読み込まれるため、検索フォームがないページでは何もしない。
    const form = document.getElementById('tmdb-search-form');
    if (!form) {
        return;
    }

    // Blade側に定義済みの各要素。idはsearch.blade.phpと一致させる。
    const input = document.getElementById('tmdb-query');
    const button = document.getElementById('tmdb-search-button');
    const status = document.getElementById('tmdb-search-status');
    const results = document.getElementById('tmdb-search-results');
    const queryError = document.getElementById('tmdb-query-error');
    const errorBox = document.getElementById('tmdb-search-error');

    // BladeがJs::from()で出力したURLと翻訳済み文言を読む。
    // window.axiosは既存bootstrap.jsで初期化済みの共通Axios。
    const config = window.tmdbSearchConfig;

    // 画面の部品・設定が欠けていたら、null参照で処理が壊れる前に終了する。
    if (
        !input || !button || !status || !results ||
        !queryError || !errorBox || !config || !window.axios
    ) {
        return;
    }

    const messages = config.messages;
    // letは再代入できる。通信中だけtrue、通信終了でfalseへ戻す。
    let isSearching = false;

    // submitイベントは「検索ボタンのクリック」と「入力欄でEnter」の両方を受け取る。
    // async (event) => {...} は非同期処理を使える無名関数（アロー関数）。
    form.addEventListener('submit', async (event) => {
        // HTMLフォーム既定の画面遷移（通常のGET送信）を止め、Axiosで処理する。
        event.preventDefault();

        // 入力欄やボタンのdisabledだけでなく、JavaScriptでも二重送信を遮断。
        if (isSearching) {
            return;
        }

        // input.value＝入力欄の現在の文字列。
        // trim()＝前後の一般的な空白を除去（PHPのmb_trimと対象が完全一致するわけではない）。
        // 正式な入力検証はControllerのrequired|string|max:100に任せる。
        const query = input.value.trim();

        // 無効化前に入力欄が選択されていたかを記憶する。
        // document.activeElement＝現在キーボード操作の対象になっている要素。
        const restoreInputFocus = document.activeElement === input;

        // ★重要：awaitより前にフラグを立て、連打による追加通信を防止する。
        isSearching = true;

        // 前回検索の表示を消して、新しい検索に備える。
        // replaceChildren()＝子要素を指定内容に総入れ替え。引数なしは全消去。
        results.replaceChildren();
        status.textContent = '';
        queryError.textContent = '';
        queryError.hidden = true; // hidden=trueで非表示。
        errorBox.replaceChildren();
        errorBox.hidden = true;
        input.removeAttribute('aria-invalid');

        // 処理中の表示。disabled=trueで追加操作を受け付けない。
        // aria-busyは支援技術へ「現在、この領域を更新中」と伝える属性。
        input.disabled = true;
        button.disabled = true;
        button.textContent = messages.searching;
        status.textContent = messages.searching;
        results.setAttribute('aria-busy', 'true');

        try {
            // awaitはAxiosの通信完了まで「この非同期関数の続きを一時停止」する。
            // ブラウザ全体の動作を止めるものではない。
            // 通信先はLaravelのController。TMDBへ直接アクセスしない。
            const response = await window.axios.get(config.url, {
                params: { query }, // { query } は { query: query } の省略記法。
                headers: { Accept: 'application/json' },
                timeout: 20_000, // 20,000ミリ秒＝20秒。今回のGETだけの設定。
            });

            // response.data＝Laravelから受け取ったJSON。
            // 分割代入：resultsをmovies、summaryをsummaryという変数に取り出す。
            const { results: movies, summary } = response.data;

            // 想定外のレスポンスをそのまま描画しないための最低限の形状チェック。
            // Array.isArray()＝配列か、Number.isInteger()＝整数か、を調べる。
            // !は否定、||は「または」、!==は型も含めて異なるかを比較する。
            if (
                !Array.isArray(movies) ||
                !summary ||
                !Number.isInteger(summary.checked) ||
                !Number.isInteger(summary.displayed) ||
                summary.displayed !== movies.length
            ) {
                // throwでエラーにすると、下のcatchで安全な固定文言へ変換できる。
                throw new Error('Invalid search response structure');
            }

            // map()は配列の各要素を別の値へ変換した「新しい配列」を作る。
            // 映画データ配列 → createMovieCardで作成したarticle要素配列。
            const cards = movies.map((movie) =>
                createMovieCard(movie, messages)
            );

            // ...cardsの「...」は配列の中身を引数として展開する書き方。
            // 前回結果を、今回取得した映画カードだけに置き換える。
            results.replaceChildren(...cards);

            // 画面のstatusはBladeでrole="status"が設定されている。
            // ①0件 ②部分成功 ③全件正常 を翻訳済み文言で区別する。
            if (summary.displayed === 0) {
                status.textContent = messages.noResults;
            } else if (summary.displayed < summary.checked) {
                // 除外件数＝検査件数－表示件数。
                // 翻訳テンプレートのプレースホルダーを実際の件数に置き換える。
                status.textContent = messages.partialSuccess
                    .replace(':checked', String(summary.checked))
                    .replace(':displayed', String(summary.displayed))
                    .replace(
                        ':excluded',
                        String(summary.checked - summary.displayed)
                    );
            } else {
                status.textContent = messages.resultsCount.replace(
                    ':displayed',
                    String(summary.displayed)
                );
            }
        } catch (error) {
            // AxiosがHTTPエラーを返した場合、あるいは描画中の例外もここへ来る。
            // error.message・レスポンス本文を無条件に画面表示しない（情報漏えい対策）。
            status.textContent = '';

            // ?. は「左側がnull/undefinedなら、そこで取得をやめてundefinedを返す」。
            // HTTP応答そのものがない通信失敗でも例外を起こさず確認できる。
            const httpStatus = error?.response?.status;

            if (httpStatus === 422) {
                // 入力検証エラー。Bladeにある入力欄直下のrole="alert"領域へ出す。
                // Laravel標準JSONではerrors.queryがメッセージ配列になる。
                // [0]は最初のメッセージ、?.でキー欠損にも対応する。
                const validationMessage = error.response.data?.errors?.query?.[0];

                // 誤った型の値を無条件に使わず、翻訳済み固定文言へフォールバック。
                queryError.textContent =
                    typeof validationMessage === 'string'
                        ? validationMessage
                        : messages.invalidQuery;
                queryError.hidden = false;
                input.setAttribute('aria-invalid', 'true');
            } else {
                // 422以外のエラーは検索フォーム下の共通role="alert"領域へ出す。
                errorBox.hidden = false;

                if (httpStatus === 401) {
                    // 未ログイン／セッション切れ。ログインページへ誘導する。
                    errorBox.textContent = messages.unauthenticated;

                    const loginLink = document.createElement('a');
                    loginLink.href = config.loginUrl; // URLはBlade側で生成済み。
                    loginLink.textContent = messages.loginLink;
                    loginLink.className = 'ml-2 underline underline-offset-2';
                    errorBox.append(loginLink);
                } else if (httpStatus === 403) {
                    // ログインしていても管理者権限がない場合。
                    errorBox.textContent = messages.forbidden;
                } else if (httpStatus === 502 || httpStatus === 503) {
                    // TMDB障害等。サーバーからの文章は原則信用しない。
                    // 設計書で許可された固定メッセージと完全一致した場合のみ表示。
                    const serverMessage = error.response.data?.message;
                    const allowedMessages = [
                        messages.fetchFailed,
                        messages.rateLimited,
                        messages.unavailable,
                    ];

                    // includes()は配列の中に同じ値が存在するかを調べる。
                    errorBox.textContent = allowedMessages.includes(serverMessage)
                        ? serverMessage
                        : messages.fetchFailed;
                } else if (
                    !error?.response &&
                    ['ECONNABORTED', 'ETIMEDOUT'].includes(error?.code)
                ) {
                    // HTTP応答がないタイムアウト。Axiosのcodeを判定する。
                    errorBox.textContent = messages.requestTimeout;
                } else if (!error?.response && error?.request) {
                    // リクエストは試みたがHTTP応答を受け取れなかった通信障害。
                    errorBox.textContent = messages.networkFailed;
                } else {
                    // 未知のエラーも詳細を画面に出さず、固定文言へフォールバック。
                    errorBox.textContent = messages.fetchFailed;
                }
            }
        } finally {
            // finallyは成功・失敗にかかわらず、最後に必ず通る終了処理。
            // エラーで入力欄が使えなくなったままになるのを防ぐ。
            isSearching = false;
            input.disabled = false;
            button.disabled = false;
            button.textContent = messages.searchButton;
            results.setAttribute('aria-busy', 'false');

            // 入力欄にフォーカスがあったのに、disabledで失われた場合だけ復帰。
            // 別のボタン等に移動している場合は、勝手にフォーカスを奪わない。
            if (restoreInputFocus && document.activeElement === document.body) {
                input.focus();
            }
        }
    });
}

// JavaScriptの読み込み時点ではHTML要素がまだ作られていない場合がある。
// loading中ならDOMContentLoaded（HTMLの解析完了）を待ってから初期化する。
// すでに読み込み済みなら、その場で初期化する。
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeTmdbSearch, {
        once: true,
    });
} else {
    initializeTmdbSearch();
}
