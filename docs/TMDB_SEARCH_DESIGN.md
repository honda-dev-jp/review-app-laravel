# TMDB映画検索 技術設計（Issue 212・231・232・230）

## 目次

- [1. 目的・位置づけ](#1-目的位置づけ)
- [2. 対象範囲・対象外](#2-対象範囲対象外)
- [3. 画面とルート（確定）](#3-画面とルート確定)
- [4. 処理フロー・責務（確定）](#4-処理フロー責務確定)
- [5. 設定・認証（確定）](#5-設定認証確定)
- [6. 検索入力・TMDBリクエスト（確定）](#6-検索入力tmdbリクエスト確定)
- [7. Movie Searchレスポンスとアプリ用JSON（確定）](#7-movie-searchレスポンスとアプリ用json確定)
- [8. ConfigurationとポスターURL（確定）](#8-configurationとポスターurl確定)
- [9. HTTP応答・障害時の扱い（確定）](#9-http応答障害時の扱い確定)
- [10. フロントエンド（確定事項と確認事項）](#10-フロントエンド確定事項と確認事項)
- [11. テスト・品質確認](#11-テスト品質確認)
- [12. 実装前の未決事項と合意履歴](#12-実装前の未決事項と合意履歴)
- [13. 公式一次資料](#13-公式一次資料)
- [14. Genre Movie List取得・ジャンル変換（Issue 231）](#14-genre-movie-list取得ジャンル変換issue-231)
- [15. 検索結果カードの表示改善（Issue 232）](#15-検索結果カードの表示改善issue-232)
- [16. 作品登録前モーダルの設計（Issue 230）](#16-作品登録前モーダルの設計issue-230)

## 1. 目的・位置づけ

映画レビューアプリ Laravel移植版のMVP2において、管理者が映画タイトルを検索し、TMDBの映画情報とポスターを同一画面に非同期表示するための技術設計を定める。Issue 231では既存の検索結果JSONへ複数ジャンルのID・日本語名称を追加し、Issue 232では検索結果カードへのジャンル参考表示・概要の100文字省略・ポスター表示寸法の検証を扱う。Issue 230ではその検索結果を使う登録前モーダルの確認・編集UIを扱い、DB登録は後続のIssue 228で実装する。

- 対象Issue: [Issue 212「TMDB映画検索結果をポスター付きで非同期表示する」](https://github.com/honda-dev-jp/review-app-laravel/issues/212)、[Issue 231「TMDB映画検索結果に複数ジャンル情報を追加する」](https://github.com/honda-dev-jp/review-app-laravel/issues/231)、[Issue 232「TMDB検索結果カードにジャンルを表示し概要を100文字に省略する」](https://github.com/honda-dev-jp/review-app-laravel/issues/232)、[Issue 230「TMDB検索結果から作品登録モーダルを開きカテゴリーを選択できるようにする」](https://github.com/honda-dev-jp/review-app-laravel/issues/230)
- 状態（2026-11-01更新）: **Issue 212はPR 229、Issue 231はPR 233、Issue 232はPR 234（マージコミット`42fc77e`）で、いずれも`develop`へ統合済み**。Issue 232のPR 234は2026-10-30 JSTにマージ済みである。第15章の実装・テスト記録はIssue 232作業時点の証跡として保持する。**Issue 230は`feat/230-tmdb-registration-modal`の未コミット作業ツリーで登録前モーダルUIを実装し、ローカルの検証結果が報告されているが、`develop`への統合・GitHub Actionsの成功は未確認**。Issue 230の設計履歴と実装・検証状況は第16章で区別して記録する。
- 第12章のIssue 212の確定事項表は当時の合意履歴として保持し、Issue 231の追加設計は第14章、Issue 232のカード表示設計は第15章、Issue 230の登録前モーダル設計は第16章にまとめる。作品登録API・DB保存とページネーションはそれぞれIssue 228・227の後続対応とする。
- 機能範囲の正本: [FEATURES.md](FEATURES.md)
- URL・HTTPメソッド・ルート名の正本: [ROUTES.md](ROUTES.md)
- 画面・画面遷移の正本: [SCREEN_TRANSITIONS.md](SCREEN_TRANSITIONS.md)
- 認証・認可・秘密情報保護の正本: [SECURITY.md](SECURITY.md)
- 開発手順: [DEVELOPMENT_FLOW.md](DEVELOPMENT_FLOW.md)

本書は**技術的なリクエスト／レスポンス契約、TMDB通信・変換、エラー制御、テスト方針**を扱う。別の正本の内容を必要以上に複製しない。

## 2. 対象範囲・対象外

### 対象

- 管理者向けTMDB映画検索画面、検索フォーム、検索結果の同一画面内での非同期表示
- Axios → Laravel → TMDB API v3 のサーバー経由通信
- TMDB Movie Searchから検索結果を取得し、表示に必要な項目へ整形
- Issue 231: Movie Searchの`genre_ids`をGenre Movie List（`language=ja-JP`）と対応付け、既存JSONに`genres`を追加する（PR 233で`develop`へマージ済み）
- Issue 232: 検索結果カードに`genres`の名称を参考表示し、概要を最大100書記素クラスタ（grapheme）に省略し、ポスターの表示寸法を確認する（PR 234で`develop`統合済み）
- Issue 230: 検索結果カードから登録前モーダルを開き、作品情報を確認・タイトルと概要全文を編集・アプリ独自カテゴリー12件から1件を選択する（作業ブランチでUI実装済み・PR前。第16章、特に第16.11節参照）
- Configurationに基づく公開ポスター画像URLの生成とNo Image表示
- 最大5件表示、正常・0件・入力エラー・外部API失敗時の処理
- 実APIへ接続しない自動テストと、ローカル手動動作確認

### 対象外

- 作品・ジャンル・ポスター・ConfigurationのDB保存、migration、永続キャッシュ
- `tmdb_id` のDB仕様、作品登録、重複登録防止、Movie Details取得、ページネーションUI
- 作品登録API、`items`への保存、`tmdb_id`のDB追加・重複登録防止、カテゴリーSeeder拡張・DB連携（Issue 228）。Issue 230は登録前モーダルのUIのみ実装する。
- TMDBジャンルとアプリ独自カテゴリーの自動対応付け、1作品への複数カテゴリー登録。**ジャンルの取得・JSON変換はIssue 231で対応済み**。Issue 230で新規TMDB API通信や検索JSON契約変更は行わない。
- TMDBの利用条件、attribution、notice、brandingおよび長期保存条件の確定（別途Issue 98）
- TMDB用の外部PHP wrapper、Sanctum、TMDBユーザー認証

## 3. 画面とルート（確定）

| 用途 | HTTP | パス | ルート名 | Laravel側の担当 |
|---|---|---|---|---|
| 検索画面 | GET | `/admin/tmdb/search` | `admin.tmdb.search` | `Route::view` によるBlade表示 |
| 検索結果 | GET | `/admin/tmdb/search/results` | `admin.tmdb.search.results` | JSONを返す専用Controller |

- `routes/web.php` の既存管理者用ルートグループへ配置する。
- 両ルートは `auth` と `can:access-admin-panel` により保護し、`verified` は要求しない。
- 検索結果取得は読み取り専用のGET。Issue 231ではこの既存JSON応答を拡張し、新しいLaravelルート・Controllerは設けない。作品登録用ルートは後続で決める。

## 4. 処理フロー・責務（確定）

```text
管理者ブラウザの検索画面（Blade）
  └─ Axios GET /admin/tmdb/search/results?query=...
      └─ TmdbSearchController（入力検証・レスポンス制御）
          └─ TmdbMovieService（TMDB通信・結果の変換・最大5件へ絞り込み）
              ├─ GET https://api.themoviedb.org/3/search/movie
              ├─ 必要な場合のみ GET https://api.themoviedb.org/3/configuration
              │   └─ Configuration + poster_path → poster_url
              └─ Issue 231: 有効なジャンルIDがある場合のみ
                  GET https://api.themoviedb.org/3/genre/movie/list?language=ja-JP
                  └─ genre_ids + ジャンル一覧 → genres: [{id, name}, ...]
      └─ JSON → Axios → 検索画面にDOMで安全に描画（Issue 232のカードのジャンル参考表示・概要省略は`develop`統合済み。登録モーダルはIssue 230の作業ブランチでUI実装済み・未統合）
                         └─ 画像はブラウザからTMDB画像CDNへ取得
```

| 層 | 責務 |
|---|---|
| Route | `Route::view` とControllerへのルーティング、既存管理者middleware適用 |
| `App\Http\Controllers\Admin\TmdbSearchController` | `Request`の検証、Serviceの呼び出し、JSON/HTTPレスポンスへの変換 |
| `App\Services\TmdbMovieService` | Bearer認証付きTMDB通信、HTTP応答確認、最大5件への絞り込み、必要フィールドの抽出、Configuration取得・URL生成。Issue 231ではジャンルID検証・Genre Movie List取得・`genres`変換と、障害時の独立フォールバックを追加 |
| Blade / JavaScript | 画面・検索フォーム、Axios送信、ロード／結果／エラー表示、安全なDOM更新、No Image表示。Issue 232で既存の検索カード描画にジャンル参考表示・概要省略を追加済み（`develop`統合済み） |

- 現時点で新たなForm Requestクラスは導入せず、ControllerでLaravel標準の `$request->validate()` を使用する。
- 既存Axios（`resources/js/bootstrap.js` の `window.axios`）を使用し、専用HTTP wrapperは導入しない。
- Issue 212のクラス・ルートは実装済み。Issue 231では既存`TmdbMovieService`の追加処理だけを必要最小限のprivateメソッドへ分け、Controller・ルート・既存Configuration処理の不要なリファクタリングは行わない。

## 5. 設定・認証（確定）

- TMDB API v3の**API Read Access TokenをBearer方式**で使用する。
- 環境変数名: `TMDB_READ_ACCESS_TOKEN`。`.env.example` には `TMDB_READ_ACCESS_TOKEN=` と**空値**で記載する。
- `config/services.php` に `tmdb.read_access_token` を追加し、Serviceでは `config('services.tmdb.read_access_token')` から取得する。
- アプリケーションコードから `env()` を直接使用しない。実トークンは人間がローカルの非追跡設定に登録し、AIは `.env` を読み取らない。
- Tokenをブラウザ、JavaScript、HTML、JSONレスポンス、README、docs、Issue、PR、スクリーンショット、ログへ露出させない。
- ポスター画像の公開URLのブラウザ表示にBearer Tokenを使用しない。
- Issue 231のGenre Movie Listへのアクセスにも同じBearer TokenをLaravelサーバー側で使用し、ジャンルの公開JSONやブラウザへToken・上流の生応答を渡さない。

## 6. 検索入力・TMDBリクエスト（確定）

Laravel側が受け取るクエリパラメータは `query` とする。

| 入力項目 | ルール |
|---|---|
| `query` | 必須、文字列、前後のUnicode空白を除去した後で空文字不可・最大100文字 |

- Controllerでは `query` が文字列の場合に限り、PHP 8.4の `mb_trim()`（UTF-8）で前後のUnicode空白（半角空白・全角空白等）を除去し、その値をLaravel標準の `$request->validate()` で `required|string|max:100` として検証する。配列等の文字列以外は型変換せず検証で拒否する。新たなForm Requestクラスは導入しない。**ただし、Controller到達時点の入力値には下記の先行正規化が適用されている。**

**ブラウザから検索する場合の正規化順序（現行実装）**

1. **ブラウザ（JavaScript）**: 検索フォームの `input.value.trim()` で、ECMAScriptが定める前後の空白・改行文字を除去した値をAxiosの `query` パラメータとして送信する。ここでは正式な文字数検証を行わない。
2. **Laravelのglobal middleware**: Controllerより前に `TrimStrings` が文字列入力へ `Illuminate\Support\Str::trim()` を適用する。続く `ConvertEmptyStringsToNull` は空文字列を `null` に変換する。両middlewareの処理順序は本アプリの `app/Http/Kernel.php` に従う。
3. **Controller**: middleware処理後の `query` が文字列の場合に `mb_trim()` を適用し、`required|string|max:100` で検証する。`null` や配列などの不正値は検証で拒否する。

JavaScriptの `trim()`、Laravelの `Str::trim()`、PHPの `mb_trim()` は、**前後から除去する文字の集合が完全には一致しない**。例えば、U+200B（ZERO WIDTH SPACE）はLaravelの `Str::trim()` では除去対象だが、JavaScriptの `trim()` とPHPの既定 `mb_trim()` では除去対象ではない。U+FEFFはJavaScriptおよびLaravelで除去されるが、PHPの既定 `mb_trim()` では除去されない。U+180EはLaravelおよびPHPの既定 `mb_trim()` で除去されるが、JavaScriptでは除去されない。したがって、**検索語の実効的な入力契約はこの一連の処理によって決まり、`mb_trim()` 単独の文字集合だけでは定義しない**。この差異を理由にglobal middlewareを無効化・変更することはIssue 212では行わない。
- 最大100文字の制限は**上記の先行正規化とControllerの `mb_trim()` を経た後**の検索語に適用する。ブラウザの `maxlength` による入力途中の切り詰めは行わず、Laravel側の検証を正とする。正規化後に空文字または `null` となる入力（半角・全角空白のみ、両者の混在等）や入力不正はHTTP `422` とし、TMDBへの通信は行わない。
- 文字列内部の空白は維持し、前後の空白除去以外の表記変換・誤字補正・同義語展開は行わない。検索そのものはTMDB Movie Search APIに任せる。
- Axiosでは `Accept: application/json` を指定し、認証切れ・検証失敗時にもJSON応答として取り扱えることを確認する。
- TMDBへ送る検索条件はサーバー側で固定し、ブラウザから受け取った `page`・`language`・`include_adult` 等をそのまま転送しない。

| TMDB API | メソッド・パス | パラメータ |
|---|---|---|
| Movie Search | `GET /3/search/movie` | `query=<検証済み検索語>`、`language=ja-JP`、`include_adult=false`、`page=1` |
| Configuration | `GET /3/configuration` | ポスターURL生成に必要な画像設定を取得 |
| Genre Movie List（Issue 231） | `GET /3/genre/movie/list` | `language=ja-JP`（Laravelサーバー側固定） |

- `Http::timeout(5)` を**TMDBへの各HTTPリクエスト**（Movie Search、条件付きConfiguration、条件付きGenre Movie List）に適用する。自動retry、並列化、検索間キャッシュは導入しない。
- この5秒はアプリのMVP用設計値であり、TMDB公式推奨値ではない。Issue 231では必要時にMovie Search→Configuration→Genre Movie Listの順で最大3回のHTTP通信を直列実行する。各5秒の設定値を単純合計すると最大約15秒となり、Laravel処理・接続・待ち行列・ブラウザ通信等を含む全体時間はこれより長くなり得る。
- **ブラウザからLaravelへの検索GETだけ**、Axiosのリクエストオプションに `timeout: 20_000`（20秒）を引き続き指定する。各TMDB通信5秒とは別の上限であり、3通信のケースでは設定値上の差は約5秒だが、**20秒以内の完了を保証するものではない**。既存のAxios全体の既定値は変更しない。
- Axios timeoutが発生したときは、翻訳キー `admin.tmdb.search.errors.request_timeout` の文言を表示し、`finally` 相当の処理で入力欄・検索ボタンを再有効化する。ブラウザでのタイムアウトはLaravelサーバー内の処理停止まで保証するものではなく、今回は読み取り専用GETのため終了後の再検索を許可する。

## 7. Movie Searchレスポンスとアプリ用JSON（確定）

### 成功JSONの契約（確定）

- TMDBのraw responseをそのまま返さず、Serviceで以下の検証・正規化を行う。
- 成功時はHTTP `200` とし、常に `results` 配列と `summary` オブジェクトを返す。ページ情報は付けない。
- `summary.checked` はTMDB検索結果の**先頭最大5要素の検証対象数**（0〜5の整数）。TMDB全体の検索ヒット数ではない。
- `summary.displayed` は検証後の正常な表示件数（0〜`checked`の整数）で、返す `results` 配列の要素数と一致する。
- エラーJSONに `summary` は追加しない（第9章参照）。
- 作品ごとの既存フィールドは維持し、Issue 231（PR 233で`develop`へマージ済み）ではすべての表示作品に以下のキーを持たせる。`genre_ids`は内部処理専用であり、公開しない。TMDBの他のフィールドも公開しない。Issue 232ではこのJSON契約および`overview`全文を変更しない。

| キー | 内容 | 出力型 |
|---|---|---|
| `id` | TMDB映画ID | 整数 |
| `title` | タイトル（日本語優先、補完後） | 文字列 |
| `original_title` | 原題 | 文字列または `null` |
| `release_date` | 公開日 | 文字列または `null` |
| `overview` | 概要 | 文字列または `null` |
| `poster_url` | Configurationから組み立てたポスター公開URL | URL文字列または `null` |
| `genres`（Issue 231追加） | TMDBジャンルIDと日本語名称の組。無効・未取得時は空配列 | `list<array{id: int, name: string}>` |

**Issue 231のJSON拡張後の成功例（すべて架空のデータ、1件を検証して1件表示）**

```json
{
  "results": [
    {
      "id": 12345,
      "title": "サンプル映画",
      "original_title": "Sample Movie",
      "release_date": "2026-01-01",
      "overview": "サンプル用の概要です。",
      "poster_url": null,
      "genres": [
        {"id": 28, "name": "アクション"},
        {"id": 878, "name": "SF"}
      ]
    }
  ],
  "summary": {
    "checked": 1,
    "displayed": 1
  }
}
```

**正常な0件の成功JSON**

```json
{
  "results": [],
  "summary": {
    "checked": 0,
    "displayed": 0
  }
}
```

### 作品単位の検証・除外（確定）

1. Movie SearchのHTTP応答を第9章に従って確認した後、**JSONのオブジェクトと配列の区別を保持して**デコードし、トップレベルがオブジェクト、`results` がJSON配列であることを確認する。JSON破損、トップレベルの型不正、`results` 欠落・`null`・JSONオブジェクト・その他の型不正はHTTP `502` と既存の固定エラーJSONを返す。空配列への補正で0件と混同しない。
2. `results` の先頭最大5要素を検証対象として固定し、その要素数を `checked` とする。除外後に6件目以降から補充せず、6件目以降の作品は検証しない。
3. 各作品について**JSONオブジェクトであること**と必須項目・型を確認し、JSON配列など作品オブジェクト以外の要素や必須項目の不正、想定外の型があれば、その作品だけを除外する。数値・配列等を文字列へ強制変換して受け入れない。下記の正常な欠損値は除外理由にしない。
4. 正常な作品を元の順序で残し、正規化後の表示件数を `displayed` とする。1件以上あれば部分成功を含めHTTP `200` で返す。`checked = 0` もHTTP `200` で空配列を返す。
5. `checked > 0` かつ `displayed = 0` は全件不正としてHTTP `502` と既存の固定エラーJSONを返す。正常な0件とは区別する。
6. 検証・除外後の正常な表示対象についてのみ、第8章のConfiguration取得・ポスターURL生成を行う。Configuration失敗によって `displayed` を減らさない。
7. Issue 231では同じ正常表示対象の`genre_ids`だけを利用し、第14章の規則でジャンル一覧を必要時に1回取得して`genres`を追加する。`genre_ids`の欠損・異常、Genre Movie Listの障害によって映画自体を除外せず、`summary`も変更しない。

**JSON型の保持と例外処理（確定）**

- ServiceはTMDB応答の**生の本文文字列**を `json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR)` 等でデコードし、JSONオブジェクトを `stdClass`、JSON配列をPHP配列として区別する。`$response->json()` 等で連想配列に変換した後の `is_array()` のみでは、空のJSONオブジェクト `{}` と空のJSON配列 `[]` を判別できないため、本契約の検証には使用しない。
- `JsonException`（不正JSON、深さ超過、不正UTF-8など）を捕捉して、Movie Searchでは固定エラーJSONのHTTP 502へ変換する。JSON例外の本文や上流の生レスポンスは返さない。
- トップレベルが `stdClass`、`results` がPHPのリスト配列、各有効作品が `stdClass` であることを確認する。`results: []` は正常な0件、`results: {}` と `results: {"0": {"id": 1}}` は形状不正の502とし、連想配列への変換で救済しない。

- 作品データの必須項目はTMDB映画ID `id` のみとし、1以上の整数だけを受け入れる。欠落・`null`・0以下・小数・数値文字列・真偽値などは不正として、その作品だけを除外する。型変換による救済は行わない。
- `title`・`original_title`・`release_date`・`overview`・`poster_path` は欠落を許容し、確定済みの補完・正規化ルールに従う。ただし、値が存在する場合の想定外の型は不正として作品単位で除外する。

### 正常な欠損値の正規化（確定）

- `title`・`original_title`・`overview` は、文字列の場合にPHP 8.4 `mb_trim($value, null, 'UTF-8')` により**前後のUnicode空白**（半角・全角空白、タブ、改行、NBSP等、PHP公式が定義する既定の除去対象）を除去してから扱う。**TMDB応答値はHTTPリクエスト入力の `TrimStrings` やブラウザの `trim()` を通らないため、検索入力 `query` と実効的な除去対象文字集合は完全には一致しない**（第6章）。文字列内部の空白・改行は維持する。正規化後に空文字となった値は欠損として扱い、作品は除外しない。
- 表示用 `title` は正規化したTMDBの `title` を優先する。欠落・`null`・空文字・空白のみの場合は、正規化後に有効な `original_title` で補完し、両方とも利用できなければ `__('admin.tmdb.search.ui.unknown_title')` の翻訳済み文言「タイトル不明」を返す。それだけでは作品を除外しない。
- `original_title`・`overview` は欠落・`null`・空文字・Unicode空白のみの場合、`null` に統一する。有効な文字列は前後のUnicode空白を `mb_trim()` で除去した値を返す。`original_title` を `title` で補完しない。
- `release_date` は厳密な `YYYY-MM-DD` 形式かつ実在する日付のみ受け入れる。未来の日付も許容する。欠落・`null`・空文字・不正な日付文字列は `null` に正規化し、作品は除外しない。ただし、数値・真偽値・配列など想定外の型は作品単位で除外する。
- `poster_path` が欠落・`null`・空文字の場合は正常な欠損値として作品を残し、`poster_url: null` とする。画面では既存の `public/images/no-image.png` を表示する。
- `poster_path` が空でない文字列の場合は、`/` に続く英数字・ハイフン・アンダースコアのみのファイル名と、拡張子 `.jpg`・`.jpeg`・`.png`・`.webp`（小文字）を許可する（例：`/abc123.jpg`、PHP PCREの完全一致パターン：`~\A/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)\z~`）。**`$` では末尾改行の直前に一致し得るため、`\z` を使用**し、末尾のLF・CRLF・NUL等を拒否する。ディレクトリ区切りの追加、パス移動、二重スラッシュ、クエリ文字列、フラグメント、URL全体など、条件に合わない文字列は `poster_url: null` にして作品を維持する。数値・配列・真偽値などの型不正は従来どおり作品単位で除外する。このファイル名・拡張子の制限は**TMDB公式が全有効パスを保証する規則ではなく、本アプリ独自の許可条件**である。
- 想定外の型は欠損値扱いで補完せず、その作品を除外する。例えば `title` が配列の場合、有効な `original_title` があっても救済しない。

## 8. ConfigurationとポスターURL（確定）

画像URL生成では、TMDB公式Image Basicsに従って画像のbase URL、サイズ、`poster_path`を用いる。

- **先頭最大5件の検証で不正作品を除外した後の正常な表示対象**のうち、有効な `poster_path` を持つ作品が1件以上ある場合だけ `/3/configuration` を取得する。不正なパス文字列しかない場合は取得しない。
- Configurationのリクエストは**検索1回あたり最大1回**とする。作品ごとに取得しない。
- Configuration情報は検索処理内でのみ使用し、複数検索にまたがるキャッシュ・DB保存・永続キャッシュは行わない。
- Configuration応答はHTTP成功だけで信用せず、第7章と同様にJSON型を保持する方式でデコードする。トップレベルと `images` がJSONオブジェクト（`stdClass`）、`images.secure_base_url` が文字列、`images.poster_sizes` がJSON配列（PHP配列）であることを確認する。欠落・`null`・型不正・JSON破損・`JsonException` はConfiguration失敗として扱い、**映画情報を維持してNo Imageへフォールバック**する。`images.poster_sizes: {}` を `[]` と混同しない。
- 画像URLのベースにはConfigurationの `images.secure_base_url` だけを採用し、MVPではTMDB公式の画像URL例に対応する `https://image.tmdb.org/t/p/` と完全一致する場合のみ許可する。HTTP、別ホスト、ユーザー情報、ポート番号、クエリ、フラグメント、想定外のパスを含むURLは拒否し、`images.base_url` への切り替えは行わない。これは本アプリ独自の厳格な許可条件であり、将来公式配信先が変更された場合は人間が見直す。
- ポスターサイズの目標幅は **342px** とする（本アプリの管理画面向け設計値であり、TMDB公式の推奨幅ではない）。Configurationの `images.poster_sizes` に含まれる、有効な正の整数幅を表す `w` + 数字形式のサイズ（`w342` など）のみを候補とし、返されていないサイズをURLへ埋め込まない。サイズ文字列はPHP PCREの完全一致パターン `~\Aw[1-9][0-9]{0,3}\z~` に一致するものだけを採用する（MVPの許可条件）。末尾のLF・CRLF・NUL等も拒否し、`original` や不正な要素は候補から除外する。
- サイズは次の優先順で検索処理ごとに1つ選択し、正常な表示対象の全ポスターに適用する。① `w342` があれば選ぶ。② なければ342px以上の最小幅を選ぶ。③ 342px以上がなければ利用可能な数値幅サイズの最大幅を選ぶ。
- `original` は一覧表示用に採用しない。有効な数値幅サイズが1件もない場合は画像URLを生成せず、表示対象の `poster_url` を `null` にしてNo Image表示とする。映画情報および `summary` は維持する。
- `poster_path` が欠落・`null`・空文字、または形式不正の文字列の場合は `poster_url: null` とし、フロントエンドで既存の `public/images/no-image.png`（公開URL `/images/no-image.png`）を表示する。文字列の形式不正だけでは作品自体を除外しない。
- **ConfigurationのHTTP通信失敗・JSON破損・必須項目の欠落／不正・許可できない画像ベースURL・有効な数値幅サイズの不足がある場合**は、映画検索が成功していれば映画情報と `summary` を維持し、対象作品の `poster_url: null` を返してNo Image表示とする。Configurationの障害だけで検索全体を502/503へ変更したり、作品を除外したりしない。
- ブラウザでのポスター画像読み込み失敗時も、検索結果の文章情報を残し、既存の `/images/no-image.png` に切り替える。代替画像自体の読み込み失敗で再試行を繰り返さない。

Configurationの検証・フォールバック条件は上記のとおり確定とする。Issue 231のGenre Movie Listとは独立して扱い、一方の取得・解析失敗を理由に他方を省略しない。TMDBの公式画像配信ドメインやサイズ仕様が将来変わった場合は、無条件に許可範囲を拡大せず設計を再確認する。

## 9. HTTP応答・障害時の扱い（確定）

### 確定事項

- 全件正常・部分成功・正常な検索結果0件はHTTP `200` とする。検証対象が1件以上で全件不正の場合と、JSON全体または `results` の不正はHTTP `502` とする（第7章参照）。
- Laravel標準の入力バリデーション失敗はJSON要求にHTTP `422` と `message` / `errors` を含む標準応答を利用する。
- Laravel側の未認証と管理者権限不足は、既存の `auth` / `can` に従う（JSON要求での401/403の実動作をテストする）。
- TMDBの認証失敗（上流401）をLaravelの未ログイン401と混同しない。
- Movie Searchの429、その他4xx/5xx、connection failure、timeout、トークン未設定を既存の502/503契約で安全に扱う。Issue 231のGenre Movie Listは補助APIとして別の障害境界を設け、429を含むHTTP異常・通信失敗・JSON不正でも検索結果を維持する。生のレスポンス・例外・認証情報はクライアントへ公開しない。
- Laravel HTTP Clientでは4xx/5xxは標準では例外にならないため、レスポンスの状態を明示判定する。接続失敗・タイムアウトによる `ConnectionException` とは分ける。
- Configurationのみ失敗した場合は、映画検索結果を200で返してNo Image表示とする。Genre Movie Listのみ失敗した場合は、検索結果を200で返してすべての`genres`を空配列とする。両方失敗した場合も映画情報・`summary`は維持する。

### 確定事項：HTTPステータスとエラーJSON

Issue 212のMovie Searchを中心とする既存応答契約を維持し、Issue 231ではGenre Movie List固有の補助情報フォールバックを追加した。下表のGenre Movie List行を含むIssue 231の応答契約は**PR 233で`develop`へマージ済み**であり、`main`への同期は未実施。Issue 232ではHTTPステータス・JSON構造・フォールバック契約を変更しない。

| 状況 | HTTPステータス | JSON応答 |
|---|---|---|
| 全件正常（`checked = displayed > 0`） | 200 | `results` と `summary`（第7章） |
| 部分成功（`0 < displayed < checked`） | 200 | 正常な作品の `results` と `summary`（第7章） |
| 正常な検索結果0件（`checked = displayed = 0`） | 200 | `{"results":[],"summary":{"checked":0,"displayed":0}}` |
| 検証対象が1件以上で全件不正 | 502 | `{"message":"映画情報を取得できませんでした。"}` |
| Movie SearchのJSON破損・`results` 欠落／型不正 | 502 | `{"message":"映画情報を取得できませんでした。"}` |
| Laravel側の未認証 | 401 | Laravel標準JSON |
| Laravel側の認可違反 | 403 | Laravel標準JSON |
| 入力不正 | 422 | Laravel標準の `message` / `errors` |
| Movie SearchのTMDB認証失敗・その他4xx/5xx（429を除く） | 502 | `{"message":"映画情報を取得できませんでした。"}` |
| Movie SearchのTMDB 429 | 503 | `{"message":"しばらくしてから再度お試しください。"}` |
| Movie SearchのTMDB接続失敗・タイムアウト | 503 | `{"message":"映画情報を取得できませんでした。"}` |
| Laravel側のTMDB Token設定不足 | 503 | `{"message":"検索サービスを利用できません。"}` |
| Genre Movie Listの非2xx（429を含む）・接続失敗・タイムアウト・JSON不正 | 200（Movie Search正常時） | 映画情報・`poster_url`・`summary`を維持し、各映画の`genres: []`へフォールバック |

- エラーJSONは `message` を基本とし、独自の `error_code` や `summary` は追加しない。
- 未認証・認可違反・入力不正はLaravel標準の応答を利用する。
- TMDB側の401をLaravel側の未認証401として返さない。
- TMDB 429に対して自動retryは行わない。Movie Searchの429は503のまま、Genre Movie Listの429は補助データのみ縮退する。429を受けても即時再試行しない。これは本アプリ独自の障害分離方針である。
- Configuration取得だけが失敗した場合は、映画情報を維持してHTTP 200で返し、ポスターをNo Image表示にする。
- Genre Movie List障害での`genres: []`と、本来ジャンルがないための`genres: []`はMVPでは区別せず、追加のエラーキー・警告メッセージを公開しない。ジャンル障害は`partial_success`の除外件数へ反映しない。
### 管理者向けメッセージの一元管理（確定）

- 管理者向けTMDB検索のエラー文言・画面案内文言は、Issue 212で設けた `lang/ja/admin.php` のPHP配列で一元管理する。既存の `lang/ja.json`・`lang/ja/validation.php` は今回の専用メッセージ追加を目的として書き換えない。`config/app.php` の既存の日本語localeを使用する。
- メッセージキーはHTTPステータス番号に依存させず、`admin.tmdb.search.errors.*` と `admin.tmdb.search.ui.*` の意味別キーを用いる。文言はController・Service・Blade・JavaScriptへ直接ハードコードせず、PHPではLaravel標準の `__()` によって取得する。
- サーバーで生成する固定エラーJSONの `message` は上記の翻訳キーを使い、第9章の確定済みレスポンス文言・HTTPステータス・`summary` 非付与を維持する。上流TMDBの生のエラーメッセージや例外詳細を利用者へ返さない。
- **内部診断用例外メッセージの扱い：** Service内で生成する例外のメッセージは、利用者向けの表示・案内文言とは区別し、内部診断専用である場合に限り翻訳キーによる一元管理の対象外とする。この例外は利用者向けの文言をServiceへ直書きすることを許可するものではない。内部診断メッセージにはTMDBの生レスポンス、アクセストークン、検索語その他の機密情報・利用者入力を含めない。Controllerは内部例外のメッセージをHTTPレスポンスへ転用せず、上記の翻訳キーに基づく固定エラーJSONへ変換する。なお、Laravel HTTPクライアント由来の例外は上流レスポンス情報を保持する可能性があるため、例外オブジェクトやメッセージをログへ無条件に記録せず、記録項目を安全な情報に限定する。
- `query` の422では、Controllerの `$request->validate()` のカスタムメッセージに `__('admin.tmdb.search.errors.invalid_query')` を指定し、Laravel標準の422 JSON（`message` / `errors`）を維持する。`required`・`string`・`max` のいずれの失敗でも検索入力用の統一文言とする。共通の `validation.php` をTMDB専用文言で上書きしない。
- Laravel標準の401・403応答や、管理画面URLへ直接アクセスした際の403ページは変更しない。Axiosで検索結果取得APIから401・403を受けた場合のみ、管理者検索画面内に対応する翻訳済みエラー文言を表示する。
- JavaScriptはPHPの言語ファイルを直接読まない。Blade内で必要な翻訳済みメッセージだけを配列にまとめ、`Illuminate\Support\Js::from($messages)` 等のLaravel標準機能で安全に渡す。メッセージの原文をJavaScriptに再定義しない。
- `502`・`503` のレスポンス本文を表示する場合は、サーバーが返す**確定済みの固定メッセージのみ**を許可し、それ以外の本文（HTMLエラーページ・proxy応答・予期せぬ文字列など）は安全な汎用エラー文言にフォールバックする。503はレート制限・トークン未設定・通信失敗を区別するため、ステータス番号だけで文言を推測しない。表示は `textContent` 等を使用する。

**Issue 212で確定・実装した言語ファイルのキー（既存仕様の記録）**

```php
<?php

return [
    'tmdb' => [
        'search' => [
            'errors' => [
                'invalid_query' => '映画タイトルを1〜100文字で入力してください。',
                'unauthenticated' => 'ログインが必要です。再度ログインしてください。',
                'forbidden' => 'この機能を利用する権限がありません。',
                'fetch_failed' => '映画情報を取得できませんでした。',
                'rate_limited' => 'しばらくしてから再度お試しください。',
                'unavailable' => '検索サービスを利用できません。',
                'network_failed' => '通信に失敗しました。接続を確認して再度お試しください。',
                'request_timeout' => '検索に時間がかかっています。しばらくしてから再度お試しください。',
            ],
            'ui' => [
                'page_title' => 'TMDB映画検索',
                'query_label' => '映画タイトル',
                'query_placeholder' => '映画タイトルを入力',
                'search_button' => '検索',
                'searching' => '検索中...',
                'no_results' => '該当作品なし',
                'results_count' => ':displayed件の作品を表示しました。',
                'unknown_title' => 'タイトル不明',
                'not_available' => '情報なし',
                'tmdb_id' => 'TMDB ID',
                'original_title' => '原題',
                'release_date' => '公開日',
                'overview' => '概要',
                'poster_alt' => '作品「:title」のポスター',
                'no_poster_alt' => 'ポスター画像なし',
                'login_link' => 'ログイン画面へ',
                'partial_success' => ':checked件中:displayed件を表示しました。:excluded件はデータ形式が不正だったため除外しました。',
            ],
        ],
    ],
];
```

- タイトル補完ではService側で `__('admin.tmdb.search.ui.unknown_title')` を取得し、`title` の出力値とする。JavaScriptに「タイトル不明」を直書きしない。
- 新しい検索画面のタイトル・ラベル・入力ヒント・検索ボタン・ログインリンク・作品項目名・欠損の代替文言・ポスターのalt文字列は `ui.*` を参照する。既存の管理者画面全体や共通ナビの翻訳整理はIssue 212の対象外とする。表示用の欠損文言 `not_available` は**JSON内の `null` を変更せず、画面描画時だけ使う**。
- 全件正常時の `role="status"` では `ui.results_count` に `summary.displayed` を埋め込み、部分成功は `ui.partial_success`、正常な0件は `ui.no_results` を通知する。画面表示に使用する翻訳値をJavaScript側に日本語で再定義しない。
- `partial_success` の件数は `summary.checked`・`summary.displayed` から算出する。JavaScript側ではBladeから受け取った翻訳済みテンプレートの`:checked`・`:displayed`・`:excluded` を、表示直前に安全に置換する。`poster_alt` の`:title` も安全な文字列操作で置換し、DOMへ `alt` 属性として設定する。件数や除外判定に影響を与えない。
- 画像の代替URL `/images/no-image.png`、ルート名、HTTPステータスなどの**仕様上の固定値**は翻訳対象としない。メッセージ文言と技術的な定数を混同しない。
- 上記のPHP配列はIssue 212時点の言語キーの記録である。Issue 232ではこれを維持したうえで`ui.genres = 'TMDBジャンル（参考）'`を追加し、Bladeから翻訳済みの`messages.genres`としてJavaScriptへ渡す（第15.2節）。

## 10. フロントエンド（確定事項と確認事項）

- 管理者ナビゲーションの「TMDB検索」から検索画面に移動できるようにする（PC・モバイル双方）。既存のモック `href: null` を `route('admin.tmdb.search')` へ切り替え、`active` は `request()->routeIs('admin.tmdb.search')` により検索画面ルートで判定する。検索結果の `admin.tmdb.search.results` はJSON応答のみで管理ナビゲーションを描画しない。
- 検索フォーム送信時はページを再読み込みせず、AxiosからLaravel JSON endpointへGETを送る。**検索GETのみ** `timeout: 20_000` を指定し、タイムアウトしても再検索可能に戻る。
- 初期表示／検索中／全件正常／部分成功／0件／入力エラー／API失敗を画面上で区別できるようにする。検索中・成功・0件・部分成功などの**状態通知**は `role="status"`（または同等のライブリージョン）で読み上げられるようにし、処理中の結果領域へ `aria-busy` を適切に設定する。
- 新しい検索の開始時は前回の検索結果とエラー表示をクリアし、検索失敗後は古い結果を再表示しない。検索語の入力値は保持し、再検索可能にする。
- エラーはブラウザのalertダイアログではなく画面内へ表示し、`role="alert"` 等で支援技術へ通知する。422は検索欄の近くに表示し、検索欄の `label` と `aria-describedby` によるエラー関連付けを行う。401では既存のログインルートへの再ログイン用リンク（`ui.login_link`）を表示し、403はメッセージのみ表示して権限変更や強制リダイレクトは行わない。
- 422は検証対象の `errors.query` の最初のメッセージ（ない場合は `invalid_query`）を使用する。401・403はBladeから渡した `unauthenticated`・`forbidden`、502・503は第9章の許可された固定メッセージ、HTTP応答を受信できないネットワークエラーは `network_failed` を表示する。**Axios検索GETの20秒timeout**（Axiosの `ECONNABORTED` や `ETIMEDOUT` 等、HTTP応答なしのタイムアウトとして識別できるもの）では `request_timeout` を使用する。安全な文言に該当しない場合は `fetch_failed` へフォールバックする。
- 全件正常時は通常の検索結果を表示し、正常な0件は「該当作品なし」とする。全件不正の502を0件表示にしない。
- 部分成功時は `summary.checked` と `summary.displayed` から件数と注意文を表示する。例: 「5件中4件を表示しました。1件はデータ形式が不正だったため除外しました。」（文言は `admin.tmdb.search.ui.partial_success` で一元管理）除外件数は `checked - displayed` とし、TMDB全体の検索ヒット数として表示しない。
- Configuration失敗やポスター欠損は作品の除外に数えず、No Imageで表示する。Issue 231のジャンル欠損・Genre Movie List障害も作品除外に数えず、既存の件数表示・画面表示を維持する。検索結果カードへのジャンル参考表示・概要100文字省略はIssue 232の第15章、作品登録モーダルでのジャンル参考表示はIssue 230で扱う。
- TMDB由来の文字列を `textContent` 等で描画し、信頼できない値を `innerHTML` に渡さない。
- `poster_url` が `null` の場合および画像読み込み失敗時は `/images/no-image.png` を表示し、結果全体を壊さない。ポスターには作品名を含む `ui.poster_alt`、No Imageには `ui.no_poster_alt` の翻訳済み文言をaltへ設定する。
- 検索フォームの `submit` イベントを一元的に処理し、検索開始時にJavaScriptの処理中フラグを即時に立てる。フォーム送信によるページ遷移は抑止し、検索中は検索ボタンと入力欄を無効化して `admin.tmdb.search.ui.searching` の翻訳済み文言を表示する。
- 処理中フラグが立っている間は、クリック・Enterキー・連続 `submit` のいずれからも追加のAxiosリクエストを送らない。UIの無効化だけに依存せず、イベントハンドラの入口でもフラグを確認する。
- 成功・検索結果0件・HTTP 422・401/403・上流APIエラー・通信失敗・**Axios timeout**など、処理の終了時は成功・失敗にかかわらず `finally` 相当の処理でフラグを解除し、入力欄と検索ボタンを再び有効化する。その後の再検索は許可する。無効化に伴うキーボードフォーカス喪失を確認し、必要なら検索入力欄へ復帰できるようにする。ただし、利用者が別の操作対象へ移ったときはフォーカスを不意に奪わない。
- 同じ画面では検索リクエストを同時に複数実行しないため、MVPではリクエストのキャンセルや結果の通し番号による先後逆転制御を導入しない。作品登録を伴わない読み取り専用GETであり、PRGやワンタイムCSRFトークンによる二重登録防止は対象外とする。

## 11. テスト・品質確認

### Feature / Serviceテスト（外部通信なし）

- `Http::fake()` でMovie Search・Configuration・Issue 231のGenre Movie Listを必要なケースのみスタブし、`Http::preventStrayRequests()` で想定外の外部HTTP通信を阻止する。
- 管理者・一般ユーザー・未ログインの両ルートへのアクセス。メール未認証管理者も確認する。
- 認証・認可・入力検証で拒否した場合、TMDBへHTTP通信しない。
- 検索語 `query` の欠落・`null`・空文字・半角／全角／混在したUnicode空白のみ・配列等の文字列以外をHTTP `422` で拒否し、TMDBへHTTP通信しないことを確認する。
- `query` について、HTTP経路の `TrimStrings` → `ConvertEmptyStringsToNull` → Controllerの `mb_trim()` という順序を前提に、前後の空白除去と文字列内部の空白維持を確認する。正規化後の99・100文字は許可、101文字はHTTP `422` とする。前後に空白が付いた100文字の入力も許可し、前後空白除去以外の表記変換・誤字補正を行わないことを確認する。ブラウザ側の検証では `maxlength` による事前切り詰めがないことも確認する。U+200B・U+FEFFなどの境界値は、各層の除去対象が異なることを前提に期待値を定める。
- TMDBへのBearer header、`language=ja-JP`、`include_adult=false`、`page=1`、リクエスト回数。
- 正常な0件は200、空の `results`、`summary: {"checked":0,"displayed":0}` を確認する。
- 全件正常の1・5・6件では200、先頭最大5件のみを返し、`checked = displayed = results.length` を確認する。作品ごとの既存キーを維持し、不要フィールドを公開しない。
- 部分成功（先頭5件中1件不正等）は200、正常な4件を元の順序で返し、`summary: {"checked":5,"displayed":4}` を確認する。
- 検証対象1件および5件が全件不正なら502と既存の固定エラーJSONを返し、`summary` を含めない。0件成功へ変換しない。
- **生のJSON本文**を `Http::fake()` で返して、`{"results":[]}` は200、`{"results":{}}` および `{"results":{"0":{"id":1}}}` は502となることを確認する。トップレベル配列・数値・`null`、`results` 欠落・`null`・文字列、JSON破損、深さ超過・不正UTF-8による `JsonException` は502と既存の固定エラーJSONを返し、`summary` を含めない。デコード後のPHP配列から生成したfakeだけでは `{}` と `[]` の差を確認できないため、境界値にはJSONリテラルを使用する。
- 6件以上の応答で先頭5件に不正作品があっても6件目以降から補充しない。先頭5件全件不正・6件目正常でも502、先頭5件正常・6件目不正でも200となることを確認する。
- 作品オブジェクト以外の要素、必須項目不正、想定外の型は作品単位で除外する。TMDB映画ID `id` は1以上の整数のみ受け入れ、0以下・小数・数値文字列・真偽値・`null`・欠落を除外する。
- `title`・`original_title`・`overview` を `mb_trim()` で前後のUnicode空白（半角・全角・タブ・改行・NBSP等）を除去し、内部の空白・改行を維持することを確認する。Unicode空白のみの `title` は有効な原題で補完し、原題も欠損・空白だけなら `admin.tmdb.search.ui.unknown_title` の翻訳済み「タイトル不明」を返す。`original_title`・`overview` はUnicode空白だけなら `null` に正規化し、作品を除外しない。`poster_path` の欠落・`null`・空文字も作品を除外せず `poster_url: null` とする。
- `release_date` の正常日付・うるう年・未来日付・欠落・`null`・空文字・不正な日付文字列・想定外の型を検証する。不正な日付文字列では作品を維持して `null` に正規化し、型不正では作品単位で除外する。
- `poster_path` は有効な `.jpg`・`.jpeg`・`.png`・`.webp`、先頭 `/` 不足、パス移動、二重スラッシュ、クエリ文字列、フラグメント、URL全体、不許可拡張子、**末尾LF・CRLF・NUL**、数値・配列・真偽値などを検証する。`~\A...\z~` による完全一致を確認し、形式不正の文字列では作品と `summary.displayed` を維持して `poster_url: null` とし、型不正では作品単位で除外する。
- 作品除外後に有効な `poster_path` を持つ正常作品がなければConfigurationを取得しない。`poster_path` が欠落・`null`・空文字・形式不正の文字列の作品しかない場合、不正作品だけにポスターがある場合、0件、全件不正、JSON全体・`results` 不正でも取得しない。
- 有効な `poster_path` を持つ正常作品があればConfigurationを最大1回取得し、目標342pxの選択規則（`w342`優先・342以上の最小幅・それ以外の最大幅）に従って有効な数値サイズとパスからURLを生成する。`original` のみ・サイズ候補なしでは `poster_url: null` とし、作品と `summary` を維持する。
- サイズ候補 `w185, w342, w500` → `w342`、`w185, w500` → `w500`、`w92, w154, w185` → `w185`、`original` のみ・空配列 → No Imageを確認する。返された候補以外のサイズは使用しない。
- Configuration応答の正常な `images.secure_base_url: "https://image.tmdb.org/t/p/"` と `images.poster_sizes` の**JSON配列**から安全にURLを生成する。生JSONで `poster_sizes: []` と `poster_sizes: {}` を区別する。`images` 欠落・型不正、`secure_base_url` 欠落・`null`・別ホスト・HTTP・余分なポート／パス／クエリ／フラグメント、`poster_sizes` 欠落・オブジェクト等、JSON破損・`JsonException` では、映画情報・`summary` を維持して `poster_url: null` とすることを確認する。`poster_sizes` に無効な要素（例: `w0`・`w-1`・`w342?x=1`・`w342\n`・`w342\r\n`・NUL付き・`w0342`・`w10000`・`original`・非文字列）が混在する場合はその要素のみ候補から除外し、有効な数値サイズが1件もなければ `poster_url: null` とする。
- Configuration失敗時でも正常な全作品の情報・件数・`summary` を維持し、200で `/images/no-image.png` へフォールバックする。部分成功との組み合わせも確認する。
- TMDBの401、429、その他4xx/5xx、connection failure、timeout、トークン未設定時の安全な応答。
- 検索結果のJSON、HTML、ログ等にトークンやraw TMDBレスポンスを混入させない。
- Issue 212で追加した `lang/ja/admin.php` の専用キーがすべて定義され、Serviceの `unknown_title`、Controllerの固定エラーJSON（502・503）、検索入力の422バリデーションが翻訳キーを参照して、既存のHTTPステータス／JSON構造を維持することを確認する。新規画面のラベル・代替文言・ログインリンク・ポスターalt・Axios timeout文言もキーから取得し、JavaScriptに日本語を重複定義しない。共通の `validation.php` や既存認証／認可応答を変更しない。

### Issue 231追加：ジャンル変換のPHPUnit検証（Codex担当）

- **Serviceテスト**: 複数作品・複数ジャンルのID→日本語名称変換、映画側`genre_ids`の順序維持、作品内IDの重複除去、未知IDだけの除外を確認する。
- **入力境界**: `genre_ids`欠損・`null`・`[]`・文字列・数値・JSONオブジェクト`{}`・数値キー付きオブジェクトを区別する。リスト内の0・負数・小数・数値文字列・真偽値・`null`は無効、正の整数だけ採用する。不正ジャンルだけでは映画を除外しない。
- **Genre List全体**: 正常な`{"genres":[]}`、トップレベル配列／`null`、`genres`欠損／`null`／文字列／オブジェクト、JSON破損、深さ超過・不正UTF-8を検証し、異常時も映画・`poster_url`・`summary`を維持する。`{"genres":[]}`と`{"genres":{}}`の区別は生のJSONリテラルで検証し、配列化したfixtureで誤って同一視しない。
- **Genre List内の要素**: オブジェクト以外、不正ID、欠損・不正型・空文字・Unicode空白のみの名前、重複ID（不正→有効／有効→別の有効名）、`mb_trim()`による出力正規化を確認する。
- **不要通信**: 正常0件、先頭5件全件不正、有効映画にジャンルIDなし、除外映画にだけIDあり、6件目以降にだけIDありの場合はGenre Listを呼び出さない。複数映画でもGenre Listは検索1回につき最大1回とし、検索を2回行えば必要時に各回で再取得する。
- **HTTP・独立性**: URL・GET・`language=ja-JP`・Bearer・5秒timeout・retryなしを検証する。Genre Listの401・429・4xx/5xx・3xx・接続失敗・timeout時はHTTP 200の映画情報を維持する。Configurationとの成功／失敗4通りを確認し、エラー応答・例外・Tokenの漏出がないことを確認する。
- **Featureテスト**: 既存管理者ルートを通した完全一致JSONの正常変換・Genre List障害時の200・`summary`不変を検証する。Serviceの全境界値テストを重複して持たせない。
- **既存期待値・型**: `TmdbMovieServiceTest.php`と`TmdbSearchTest.php`の`movie()`ヘルパー、明示的な期待配列、PHPDocを`genres`付きへ更新する。`assertSame`・`assertExactJson`は維持し、既存fixtureに有効な`genre_ids`がない場合は**HTTP呼び出し回数を一律に増やさない**。正常0件の`results: []`と既存固定エラーJSONは変更しない。
- **テストの読みやすさ**: Codexが追加・更新するPHPUnit／Playwright E2Eには、意図が理解できる日本語のArrange（準備）・Act（実行）・Assert（検証）コメントを付ける。テスト実装・実行完了前に成功を宣言しない。

### ブラウザ確認

- ページ再読み込みなしの全件正常表示、部分成功の件数・注意文、0件の「該当作品なし」、全件不正502のエラー表示、検索中、422、検索API失敗、ポスター欠落・読込失敗時の既存 `/images/no-image.png` 表示（代替画像失敗時の無限再試行防止を含む）。
- 検索中の検索ボタン・入力欄の無効化と「検索中」表示、ボタン連打・Enter連打・連続submitでAxiosリクエストが1件のみ送られることを確認する。成功時・0件時・422・401/403・502/503・通信失敗・**Axios 20秒timeout**時に操作可能な状態へ必ず戻り、その後の再検索で新しいリクエストと結果表示ができることを確認する。テストでは遅延・タイムアウトをモックで再現し、実TMDBに接続しない。
- TMDB由来の疑似XSS文字列をHTMLとして実行しない。
- 422の検索欄近くのエラー、401の再ログインリンク、403の画面内表示、502／503の固定文言、ネットワーク失敗時の汎用文言を確認する。画面URLへ直接アクセスした際のLaravel標準403ページは従来どおりとする。
- 検索開始時に前回結果とエラーを消し、検索失敗後も入力語を保持し、エラー発生後に再検索できることを確認する。エラー文言・検索中・成功件数・0件・部分成功の文言はBladeから `Js::from()` 等で安全に渡した翻訳済み値を使用し、JavaScriptに日本語を重複定義しない。引用符や `</script>` を含む文字列が渡されてもHTML・JavaScriptの構造を壊さず、TMDBの生文字列はDOMへ安全に設定する。未知の502／503レスポンス本文は安全な文言にフォールバックし、raw TMDB応答・例外詳細を描画しない。
- `role="status"` の検索中・成功件数（`ui.results_count`）・部分成功・0件の状態通知、`role="alert"` のエラー通知、`aria-busy`、入力ラベル・`aria-describedby`、ポスターおよびNo Imageのalt、検索終了時のキーボード操作・フォーカスの扱いを確認する。
- 管理者ナビゲーションのPC・モバイル導線と、検索画面ルート `admin.tmdb.search` に対する `active` 判定を確認する。検索結果ルート `admin.tmdb.search.results` はJSON応答のため、ナビゲーションを描画しない。
- **Playwrightの画面表示テストでは、ブラウザの検索結果GET `/admin/tmdb/search/results` を `page.route()` 等で捕捉し、合成JSONの `route.fulfill()` または失敗シナリオ用の `route.abort()` で処理する。**リクエスト件数・URL・メソッドを検証し、想定外の検索経路はテスト失敗にする。ポスターCDN `https://image.tmdb.org/**` へのブラウザリクエストも `route.fulfill()` で代替画像を返すか `route.abort()` で遮断し、ブラウザから `api.themoviedb.org` へ直接通信しないことも確認する。
- PHPUnit側の `Http::fake()` / `Http::preventStrayRequests()` は**別プロセスのPlaywright用Laravelサーバーへ自動適用されない**。Playwrightでは上記のブラウザ通信のスタブでUIだけを検証し、Laravel→TMDBのHTTP通信とJSON変換はFeature / Serviceテストで担当する。検索リクエストがスタブを通らず実Laravelへ到達する状態ではブラウザテストを進めず、漏れを検知して失敗させる。CIから実TMDB APIを呼ばない。
- **Issue 231のE2E（実装時の契約）**: `e2e/support/tmdb-search.ts`の`Movie`型に`genres: Array<{id: number; name: string}>`を必須追加し、`movie()`fixtureの既定値を`genres: []`にする。既存6シナリオの合成応答は原則`movie()`fixtureの既定値`genres: []`を利用して回帰実行し、正常検索の合成応答では複数ジャンル付きJSONでも従来のカード描画が崩れないことを確認する。検索結果カードへのジャンル表示・XSS・表示確認はIssue 232、作品登録モーダルのジャンル参考表示・ARIA・フォーカス検証はIssue 230で扱う。
- **Issue 232のE2E（実装前の検証計画）**: 既存6シナリオを維持し、複数ジャンルの順序・区切り、ジャンル欠損と不正要素、概要の99・100・101 grapheme境界と日本語・結合文字・複合絵文字、原文不変、XSS、PC・モバイルの実寸112×176pxおよびNo Imageの回帰を合成JSONで検証する。非対応時の概要全文表示も再現する。詳細は第15章。

### 既存品質ゲート

`git diff --check`、Laravel Pint、PHPStan / Larastan Level 10、PHPUnit、Vite build、必要に応じてPlaywrightを確認する。実行していない項目を成功したと記載しない。

## 12. 実装前の未決事項と合意履歴

以下の表はIssue 212の実装前合意履歴を示す。Issue 231の設計決定は表の後に分けて記載し、実装済みかどうかと混同しない。

| 項目 | 状態 |
|---|---|
| `GET /admin/tmdb/search` と `/results` の2ルート | 確定 |
| `auth` + `can:access-admin-panel`、`verified` 不要 | 確定 |
| `Route::view` + Controller + Service、既存Axios | 確定 |
| `TMDB_READ_ACCESS_TOKEN` と `config/services.php` | 確定 |
| 検索語 `query` の必須・文字列・ブラウザ `trim()` → Laravel `TrimStrings` / `ConvertEmptyStringsToNull` → Controller `mb_trim()`・実効的な正規化後最大100文字・空白のみ禁止 | 確定（第6・11章） |
| 日本語検索・成人向け除外・1ページ目・先頭5件 | 確定 |
| 作品除外後の正常作品にポスターがある場合のみConfiguration最大1回・キャッシュなし | 確定 |
| Configuration失敗でも映画情報表示・No Image | 確定 |
| Laravel→TMDBの各HTTP通信timeout 5秒・retryなし、Axios検索GETのみtimeout 20秒・終了後の復帰 | 確定（第6・10・11章） |
| 成功JSONの既存作品キー維持・常時 `summary` 付与 | 確定（第7章、エラーには付与しない） |
| 先頭最大5件の検証・作品単位除外・6件目以降の補充なし | 確定 |
| 全件正常／部分成功200・正常な0件200・全件不正502 | 確定（第9章） |
| JSONオブジェクトと配列の型を保持した検証（`json_decode` のオブジェクトモード）、`results` 欠落／型不正502 | 確定（第7・8・11章） |
| タイトル補完・文字列欠損の `null` 正規化・ポスター `null` 許容 | 確定（第7章） |
| 作品データの必須項目は `id` のみ・1以上の整数 | 確定（第7章） |
| `poster_path` のアプリ独自の厳格パス条件（`\A`・`\z` 完全一致）・形式不正時のNo Image・型不正時の作品除外 | 確定（第7・8・11章） |
| `title`・`original_title`・`overview` の前後Unicode空白を `mb_trim()` で除去・Unicode空白のみ欠損扱い | 確定（第7・11章） |
| 部分成功の件数・注意文、0件の「該当作品なし」 | 確定（第10章、Issue 212の実装対象） |
| 上流API失敗時の502/503対応とエラーJSON | 確定（第9章参照） |
| 目標342pxのポスターサイズ選択規則・`original` 不使用・数値サイズなしでNo Image | 確定（第8・11章） |
| `images.secure_base_url` のHTTPS・TMDB配信先検証、Configuration欠損／不正時のNo Image | 確定（第8・11章） |
| `poster_path` 欠落・`null`・空文字・形式不正時の `poster_url: null` と既存 `no-image.png` 表示 | 確定（第7・8・10章） |
| 管理者向けTMDB検索文言（タイトル不明、画面ラベル、alt、timeoutを含む）を実装時に `lang/ja/admin.php` で一元管理・`__()` とBlade経由でJavaScriptへ受け渡し | 確定（第7・9・10・11章） |
| 422／401／403／502／503・ブラウザ通信失敗時の画面内表示、検索結果のクリア、入力保持・再検索 | 確定（第9・10・11章） |
| 検索中の入力欄・ボタン無効化、処理中フラグで多重送信防止、終了後の再検索許可・A11y状態通知 | 確定（第10・11章） |
| Playwrightでは検索結果GET・TMDB画像CDNをモック／遮断し、Laravel→TMDBの通信はFeature / Serviceテストで担保 | 確定（第11章） |
| TMDBジャンルとアプリ独自カテゴリー | Issue 212対象外。ジャンル取得・JSON変換はIssue 231、検索結果カードへの参考表示はIssue 232、登録モーダルでの参考表示とアプリ独自カテゴリーの選択はIssue 230で扱う |

### Issue 231の実装前設計決定（2026-10-28）

| 項目 | 設計方針 |
|---|---|
| Laravelの変更範囲 | `TmdbMovieService`と既存検索結果JSONを拡張し、ルート・Controllerは追加しない |
| Genre List通信 | 有効な先頭最大5件に正の整数のジャンルIDがある場合だけ、`language=ja-JP`で最大1回取得 |
| 検証・変換 | JSON配列／オブジェクトの型を保持し、IDは正の整数、名称は`mb_trim()`、重複は最初の有効値を採用、映画側ID順を維持 |
| フォールバック | Genre Listの429を含む障害時は`genres: []`で映画・ポスター・`summary`を維持。自動retryなし |
| 通信時間 | 条件付きの最大3リクエストを直列実行。各5秒、Axios 20秒を維持するが、全体20秒以内の完了は保証しない |
| テスト・UI | PHPUnitはCodexがAAAコメント付きで追加・更新。E2Eはfixtureと既存6シナリオを維持。検索結果カードへのジャンル表示はIssue 232、登録モーダルでの参考表示はIssue 230 |

## 13. 公式一次資料

- [TMDB Application Authentication](https://developer.themoviedb.org/docs/authentication-application)
- [TMDB Search - Movies](https://developer.themoviedb.org/reference/search-movie)
- [TMDB Genres - Movie List](https://developer.themoviedb.org/reference/genre-movie-list)
- [TMDB Configuration Details](https://developer.themoviedb.org/reference/configuration-details)
- [TMDB Image Basics](https://developer.themoviedb.org/docs/image-basics)
- [TMDB Rate Limiting](https://developer.themoviedb.org/docs/rate-limiting)
- [Laravel 13 Configuration](https://laravel.com/docs/13.x/configuration)
- [Laravel 13 HTTP Client](https://laravel.com/docs/13.x/http-client)
- [Laravel 13 Validation](https://laravel.com/docs/13.x/validation)
- [Laravel 13 Localization](https://laravel.com/docs/13.x/localization)
- [Laravel 13 Blade Templates（Js::from）](https://laravel.com/docs/13.x/blade)
- [Laravel 13 Requests（入力値のマージ・入力値のトリミングと正規化）](https://laravel.com/docs/13.x/requests#input-trimming-and-normalization)
- [ECMAScript String.prototype.trim（JavaScriptの前後空白除去）](https://tc39.es/ecma262/multipage/text-processing.html#sec-string.prototype.trim)
- [PHP 8.4 mb_trim](https://www.php.net/manual/en/function.mb-trim.php)
- [PHP json_decode](https://www.php.net/manual/en/function.json-decode.php)
- [PHP PCREアンカー（`\A` / `\z`）](https://www.php.net/manual/en/regexp.reference.anchors.php)
- [Axios Request Config（timeout）](https://axios-http.com/docs/req_config)
- [Playwright Network（route / fulfill / abort）](https://playwright.dev/docs/network)
- [MDN HTML disabled属性](https://developer.mozilla.org/ja/docs/Web/HTML/Reference/Attributes/disabled)
- [Tailwind CSS v4 Detecting classes in source files（`source(none)`・`@source`）](https://tailwindcss.com/docs/detecting-classes-in-source-files)
- [MDN Intl.Segmenter（grapheme単位の分割）](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Intl/Segmenter)
- [MDN CSS align-items（Flexboxの交差軸配置）](https://developer.mozilla.org/en-US/docs/Web/CSS/align-items)

2026-10-20にIssue 212、TMDB Movie Search・Search & Query For Details・Image Basics、Laravel 13 HTTP Client・Validation・Requests、PHP 8.4 `mb_trim()` を再確認した。先頭5件の検証・部分成功・`summary` は本アプリで合意した応答契約であり、TMDB公式の仕様とは区別する。Laravel HTTP ClientのJSON取得やHTTP成功判定だけに依存せず、第7章の形状・作品検証を行う。

Codex・Claude Codeの実装前再レビュー指摘を受け、PHP公式の `json_decode`・PCREアンカー・`mb_trim`、Axiosのtimeout、Playwrightのネットワークスタブ方法を確認した。20秒timeoutと文字列のUnicode空白除去はプロジェクトで合意したアプリ独自の設計値・正規化方針であり、外部サービスの推奨値とは区別する。

2026-10-28にIssue 231の設計確定にあたり、TMDB Movie Search／Genre Movie List／Rate Limiting、Laravel 13 HTTP Client、PHP `mb_trim()`／`json_decode()`、Playwright Network、Axios timeoutの公式一次情報を再確認した。`genres`の出力形式、型の厳格判定、429時の障害分離、各5秒・Axios20秒は**本アプリ側の設計判断**であり、TMDBが保証した応答・推奨値とは区別する。

2026-10-29にIssue 232の設計確定にあたり、Tailwind CSS v4のソース検出、MDNの`Intl.Segmenter`とCSS `align-items`の一次資料を確認した。100 grapheme・非対応時の全文表示・単一JSファイルの`@source`指定・ポスター寸法112×176pxは**本アプリの設計判断**であり、公式側が推奨するアプリ固有の表示値ではない。

各APIレスポンスの実データや実トークンは本書に保存しない。テストには合成データだけを使用する。

## 14. Genre Movie List取得・ジャンル変換（Issue 231）

本章は2026-10-28に**実装前に確定した設計仕様**を記録する。Issue 231はその後、PR 233（マージコミット`519862f`）により2026-10-29に`develop`へマージ済みである。`main`へは未同期であり、IssueのCloseは`main`反映時に行う。Issue 212の検索・ポスター表示・認証／認可・エラー契約を基礎として、既存`TmdbMovieService::search()`の戻り値だけを拡張した。アプリ独自の`category_id`とは対応付けず、作品／ジャンルのDB保存も行わない。

### 14.1 取得元・タイミング・通信回数

1. Movie Searchの成功応答を既存のオブジェクトモードでデコードし、先頭最大5件を検証して表示可能な映画だけを確定する。検索0件・全件不正は既存規則に従い、その時点で終了する。
2. **検証済み表示対象だけ**の`genre_ids`を調べる。除外した作品や6件目以降の`genre_ids`は参照しない。ジャンルの欠損や不正型は映画の除外条件にしない。
3. 有効な表示対象のいずれかに正の整数ジャンルIDが1つ以上ある場合に限り、`GET https://api.themoviedb.org/3/genre/movie/list`へ`language=ja-JP`を指定してアクセスする。そうでなければ呼び出さず、各映画の`genres`は空配列とする。
4. Genre Movie ListのHTTPリクエストは**検索1回につき最大1回**であり、複数作品のIDを一括対応付けする。既存のMovie SearchとConfigurationと同じサーバー側Bearer Tokenを使用し、各リクエストに`timeout(5)`を指定する。自動retry、並列化、複数検索をまたぐキャッシュ、永続キャッシュは行わない。検索ごとに必要なら再取得する。
5. ConfigurationとGenre Movie Listの成功・失敗は独立させる。一方の失敗で他方の必要な通信を省略しない。両方が必要ならMovie Search→Configuration→Genre Movie Listの順で合計最大3通信を直列に行う。Configurationが失敗・タイムアウトしても、Laravel側の処理が継続している限り、必要なGenre Movie List通信を省略しない。ブラウザのAxios 20秒timeoutはサーバー処理の自動停止を保証せず、全体の20秒以内の完了も保証しない（第6章）。

### 14.2 `genre_ids`とGenre Movie Listの厳格な検証

- Movie Searchの各映画の`genre_ids`は、欠落・`null`・JSON配列以外（JSONオブジェクト`{}`、`{"0":28}`、文字列、数値、真偽値など）の場合、**その作品の有効ジャンルIDなし**として扱う。JSONオブジェクトを配列へ救済しない。JSON配列はオブジェクトモードでデコードした後のPHPリスト配列（`is_array`かつ`array_is_list`）として判定する。
- `genre_ids`の配列要素は`is_int($id) && $id >= 1`相当の条件だけ有効とする。0・負数・小数・数値文字列・真偽値・`null`・オブジェクトは除外し、型変換しない。同一作品内でIDが重複する場合は**最初の出現を残す**。元の`genre_ids`配列順を維持する。
- Genre Movie ListのHTTP 2xx成功応答も`json_decode($body, false, 512, JSON_THROW_ON_ERROR)`相当で型を保持して検証する。トップレベルはJSONオブジェクト、`genres`はJSON配列（PHPリスト配列）を必須とする。`{"genres":[]}`は有効な空一覧である。トップレベルの型不正、`genres`欠落・`null`・JSONオブジェクト・連想配列化・文字列・JSON破損・深さ超過・不正UTF-8は、一覧取得全体の失敗として扱う。
- `genres`配列内の各定義はJSONオブジェクトで、`id`が`is_int($id) && $id >= 1`相当、`name`が`is_string($name)`であることを必要とする。IDが0・負数・小数・数値文字列・真偽値・`null`・配列・オブジェクトの場合は型変換せず、その定義だけ除外する。名称にはPHP 8.4の`mb_trim($name, null, 'UTF-8')`を適用し、前後のUnicode空白を除いた値を**公開名**に採用する。内部の空白・改行は維持し、正規化後に空文字となった名前は不正としてその定義だけ除外する。数値や配列を文字列へ強制変換しない。
- Genre Movie List内で同じIDが複数定義された場合は、**最初の有効なID・名称の組**を採用する。不正な先行定義はIDを使用済みにせず、後続の有効定義を採用できる。有効な定義を採用した後の同一IDは無視する。
- 対応表の取得・検証が成功した場合だけ映画側のID順に名前を対応付け、一覧に存在しないIDはそのIDだけ除外する。ジャンル配列を名称順・ID順・Genre Movie Listの定義順へ並べ替えない。

### 14.3 公開レスポンスとフォールバック

- 既存`GET /admin/tmdb/search/results`の各**有効な映画**に、`genres: [{"id": 整数, "name": 文字列}, ...]`を**必須キー**として追加する。有効なジャンルがない場合は`genres: []`とする。公開値はIDと名称だけで、内部処理用`genre_ids`、ジャンル一覧のrawデータ、その他のTMDB由来キーは公開しない。
- Genre Movie Listの非2xx（401・403・404・**429**・5xx・3xx等）、接続失敗、タイムアウト、JSON破損・構造不正は、**全作品の`genres: []`へ縮退**する。HTTP異常本文は解析しない。HTTP 4xx/5xxはLaravel HTTP Clientの`successful()`等で明示判定し、通信例外とJSON例外はGenre Movie List処理内で捕捉する。ジャンル障害だけを既存Controllerへ伝播させない。
- Movie Search自身の429は既存どおり503とするが、**Genre Movie Listだけの429ではMovie Search成功結果をHTTP 200で維持**し、自動retryしない。これは補助情報と必須検索の障害境界を分ける本アプリ固有の設計である。TMDB公式が両エンドポイントに対してこのHTTP応答を推奨しているわけではない。
- Genre Movie Listの障害と、検索結果に有効なジャンルが存在しない状態は、今回のJSONではどちらも`genres: []`として表し、区別用フラグや画面警告を追加しない。障害時の生レスポンス・Token・リクエスト検索語・URLクエリ・例外詳細はブラウザへ公開せず、ログへも記録しない。第9章と同じく、診断情報を扱う場合は記録項目を安全な情報に限定する。今回はジャンル障害のための追加ログ機能は設けない。
- ジャンル取得の成否は、映画の既存`id`・`title`等、`poster_url`、`summary.checked`・`summary.displayed`、部分成功の除外件数に影響させない。ConfigurationとGenre Movie Listがともに失敗しても映画情報は維持し、ポスターはNo Image、ジャンルは空配列へ別々にフォールバックする。Movie Searchの既存の502/503契約は維持する。
- Issue 231ではBlade・JavaScriptを変更せず、ジャンルを画面へ表示しない。**検索結果カードへのジャンル参考表示・概要100文字省略・ポスター表示寸法の調整はIssue 232**、**作品登録モーダルでのジャンル参考表示・アプリ独自カテゴリー選択はIssue 230**で扱う。TMDBジャンルと`category_id`の自動対応は行わない。

### 14.4 Serviceへの変更境界・品質確認

- 公開メソッド`search()`が既存の検索・検証・画像設定処理を統括する構造を維持し、**追加したジャンル関連処理**を3つのprivateメソッドに分離する。`normalizeGenreIds()`が映画側IDを正規化し、`fetchGenreNameMap()`がGenre Movie Listを取得・検証してID→名称の対応表を生成し、`buildMovieGenres()`が映画側ID順で公開`genres`配列を構築する。
- 既存Controller・ルート・認可・入力検証は変更せず、検索結果配列へのジャンルキー付与とServiceの`@return`型（`genres: list<array{id: int, name: string}>`）を整合させる。作品の内部`poster_path`と`genre_ids`が最終JSONに混入しないことを確認する。
- PHPUnit／Featureの厳密な完全一致期待値、E2Eの`Movie`型・fixtureは第11章を参照する。関連docsは本節の「ドキュメント整合・更新対象」に従う。実APIへ接続するテスト、検索結果カード・登録モーダルのジャンル表示UI、Movie Details・DB保存・キャッシュ追加はIssue 231の対象外とする。

**ドキュメント整合・更新対象（Issue 231の完了までに確認）**

- `docs/TMDB_SEARCH_DESIGN.md`（本書）: Genre Movie Listの通信・変換・フォールバック・テスト契約を反映し、Issue 212の既存仕様と区別する。
- `docs/FEATURES.md`: 検索結果JSONの複数ジャンル情報をIssue 231の対象として記載する。検索結果カードのジャンル参考表示はIssue 232、登録モーダルでの参考表示とアプリ独自カテゴリー選択はIssue 230の対象と区別し、古い進捗表記を更新する。
- `docs/ROUTES.md`: 新規ルートは追加せず、既存`GET /admin/tmdb/search/results`の成功JSONに`genres`が追加されることを明記し、古い進捗表記を更新する。
- `docs/SCREEN_TRANSITIONS.md`: 既存検索画面・遷移は変更せず、Issue 212の古い進捗表記を整理する。Issue 231でジャンル表示UIが増えたと記載しない。
- `docs/SECURITY.md`: Genre Movie Listの非信頼入力検証、Token・上流生応答の非公開、補助情報障害の独立フォールバックを既存TMDB節に整合させ、古い進捗表記を更新する。詳細仕様は本書を参照する。
- `docs/PLAYWRIGHT_TESTING.md`: テスト運用上必要なら`Movie`型・fixtureの追加を説明する。ブラウザ表示とLaravel→TMDB通信のテスト責務境界は変更しない。
- `README.md`など他の関連文書は、実際に記述の不一致が判明した場合のみ必要最小限で更新する。

上記はIssue 231の実装・PR準備時に定めた文書更新対象の履歴である。**Issue 231はPR 233で`develop`へ統合済み**（`main`未同期）。Issue 232の表示仕様と、その後の実装・ローカル検証結果は第15章に別記し、Issue 231の当時の設計経緯と混同しない。

## 15. 検索結果カードの表示改善（Issue 232）

本章は2026-10-29のClaude Code・Codexによる**独立した実装前検証**を経て確定した設計と、その後の**作業ブランチでの実装・ローカル検証結果**を記録する。Issue 231の`genres`付きJSONはPR 233で、Issue 232の検索カード改善はPR 234でそれぞれ`develop`へ統合済みである。実装・テストの詳細は当時の検証記録として残し、**Issue 230の新規実装結果と混同しない**。

### 15.1 変更範囲と責務

- 既存の`resources/js/tmdb-search.js`にある`createMovieCard(movie, messages)`を拡張し、既存の映画カードへ**TMDBジャンルの参考表示**と**概要の表示用省略**を追加する。別のLaravel APIや画面コンポーネントは設けない。
- `GET /admin/tmdb/search/results`が返す`results`・`summary`および各映画の`overview`全文・`genres`配列は変更しない。`movie.overview`や`movie.genres`へ書き戻さず、描画直前に表示用の値を別に組み立てる。将来の作品登録処理で元の概要全文を利用できるようにする。
- ルート・Controller・`TmdbMovieService`・TMDB API通信・Bearer Token・DB・migration・アプリ独自カテゴリーとの対応付け・作品登録モーダル（Issue 230）・ページネーション（Issue 227）は変更しない。
- 既存の最大5件・検索中・成功／部分成功・0件・エラー・再検索・認証／認可・No Image・アクセシビリティの契約（第7〜11章）を維持する。

### 15.2 TMDBジャンルの参考表示

- 各カードへ`TMDBジャンル（参考）：名称1・名称2`という表示行を追加する。ラベルは`lang/ja/admin.php`の`admin.tmdb.search.ui.genres`を新設し、値を**「TMDBジャンル（参考）」**とする。Bladeの`$tmdbSearchConfig['messages']['genres']`から`Js::from()`を経てJavaScriptへ渡す。アプリ独自のカテゴリーとの違いを明示する。
- `movie.genres`が配列であれば、その**配列の出現順**を保ち、有効な`name`を`・`で結合する。区切り文字はIssue仕様上の固定値であり、今回の表示処理で`join('・')`を使用する。名称順・ID順への並べ替え、表示側での重複除去、JSONへの書き戻しはしない。
- `genres`の欠損・`null`・非配列・空配列は有効ジャンルなしとする。配列内でも`null`、配列、オブジェクト以外の要素、`name`欠損、非文字列、JavaScriptの`trim()`で空白だけとなる名称を**表示対象から除外**する。正常な名称の元文字列は変更せず、IDの厳格な再検証などServiceの処理を重複実装しない。
- 有効名称が0件になった場合は既存`messages.notAvailable`の**「情報なし」**を表示する。配列をそのまま`appendField()`へ渡して空文字にならないよう、表示用の文字列または`null`を渡す。
- ジャンル名称はTMDB由来の**非信頼文字列**として扱う。既存の`createElement()`・`textContent`で安全に描画し、`innerHTML`、HTML文字列の組み立て、属性イベントの挿入には使用しない。ジャンル欠損や補助API障害により、映画・ポスター・`summary`・検索処理全体を失敗させない。

### 15.3 概要の100文字省略とUnicode

- 検索結果カードに表示する`overview`のみ、**Unicodeの書記素クラスタ（grapheme）単位で最大100個**とする。JavaScriptのUTF-16コード単位の`.length`や`slice(0, 100)`、コードポイント単位の`Array.from()`で切らない。
- 対応環境では`Intl.Segmenter('ja-JP', { granularity: 'grapheme' })`で分割する。**100個以下は原文をそのまま表示**し、**101個以上の場合だけ先頭100個を連結し、末尾にASCIIの半角ピリオド3個`...`を付加**する。`...`は100個の文字数制限とは別の省略記号で、U+2026の三点リーダーへ置き換えない。結合文字・肌色修飾・ZWJを含む複合絵文字・国旗などの書記素クラスタを途中で分断しない。
- `overview`欠損・`null`は既存の**「情報なし」**を表示する。正規化前のTMDB概要に対する検証は引き続きServiceの責務とし、表示側で`overview`全文を書き換えたり、JSONの値を切り詰めたりしない。
- `Intl.Segmenter`が存在しないブラウザでは**概要全文を`textContent`で表示**し、検索処理を異常終了させない。追加の互換ライブラリやコードポイント単位の擬似フォールバックは導入しない。**非対応環境では100文字の上限を保証しない**ことを明示する。現行PlaywrightはChromiumを検証対象とし、その他のブラウザの包括的な互換性保証は本Issueの対象外とする。
- 本仕様は**表示文字数の制限**であり、改行や詳細情報によるカード全体の高さを一定に固定する仕様ではない。CSSの`line-clamp`による追加の行数制限は設けない。

### 15.4 ポスター寸法とTailwind CSS v4の検出設定

- ポスター`<img>`の既存`width="112"`・`height="176"`属性と、`h-44 w-28 shrink-0 rounded-md bg-gray-100 object-cover`を維持する。PC・モバイルでCSS適用後の**描画矩形が幅112px・高さ176px**であり、概要やジャンル行の長さで引き伸ばされないことを確認する。
- 既存のカード`flex flex-col ... sm:flex-row`とポスターの明示的な高さを優先し、**設計時点では`items-start`・`self-start`・専用CSSを追加しない**。Flexboxの交差軸`stretch`は、サイズが明示されたポスターを拡大する根拠にはならない。実ブラウザで寸法が崩れた場合に限り、原因を検証して最小限のクラス変更を判断する。
- `resources/css/app.css`は`@import "tailwindcss" source(none);`によって自動ソース検出を停止している。**`@source "../js/tmdb-search.js";`を1行追加**し、対象JSに記述されたTailwindクラスを明示的な検出対象にする。既存3件の`@source`は維持し、`../js`全体への拡大や依存追加は行わない。クラス名はJavaScriptに完全な静的文字列として記述する。
- `npm run build`を新規実行後、生成CSSに**既存クラスも含めて**`h-44`・`w-28`・`shrink-0`・`object-cover`・レスポンシブ関連クラス等が出力されることを確認する。Playwrightでは`getComputedStyle()`と`getBoundingClientRect()`でCSS適用と実寸を検証する。`width`・`height`属性や画像の自然寸法だけで偶然112×176pxになっても合格としない。
- PC・モバイルで横はみ出し・画像の欠け・文字列のオーバーフロー等を確認し、従来の`/images/no-image.png`・alt・`error`イベント1回のフォールバック動作を維持する。

### 15.5 テスト実装・ローカル品質確認（2026-10-29）

- `e2e/tmdb-search.spec.ts`の**既存6シナリオの本文・アサーションを維持**し、Issue 232の表示テストを**7シナリオ追加**して計13シナリオとした。`e2e/support/tmdb-search.ts`の既存`Movie`型・`genres: []`既定fixture、検索JSONのモックと通信ガードは維持し、異なる縦横比の合成画像を指定できるよう拡張した。
- **ジャンル**: 元順序、`・`区切り、ラベル、単一ジャンル、`[]`、欠損、`null`、非配列、不正要素との混在・全件不正と「情報なし」を合成JSONで検証した。
- **概要**: 99・100・101 graphemeの境界、日本語、結合文字、複合絵文字、`null`・欠損、`Intl.Segmenter`非対応時の全文表示と再検索を検証した。期待値は本番の省略処理から独立して定義した。
- **全文不変**: テストからブラウザ内Axios応答インターセプターで応答オブジェクトを保持し、**描画前後の`overview`全文と`genres`配列の不変性**を検証した。fixtureの送信前後を比べるだけのテストとは区別し、本番コードにテスト専用の公開APIやDOM属性は追加していない。
- **XSS**: `genres[].name`と`overview`の疑似攻撃文字列をテキスト表示し、危険なDOM要素・イベント属性の非生成とJavaScript非実行を確認した。
- **表示寸法**: PC・モバイルで通常ポスターとNo Imageについて、描画矩形112×176px、計算済みCSSの`object-fit: cover`・`flex-shrink: 0`、配置と横はみ出しを確認した。自然寸法への偶然の一致を避けるため、縦横比の異なる合成画像も使用した。
- **テスト通信**: 既存の`page.route()`等によるモックと通信ガードを維持し、実TMDB APIへ接続せずに実行した。Laravel→TMDBの通信・JSON契約は引き続きPHPUnit Feature / Serviceテストの責務とし、画面変更を理由とするServiceテストの重複追加は行わない。
- **対象E2Eのローカル検証結果**: `npm run build`、`npm run typecheck:e2e`、`git diff --check`は成功。`npx playwright test e2e/tmdb-search.spec.ts --project=chromium`は**対象13シナリオ＋`setup` 1件＝`14 passed`（1.7分、終了コード0）**。`setup`実行前に`APP_ENV=e2e`、`e2e_mysql`、設定・実接続先の`e2e_testing`を確認した。対象E2Eの既存6シナリオにも回帰は検出されなかった。
- **ローカル品質ゲートの最終確認結果（Issue 232）**: `sail php ./vendor/bin/pint --test`は120ファイルPASS、`sail php ./vendor/bin/phpstan analyse`は75/75・エラー0件（Level 10）、`sail test`はPHPUnit 527件PASS・2,672 assertions。`sail npm run build`、`npm run typecheck:e2e`、`git diff --check`も成功した。`npx playwright test`は全25件PASS（`setup` 1件＋ブラウザテスト24件）。PCブラウザおよびiPhone Safariで目視確認し、Claude Code・Codexの独立した最終差分レビューも実施した。**PR 234は2026-10-30 JSTに`develop`へマージ済み**。tablet幅・支援技術・包括的なアクセシビリティ監査まで完了したとは扱わない。

### 15.6 変更ファイルと非対象の境界

| ファイル | Issue 232の作業ブランチでの変更 |
|---|---|
| `resources/js/tmdb-search.js` | ジャンル名表示用の整形、概要100 grapheme省略、既存カードへの描画追加 |
| `resources/views/admin/tmdb/search.blade.php` | 翻訳済み`messages.genres`を`Js::from()`の設定へ追加 |
| `lang/ja/admin.php` | `ui.genres`の「TMDBジャンル（参考）」を追加 |
| `resources/css/app.css` | `@source "../js/tmdb-search.js";`を追加 |
| `e2e/tmdb-search.spec.ts` | 既存6シナリオを保持し、ジャンル・概要・XSS・ブラウザ側全文不変・PC／モバイル寸法の7シナリオを追加 |
| `e2e/support/tmdb-search.ts` | 画像モックを拡張し、異なる縦横比の合成画像の返却に対応（既定fixtureと通信ガードを維持） |
| `docs/TMDB_SEARCH_DESIGN.md`（本書） | Issue 232の確定設計、ローカル検証結果、Issue 231の現行進捗を反映 |
| `docs/FEATURES.md` | 検索・ジャンル取得の統合済み機能と、検索カード表示改善の作業ブランチ上の実装を区別 |
| `docs/PLAYWRIGHT_TESTING.md` | 対象13シナリオ（既存6＋追加7）、モックと専用DBの安全な実行結果を反映 |

`README.md`・`SECURITY.md`などは既存記述と矛盾が判明した場合のみ必要最小限で更新する。`package.json`・依存関係・Service・Controller・ルート・DB・登録モーダルは変更しない。Issue 232の範囲外の改善や大規模なリファクタリングは混在させない。

## 16. 作品登録前モーダルの設計（Issue 230）

本章は、[Issue 230](https://github.com/honda-dev-jp/review-app-laravel/issues/230)の**実装前に確定した設計仕様**と、2026-11-01時点の**作業ブランチ上の実装・ローカル検証結果**を区別して記録する。作品登録API・DB保存などの後続範囲は現行の[Issue 228](https://github.com/honda-dev-jp/review-app-laravel/issues/228)が扱う。DB詳細設計・マイグレーション／回帰テスト・フロント入力検証のIssue分割は検討中であり、本書の更新時点では新しいIssue番号・責務移管は未確定とする。第1〜15章の既存TMDB検索・Genre Movie List・カード表示の実装契約を変更しない。

### 16.1 スコープと責務分担

| 項目 | Issue 230（本章） | Issue 228（現行の後続計画） |
|---|---|---|
| 検索結果カード全体の起動操作とモーダル | 実装 | 既存UIを利用 |
| TMDB複数ジャンルの参考表示 | 既存JSONを利用して実装 | 表示を再実装しない |
| 作品タイトル・概要全文の編集UI | 実装 | 編集後の値を検証・保存 |
| アプリ独自12カテゴリーの選択UI | 固定候補で実装 | DBのカテゴリーと対応付け |
| `CategorySeeder`の追加12件・DBからの候補取得 | 対象外 | 実装 |
| 「未定義」の新規登録拒否 | UIでは非表示 | サーバー側でも拒否 |
| 登録ボタン | **表示するが無効** | 有効化・送信・二重送信防止 |
| 登録API・DB保存・重複登録防止 | 対象外 | 実装 |
| 登録成功・登録エラー応答の処理 | 対象外。入力欄・ラベルの関連付けを用意（専用エラー表示領域は未実装） | エラー表示要素・API応答との連携を後続で実装 |
| 作品タイトル・概要・カテゴリーのフロント入力検証 | 未実装（登録ボタンは無効） | 別Issueへの分離を検討中。実装前に入力契約を確定 |
| ARIA・フォーカス制御 | 実装 | 既存の挙動を維持 |

- 上表は本書更新時点の責務境界を示す。後続機能を複数のIssueへ分割する案はあるが、Issueの新設・移管が正式に決まるまでは既存のIssue 228の記述を正本として扱う。
- Issue 230は`items`・`categories`等のDBに書き込まない。新規Laravel登録ルート・TMDB API通信・検索JSONのフィールド追加は行わない。
- 既存の`GET /admin/tmdb/search`と`GET /admin/tmdb/search/results`を利用し、管理者向けの`auth`・`can:access-admin-panel`を維持する。
- 作品の登録成功を装うダミー処理は作らず、登録ボタンはIssue 228でAPI連携するまで無効とする。
- 本章でカテゴリーのDB ID、登録APIのHTTPメソッド・URL、`tmdb_id`の保存形式、保存対象カラムを仮決定しない。
- **モーダル実装方式（確定方針）**：本画面専用にHTML標準の`<dialog>`を1個配置し、`showModal()`／`close()`と既存の`resources/js/tmdb-search.js`のJavaScriptから制御する。既存`resources/views/components/modal.blade.php`は変更しない。新規画面と既存の退会・確認モーダルを混在させず、既存の共通モーダルへの回帰影響を限定する。
- **フォームの分離**：登録前モーダルの`<dialog>`と編集用入力欄は既存のTMDB検索`<form id="tmdb-search-form">`の外に配置し、入力欄を検索フォームへ`form`属性で関連付けない。×・キャンセルなど送信不要のボタンは`type="button"`とし、タイトル欄でEnterを押しても検索フォーム送信・登録送信・意図しないモーダル終了が発生しないようにする。

### 16.2 起動と検索データの引き継ぎ

1. 管理者がTMDB検索を実行し、既存の検索結果カード（最大5件）を表示する。
2. **各映画カード全体をクリック／タップしてモーダルを開く。** カード末尾に独立した可視の「登録内容を確認」ボタンは追加しない。既存の`<article>`とその子要素（`<img>`、`<h2>`、`<p>`等）の構造・見出しセマンティクスを維持する。
   - **操作方式（確定）**：各`<article>`を相対配置の基準とし、**`<article>`直下で既存の`<img>`・情報用`<div>`と同階層に単一の`<button type="button">`**を追加する。情報用`<div>`内の`<h2>`・`<p>`はそのまま維持し、`<button>`の子要素として包まない。ボタンを絶対配置してカードの全面を覆う透明な操作領域とし、`<article>`自体に`role="button"`や`tabindex`を付けない。
   - 透明ボタンは、見た目の透明性を保ちながらポインターイベントを受け付ける。ボタン全体を`opacity: 0`にしてフォーカス表示まで消さない。`:focus-visible`時はカード全体の輪郭が明確に分かるようにし、重なり順を調整してポスター・見出し・本文を視覚的に隠さない。ボタンを`aria-hidden`にしない。
   - ボタンは標準のTab移動とEnter・Spaceによる起動に対応する。**ボタン1個につきTab停止は1回**とし、起動はネイティブの`click`イベントで一元化する。Enter・Space用の重複した起動ハンドラーは追加しない。翻訳済み文言を用いて「『:title』の登録内容を確認」のように**作品名と動作を含むアクセシブルな名前**を付ける。開閉後のフォーカス復帰先として、実際に操作されたボタンを記録する。
   - **全面を覆うボタンのため、カード上の通常のドラッグ操作による文字選択・コピーはできない**ことを本画面のUX上の制約として受け入れる。本文の確認・概要全文の編集とテキスト選択はモーダル内で行えるようにする。モバイルの縦スクロール、画像・タイトル・概要・余白の操作範囲、フォーカス輪郭、見出しの読み上げを実ブラウザで確認し、カード全面の押下領域を維持する。
   - **操作可能性の提示**：マウスホバー時にはカードの枠線や影を控えめに変化させ、カード全面が操作対象であることを伝える。キーボードでは前記の`:focus-visible`を優先し、モバイルではホバーだけを操作の手がかりにしない。
3. **ページ遷移せず**に、選択した映画1件のモーダルを開く。
4. モーダルは第7章の正規化済み`results`要素の`id`、`title`、`original_title`、`release_date`、`overview`、`poster_url`、`genres`を参照する。
5. 一覧カード側の`overview`はIssue 232で100書記素クラスタに省略表示しているが、**モーダルには検索JSON内の省略前の全文**を初期表示する。カードの表示テキストや`...`付き文字列から復元しない。
6. 別の作品を開く際は、その作品の元データで編集可能項目を初期化し、カテゴリーは未選択に戻す。過去に開いた作品の入力を混入させない。

**画面内で再利用するモーダルは1個**とし、カードごとには生成しない。`tmdb-search.js`がモーダルの選択作品、編集状態、開閉を一元管理する。既存の検索結果カード描画と異なる状態管理を二重に持たせない。

モーダルを開く直前に、選択した`movie`から必要な値を**元の検索データとは独立した編集用入力欄の`value`等へコピー**する。変更可能な`title`と`overview`は入力欄側で編集し、元の検索JSONやカード描画用`movie`には書き戻さない。`genres`等の参照情報も元データを変更せず表示する。閉じた際は編集値を破棄し、再表示時は選択した作品から初期化する。別の専用JavaScript状態オブジェクトを必須とはしない。

カードの見出しなど静的な情報要素をTab順へ追加しない。**カードごとに1つの操作対象**をTab順に含め、明瞭なフォーカス表示を設ける。クリック・タップ・Enter・Spaceはすべて同じモーダル起動処理につなぐ。

**検索結果の操作案内（Issue 230で確定）**

- 検索結果が**1件以上**表示された場合、既存の検索結果件数表示（`tmdb-search-status`）の**すぐ下**に「**作品カードを選択すると、登録内容を確認・編集できます。**」を表示する。部分成功でも実際の表示件数が1件以上なら表示する。
- **検索前・0件・検索エラー時・再検索中は非表示**とする。再検索の開始時には前回の操作案内を即座に消し、検索結果が確定した時点で表示件数に応じて切り替える。
- 件数・状態通知と案内文は**別々のDOM要素**で管理する。案内要素の表示は検索結果の状態に合わせて制御し、既存の件数表示やエラー通知の`textContent`へ案内を混在させない。文言は`lang/ja/admin.php`の`admin.tmdb.search.ui.select_movie_hint`に集約する。

### 16.3 表示・編集フィールド

| フィールド | 既存検索JSONのキー | モーダルでの扱い | 備考 |
|---|---|---|---|
| TMDB映画ID | `id` | **参照のみ** | 識別情報。変更不可 |
| ポスター | `poster_url` | **参照のみ** | 欠損・画像読み込み失敗時は既存No Image |
| TMDBジャンル | `genres` | **参照のみ** | 複数の名称を参考表示。`[]`・欠損・無効値は既存の「情報なし」を使用 |
| 作品タイトル | `title` | **編集可能** | 正規化済みタイトルを初期値とする |
| 原題 | `original_title` | **参照のみ** | 欠損時は既存の情報なし表示方針 |
| 公開日 | `release_date` | **参照のみ** | 欠損時もレイアウトを維持 |
| 概要 | `overview` | **全文を編集可能** | 複数行入力。カードの100文字省略結果は使わない |
| アプリ独自カテゴリー | 検索JSONには含まれない | **1件を選択** | 初期値は未選択。TMDBジャンルとは独立 |

- 編集可否が伝わるラベルとUIとし、長文・改行・欠損値で表示が破綻しないようにする。
- 作品タイトル・概要のサーバー側の必須・文字数制限等の正式な検証は後続の登録機能設計（現行Issue 228）で確定する。Issue 230のUIだけで保存可能なデータであると保証しない。
- **フロント入力検証の現状**：Issue 230の作業ブランチでは編集用タイトル・概要に`required`・`maxlength`等の検証制約を付けておらず、カテゴリー未選択のエラー表示も実装していない。登録ボタンは無効のままである。既存[DB設計](DATABASE.md)の`items.title`（`varchar(255)`）、`items.description`（`text nullable`）、`items.category_id`（必須外部キー）を踏まえ、後続工程でブラウザとLaravelの入力契約・文字数の数え方・エラー表示とARIAの連携を確定する。フロント入力検証を独立Issueにする案は**未起票・番号未確定**であり、現時点の機能として扱わない。
- `genres`は第7章のデータ構造に従い、TMDBジャンルから独自カテゴリーを自動選択・自動変換しない。情報なしの文言は既存の`admin.tmdb.search.ui.not_available`を再利用し、新しい同義キーを追加しない。
- `overview`が`null`等で存在しない場合は、編集用の複数行入力を空文字で初期化する。**表示向け代替文言の「情報なし」を入力値として混入させない。**
- TMDB由来文字列は非信頼として扱い、既存の`textContent`等の安全なDOM表示方式を維持する。利用者入力をHTMLとして解釈しない。

### 16.4 アプリ独自カテゴリーの確定選択肢

次の**12件を固定候補として、表の順番で**単一選択セレクトボックスに表示する。この順序と名称の正本は[DB設計](DATABASE.md)のMVP2カテゴリー仕様である。

| 表示順 | カテゴリー |
|---:|---|
| 1 | アクション |
| 2 | アドベンチャー |
| 3 | アニメーション |
| 4 | コメディ |
| 5 | ヒューマンドラマ |
| 6 | SF |
| 7 | ファンタジー |
| 8 | ホラー |
| 9 | サスペンス・ミステリー |
| 10 | 恋愛 |
| 11 | ドキュメンタリー |
| 12 | その他 |

- 初期表示は**「カテゴリーを選択してください」**とし、いずれのカテゴリーも自動選択しない。
- **「未定義」は新規作品登録候補に表示しない**。既存`CategorySeeder`・`ItemSeeder`が使用するDB上の「未定義」は削除しない。
- カテゴリーは**1作品につき1件**。複数選択や、`genres`との自動対応付けは行わない。
- Issue 230では固定の表示名称のみを管理し、DBの`categories.id`を推測した数値や仮IDとして割り振らない。
- Issue 228でSeederに12件を追加し、既存「未定義」を含むDB側は13種類として整備する。固定UIの選択値と実DBの`category_id`を対応させる際はDBの存在確認に加え、**「未定義」および許可対象外の値をサーバー側で拒否**する。
- DBには表示順カラムがないため、表示順の番号をDB IDと同一視しない。Issue 228のDB連携後も12件の表示順を維持する。
- Issue 230では、12件の候補名と表示順を`lang/ja/admin.php`内の`admin.tmdb.search.categories`に**順序付きの文字列配列として集約**し、既存のBlade設定と`Js::from()`経由でJavaScriptに渡す。選択値にはカテゴリー名など**DB IDではない文字列**を使用し、実際の`categories.id`を仮装した数値を割り当てない。**この文字列はIssue 230ではサーバーへ送信しない。** Issue 228で正式なDB `category_id`への切り替え・検証を行う。

### 16.5 ボタン、終了・状態管理

- **×（閉じる）**：アクセシブルな名前を「閉じる」とし、編集中のタイトル・概要・カテゴリー選択状態を破棄して閉じる。
- **キャンセル**：×と同じ終了処理を呼び出し、同じ初期化・フォーカス復帰を行う。
- **登録**：表示するが、Issue 228で登録APIに接続するまではHTMLの`disabled`属性で**無効**とする。ボタンの近くに「登録機能は準備中です。」という**常時見える補足文**を配置し、必要に応じて`aria-describedby`で関連付ける。無効ボタンにフォーカスできることを前提に説明を提供しない。送信イベント・DB保存・偽の成功通知は発生させない。
- **Escape**：`<dialog>`の標準のクローズ要求を受け入れ、編集中の値を破棄する。×・キャンセルと同じ後処理へ統一する。
- モーダルを閉じて再び開くときは、選択作品の元データとカテゴリー未選択状態から開始する。
- 登録APIの検証エラーを後で表示できるよう、フィールドごとのエラー表示位置・ラベル関連付けを考慮する。ただしレスポンスに応じたエラー表示実装はIssue 228の責務とする。
- **モーダル外の背景クリックでは閉じない。** 編集中の誤タップによる内容破棄を避けるため、背景をクリックしてもクローズ処理を呼ばない。×・キャンセル・Escapeで終了できる。既存の共通モーダルの背景クリック挙動は変更しない。

### 16.6 ARIA・キーボード・フォーカス制御

既存の`resources/views/components/modal.blade.php`は、Tab/Shift+Tabのフォーカストラップと起動元への復帰処理を実装しているが、確認した実装では背景スクロール停止のみで背景要素を`inert`にはしていない。そのため、**Issue 230の新規モーダルは既存共通コンポーネントを変更せず、HTML標準の`<dialog>`を`showModal()`で表示する設計を採用する**。ネイティブモーダルは背景をブラウザの仕組みで操作不能にする。`open`属性の直接付与や`show()`による非モーダル表示は使用しない。

`<dialog>`へは可視見出しを用いた`aria-labelledby`を設定し、ダイアログ本体に`tabindex`を付けない。`showModal()`でのモーダル表示によって適切なダイアログセマンティクスが提供されるため、カスタム`role="dialog"`や手作業の背景`inert`切り替えを重ねない。既存の共通モーダルの仕様を、このIssueで無関係に変更しない。

[W3C WAI-ARIA APG Modal Dialog Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/)を基準に、以下を満たす。

- モーダルを開くときは内容を設定してから`showModal()`を実行し、`tabindex="-1"`を付けた**可視見出し**へ明示的に初期フォーカスを置く。長い概要などによって冒頭が画面外へスクロールしないようにする。
- `showModal()`によるブラウザ標準のモーダル化で背景を操作不能にする。背景スクロール停止は補助的な見た目・操作性対策であり、背景操作抑止の代替にはしない。
- `Tab`・`Shift+Tab`でモーダル内のフォーカスを保持・循環させる。
- `Escape`、×、キャンセルで閉じられる。
- ×・キャンセル・Escapeのいずれでも、ダイアログの`close`イベントを共通後処理として編集状態を破棄し、フォーカスを復帰する。原則として元の映画カード全体を操作する要素へフォーカスを戻し、起動元がDOMから消えた場合は検索入力欄等の論理的な代替復帰先を用意する。フォーカス可能な要素か確認して復帰し、別の場所へ不意に移動させない。
- モーダルの可視見出しと`aria-labelledby`、タイトル・概要・カテゴリーと`label`、登録API接続後のエラー向け`aria-invalid`・`aria-describedby`を整合させる。
- 無効な登録ボタンは操作できず、未実装の状態を誤認させない。
- PC・モバイルでレイアウト・内部スクロール・キーボード操作が成立することを確認し、必要に応じて支援技術による確認を行う。モーダル背景に対するクリック・Tabでの到達・支援技術からのアクセスについて、ブラウザ実機で確認する。

### 16.7 文言・カテゴリー固定候補の管理（確定方針）

既存の`lang/ja/admin.php`を利用し、`admin.tmdb.search.ui.*`へ本モーダル用の文言を追加する。Bladeで翻訳を解決し、既存方式の`Js::from()`でブラウザへ渡す。以下のキーと文言は、**Issue 230の作業ブランチで実装済み**である。既存キーは重複追加しない。

| Issue 230追加キー（`admin.tmdb.search.ui.*`） | 日本語表示 |
|---|---|
| `open_registration_modal_accessible` | 「:title」の登録内容を確認 |
| `select_movie_hint` | 作品カードを選択すると、登録内容を確認・編集できます。 |
| `registration_modal_title` | 作品登録前の確認 |
| `close_modal` | 閉じる |
| `cancel` | キャンセル |
| `register` | 登録 |
| `registration_unavailable` | 登録機能は準備中です。 |
| `editable_title` | 作品タイトル |
| `category_label` | アプリ独自カテゴリー |
| `category_placeholder` | カテゴリーを選択してください |

- **概要の入力ラベル**には既存の`admin.tmdb.search.ui.overview`（「概要」）を再利用し、`editable_overview`は新設しない。`editable_title`（「作品タイトル」）は既存の検索入力ラベル`query_label`（「映画タイトル」）と表示文言が異なるため、新設する。
- 既存の`tmdb_id`、`original_title`、`release_date`、`genres`、`poster_alt`、`no_poster_alt`、`not_available`は新設せず再利用する。
- `admin.tmdb.search.categories`は第16.4節の正式12件を確定順の**文字列配列**として保持し、必要な表示テキストだけをBlade経由で渡す。アプリのDB IDや登録権限の正本にしない。
- `lang/ja/admin.php`へのキー追加、Bladeの`$tmdbSearchConfig`を介した翻訳済み文言・カテゴリー候補のJavaScriptへの受け渡しは**Issue 230の作業ブランチで実装済み**。未コミット差分のため、`develop`への統合済みとは記載しない。

### 16.8 セキュリティと非対象

- TMDB APIトークン、内部例外、TMDB raw responseをブラウザへ渡さない。ポスターURLの既存検証とNo Imageへのフォールバックを維持する。
- モーダル表示のためにLaravelの認証・認可、検索JSON契約、検索件数上限、検索失敗・部分成功処理を変更しない。
- 文字列を`innerHTML`等のHTMLパーサーに無検証で渡さず、表示と入力の双方でXSS対策を維持する。
- CategorySeeder拡張、DBからのカテゴリー取得、`category_id`の確定と検証、`tmdb_id`のDB設計、DB登録・重複登録防止、CSRF、送信中・成功・失敗処理はIssue 228で実装する。
- 登録済み作品の編集・削除、複数作品の一括登録、TMDBジャンル自動マッピング、検索ページネーション（Issue 227）は対象外。
- TMDBの帰属表示・データ／画像の保存条件は別途Issue 98で公式条件を確認する。保存条件が未確定の段階で保存方針を断定しない。

### 16.9 テスト計画・品質ゲート（実装前策定の計画）

**PHPUnit / Feature（必要範囲）**

- 既存管理者検索ルートの認証・認可、正規化済み検索JSONの契約が変更されていないことを回帰確認する。
- Bladeからのモーダル用設定や翻訳文言に変更がある場合は、正常系と境界値を検証する。
- Seeder再実行・DBカテゴリー取得・実作品登録APIのFeatureテストは**Issue 228**で実施する。

**Playwright E2E（TMDB通信は既存モックを使用）**

- **映画カード全体**（画像・タイトル・概要・余白を含む）のクリック／タップによるモーダル起動、カードごとのTabフォーカス・Enter・Spaceによる1回だけの起動、選択した作品の情報表示、ページ遷移なしの開閉。末尾に独立した可視の起動ボタンを追加していないこと。`<article>`と`<h2>`の構造を維持し、透明な`<button>`は`<article>`直下で`<img>`・情報用`<div>`と兄弟要素になり、情報用`<div>`内の見出しや説明を子要素に含まないこと。`article`の役割と見出しナビゲーション、カード全体の`:focus-visible`輪郭、Tab停止1回、画面スクロール、カード上の文字ドラッグ選択不可という制約を確認する。Playwrightの子要素に対する`.click()`がボタンに遮られる場合は、`force: true`で回避せず、ユーザーの実操作に対応するボタン・座標起点の確認を行う。
- **操作案内の状態遷移**：検索前は非表示、1件以上の成功で件数表示直下に表示、成功→再検索中→0件では再検索開始とともに消えたまま、成功→エラーでは非表示、エラー→成功では再表示、部分成功で1件以上なら表示されること。件数表示とは別のDOM要素で、翻訳キー`select_movie_hint`を介して表示し、既存の検索結果件数・エラー通知の文面を変更しない。
- **操作可能性**：PCでの控えめなホバー反応とキーボードの明瞭なフォーカス枠、モバイルでホバーに依存しない案内を確認する。
- 参照専用のTMDB ID・原題・公開日・ポスター・複数ジャンルと、編集可能なタイトル・概要**全文**の区別。
- 編集内容の保存前保持、×・キャンセル・Escape終了時の破棄、別作品のモーダルへの状態混入なし。
- 確定順の12カテゴリー、初期未選択、「未定義」不在、TMDBジャンルによる自動選択なし。
- ジャンル0件・欠損／不正値、概要欠損・長文、ポスター欠損・画像失敗の各表示。
- 非信頼文字列・HTML特殊文字を安全なテキストとして扱い、危険な要素の生成とJavaScript実行が起きないこと。
- フォーカストラップ、背景操作抑止、起動元へのフォーカス復帰、Escape操作、ラベル・ARIAの整合。ネイティブ`<dialog>`の`showModal()`によるモーダル化が成立し、外部の背景操作・Tabでの外部到達ができないこと。
- モーダル外の背景クリックでは閉じず、×・キャンセル・Escapeでのみ閉じること。閉じた後、編集内容とカテゴリー選択が破棄され、適切な要素へフォーカスが戻ること。
- カード全体の操作対象は映画ごとに適切なアクセシブルな名前を持ち、フォーカスが視覚的に分かり、キーボード操作・読み上げで対象作品を区別できること。
- 既存の検索カード表示13シナリオを含む回帰確認（特にジャンル・概要100書記素省略・XSS・元Axios応答の不変性）。
- 登録ボタンの`disabled`属性、近傍の未実装案内文、送信なし、DB書き込みやダミー成功処理なし。
- **フォーム分離**：モーダルが検索フォーム外に配置されていること、入力欄の`form`関連付けがないこと、×・キャンセルが非送信ボタンであること、タイトル入力欄でEnterを押しても検索・登録の通信や意図しないクローズが発生しないこと。
- カテゴリー選択肢の値はDB IDを仮装した数値ではなく、UI用文字列であること。後続Issue 228の正式ID連携と混同しないこと。
- PC・モバイルのモーダル内スクロール、編集、横はみ出しを確認する。

**品質ゲート**

- 関連するPHPUnit・Playwright、Laravel Pint、PHPStan / Larastan Level 10、Vite build、TypeScript型チェック、Git差分形式チェック、必要なGitHub Actionsを確認する。実行していないものをPASSと記載しない。
- E2Eは[Playwright運用ガイド](PLAYWRIGHT_TESTING.md)に従って専用DB接続先とリセット前ガードを確認し、実TMDB APIを使用しない。
- 支援技術による評価・モバイル実機確認は自動テストと区別して実施・記録する。

### 16.10 実装前レビューの論点・参照資料（設計時の記録）

- **採用方針**：既存共通モーダルの改修を回避し、単一のネイティブ`<dialog>`＋`showModal()`を使用する。背景クリックでは閉じず、Escape・×・キャンセルでは閉じる。カードの起動は`<article>`の子に置いた**独立した全面透明`<button>`**で行い、`<h2>`等をボタンの内部に入れない。背景抑止・カードの操作性・既存画面への影響を実装テストで確認する。
- 検索カードのDOM生成・翻訳文言・Tailwindの`@source`と既存E2E fixtureへの影響。
- タイトル・概要全文の編集値はモーダル専用の状態へコピーして管理し、検索元データを書き換えない。固定カテゴリー名は既存翻訳ファイルに集約し、JSへは翻訳済み文字列を渡す。
- Issue 228とのインターフェース：編集済みタイトル・概要、TMDB ID等の参照情報、カテゴリー**選択状態**の引き継ぎ方法。実際の`category_id`の採番や保存形式はIssue 228で決定する。
- 12カテゴリーの固定表示順・「未定義」非表示と、後続のSeeder／DB連携との矛盾の有無。
- `docs/FEATURES.md`、`docs/SCREEN_TRANSITIONS.md`、`docs/DATABASE.md`との整合確認。

本節はIssue 230で採用した**実装前の設計・レビュー論点の履歴**である。これらの記述だけを根拠に実装済み・テスト済みと判断しない。実際の作業ブランチでの進捗・実行結果と未確認事項は第16.11節に記録する。独立レビューや画面テストで不適合が判明した場合は、影響範囲を限定して設計を改訂する。

**関連一次資料**

- [W3C WAI-ARIA APG: Dialog (Modal) Pattern](https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/)
- [WHATWG HTML Standard: dialog・showModal()](https://html.spec.whatwg.org/multipage/interactive-elements.html#dom-dialog-showmodal)
- [MDN: `<dialog>` element](https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/dialog)
- [WHATWG HTML Standard: button](https://html.spec.whatwg.org/multipage/form-elements.html#the-button-element)
- [W3C WAI-ARIA APG: Hiding Semantics with presentation](https://www.w3.org/WAI/ARIA/apg/practices/hiding-semantics/)
- [WHATWG HTML Standard: select](https://html.spec.whatwg.org/multipage/form-elements.html#the-select-element)
- [Laravel 13 Database Seeding](https://laravel.com/docs/13.x/seeding)
- [Laravel 13 Validation](https://laravel.com/docs/13.x/validation)
- [Issue 230](https://github.com/honda-dev-jp/review-app-laravel/issues/230)、[Issue 228](https://github.com/honda-dev-jp/review-app-laravel/issues/228)

### 16.11 Issue 230の作業ブランチでの実装・ローカル検証結果（2026-11-01）

本節は第16.1〜16.10節の**実装前に決めた仕様**とは別に、プロマネによる実行ログおよびClaude Code・Codexの作業報告で確認できた**ローカル作業ツリー上の状況**を記録する。GitHub上のPR・CIの成功や、`develop`への統合を意味しない。

**実装・確認された範囲**

- 作業ブランチは`feat/230-tmdb-registration-modal`（確認時のHEAD：`42fc77ebbb22dd4807c0a12f2f3cb3d61c20cd44`）。TMDB検索カード全体を操作する透明なネイティブボタンを既存の`<article>`直下へ追加し、単一の`<dialog>`を`showModal()`で開く。カードのEnter・Space・クリック、およびタッチ有効なChromiumでのタップをE2Eで確認した。
- モーダルにはTMDB ID・ポスター・ジャンル・原題・公開日を参照情報として表示し、タイトルと概要全文を編集可能とした。アプリ独自カテゴリーの固定12候補は初期未選択とし、「未定義」を含めない。×・キャンセル・Escapeで終了して編集内容を破棄し、フォーカスを起動元へ戻す。背景クリックでは閉じない。
- `resources/js/tmdb-search.js`にTab／Shift+Tabのフォーカス循環と閉鎖時の復帰処理を追加した。既存の共通モーダルコンポーネント、管理者ルート、検索JSON契約、TMDB通信処理は変更していないとレビューで報告された。
- `lang/ja/admin.php`に第16.7節の文言・12カテゴリーを追加し、Bladeから`Js::from()`経由で渡す。登録ボタンは`disabled`、未実装の補足文を表示し、登録送信・DB保存処理は実装していない。
- モーダルの参照情報4行に使うTailwind CSS v4クラスを`break-words`から`wrap-break-word`へ変更し、その後のViteビルドが成功した。
- **初期ポスター画像の読み込みは現状維持**：閉じた`<dialog>`内の画像には初期`src`として`/images/no-image.png`を設定している。E2Eで初期画像の`load`／`error`イベントを観測している。この設計には不要な画像読み込みが生じ得るが、Issue 230では`loading="lazy"`の追加も初期`src`の削除も行わない。イベント観測と実際のサーバーへの毎回のHTTP転送は同一ではなく、キャッシュ状況で変わり得る。

**ローカル品質ゲート（2026-11-01の報告結果）**

| 確認項目 | 結果 | 備考 |
|---|---|---|
| Laravel Pint `--test` | **120ファイルPASS** | プロマネ実行ログによる。今回のE2E補強後には再実行していない |
| PHPStan / Larastan Level 10 | **75/75、エラー0件** | プロマネ実行ログによる。対象FeatureテストのXPath戻り値処理も修正済み |
| PHPUnit `sail test` | **529件PASS／2,688 assertions** | プロマネ実行ログによる。今回のE2E補強後には再実行していない |
| Vite build | **PASS** | Tailwindクラス名変更後、およびCodexのE2E補強作業時に成功報告 |
| TypeScript E2E型チェック | **PASS** | `npm run typecheck:e2e`の成功報告 |
| Playwright対象2 spec | **26 passed、終了コード0** | setup 1件＋TMDB検索13件＋登録モーダル12件 |
| Playwright全E2E | **37 passed、終了コード0** | 旧34件からモーダルの3件を追加。Chromium、setup 1件を含む |
| `git diff --check` | **PASS** | CodexのE2E補強後の報告 |

**レビュー指摘と検証の追加**

- Claude CodeとCodexの独立レビューで、操作案内の状態遷移とカード全面操作のE2E検証不足が指摘された。Codexが`e2e/tmdb-search.spec.ts`と新規`e2e/tmdb-registration-modal.spec.ts`の**2ファイルのみ**を修正し、操作案内の表示・非表示、画像・タイトル・概要・余白への実座標クリック、1カード1Tab停止、フォーカス枠、タッチ有効コンテキスト、ダイアログ外の座標クリックを補強した。
- フォーカス枠の検証はChromiumの計算済みスタイルによる。実機Safari／Android、スクリーンリーダー等の支援技術によるアクセシビリティ適合を保証するものではない。

**未実装・未確認事項**

- **フロント入力検証**：編集用タイトルの必須・最大長、概要の正式な許容値、カテゴリー未選択エラー、および`aria-invalid`・`aria-describedby`を使った項目別エラー表示は未実装。入力契約を確定してから後続で対応する。フロント検証の独立Issue化は検討段階であり、Issue番号はまだ存在しないものとして扱う。
- **DB関連・登録**：`CategorySeeder`への正式12件の登録、`tmdb_id`用migration、既存データ保全の回帰テスト、登録API、サーバー側バリデーション、`items`保存、重複防止は未実装である。現行の所管はIssue 228であり、DB詳細設計・DB変更と回帰テスト・登録処理へのIssue分割を検討しているが、起票前に責務を確定扱いしない。
- **PR／CI**：本書更新時点でIssue 230のPR作成・GitHub Actions実行結果・`develop`へのマージは未確認。ローカルテスト成功だけでCI成功を宣言しない。
- **関連文書**：`docs/FEATURES.md`、`docs/SCREEN_TRANSITIONS.md`、`docs/DATABASE.md`は、各作業ツリーの最新版で「Issue 230対応予定」等の進捗表記と後続Issueの責務が一致するかをPR前に再確認する。本書だけの修正で他の文書も更新済みとは扱わない。
