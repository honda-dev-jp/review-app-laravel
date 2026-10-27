# TMDB映画検索 技術設計（Issue 212）

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

## 1. 目的・位置づけ

映画レビューアプリ Laravel移植版のMVP2において、管理者が映画タイトルを検索し、TMDBの映画情報とポスターを同一画面に非同期表示するための技術設計を定める。

- 対象Issue: [Issue 212「TMDB映画検索結果をポスター付きで非同期表示する」](https://github.com/honda-dev-jp/review-app-laravel/issues/212)
- 状態: **検索・表示機能は作業ブランチ上に実装済み（最終品質ゲート確認中）**。本書の「確定事項」はIssue 212で合意した仕様を示し、第12章は実装前の合意履歴を残す。作品登録・重複登録防止・候補拡張・ページネーションはIssue 212の対象外であり、Issue 227・228など後続対応とする。
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
- Configurationに基づく公開ポスター画像URLの生成とNo Image表示
- 最大5件表示、正常・0件・入力エラー・外部API失敗時の処理
- 実APIへ接続しない自動テストと、ローカル手動動作確認

### 対象外

- 作品・ジャンル・ポスター・ConfigurationのDB保存、migration、永続キャッシュ
- `tmdb_id` のDB仕様、作品登録、重複登録防止、Movie Details取得、ページネーションUI
- TMDBジャンルの取得・表示とアプリ独自カテゴリーマスタ・セレクトボックスの整備（後続の作品登録Issueで検討）
- TMDBの利用条件、attribution、notice、brandingおよび長期保存条件の確定（別途Issue 98）
- TMDB用の外部PHP wrapper、Sanctum、TMDBユーザー認証

## 3. 画面とルート（確定）

| 用途 | HTTP | パス | ルート名 | Laravel側の担当 |
|---|---|---|---|---|
| 検索画面 | GET | `/admin/tmdb/search` | `admin.tmdb.search` | `Route::view` によるBlade表示 |
| 検索結果 | GET | `/admin/tmdb/search/results` | `admin.tmdb.search.results` | JSONを返す専用Controller |

- `routes/web.php` の既存管理者用ルートグループへ配置する。
- 両ルートは `auth` と `can:access-admin-panel` により保護し、`verified` は要求しない。
- 検索結果取得は読み取り専用のGET。作品登録用ルートは後続で決める。

## 4. 処理フロー・責務（確定）

```text
管理者ブラウザの検索画面（Blade）
  └─ Axios GET /admin/tmdb/search/results?query=...
      └─ TmdbSearchController（入力検証・レスポンス制御）
          └─ TmdbMovieService（TMDB通信・結果の変換・最大5件へ絞り込み）
              ├─ GET https://api.themoviedb.org/3/search/movie
              └─ 必要な場合のみ GET https://api.themoviedb.org/3/configuration
                  └─ Configuration + poster_path → poster_url
      └─ JSON → Axios → 検索画面にDOMで安全に描画
                         └─ 画像はブラウザからTMDB画像CDNへ取得
```

| 層 | 責務 |
|---|---|
| Route | `Route::view` とControllerへのルーティング、既存管理者middleware適用 |
| `App\Http\Controllers\Admin\TmdbSearchController` | `Request`の検証、Serviceの呼び出し、JSON/HTTPレスポンスへの変換 |
| `App\Services\TmdbMovieService` | Bearer認証付きTMDB通信、HTTP応答確認、最大5件への絞り込み、必要フィールドの抽出、Configuration取得・URL生成 |
| Blade / JavaScript | 画面・検索フォーム、Axios送信、ロード／結果／エラー表示、安全なDOM更新、No Image表示 |

- 現時点で新たなForm Requestクラスは導入せず、ControllerでLaravel標準の `$request->validate()` を使用する。
- 既存Axios（`resources/js/bootstrap.js` の `window.axios`）を使用し、専用HTTP wrapperは導入しない。
- 上記のクラス名・配置は実装時に採用する設計案。責務や設計から変更する場合は人間が確認する。

## 5. 設定・認証（確定）

- TMDB API v3の**API Read Access TokenをBearer方式**で使用する。
- 環境変数名: `TMDB_READ_ACCESS_TOKEN`。`.env.example` には `TMDB_READ_ACCESS_TOKEN=` と**空値**で記載する。
- `config/services.php` に `tmdb.read_access_token` を追加し、Serviceでは `config('services.tmdb.read_access_token')` から取得する。
- アプリケーションコードから `env()` を直接使用しない。実トークンは人間がローカルの非追跡設定に登録し、AIは `.env` を読み取らない。
- Tokenをブラウザ、JavaScript、HTML、JSONレスポンス、README、docs、Issue、PR、スクリーンショット、ログへ露出させない。
- ポスター画像の公開URLのブラウザ表示にBearer Tokenを使用しない。

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

- `Http::timeout(5)` を**TMDBへの各HTTPリクエスト**に適用する。自動retryは行わない。
- この5秒はアプリのMVP用設計値であり、TMDB公式推奨値ではない。2つのAPIを直列に呼ぶ場合、検索操作全体は5秒を超える可能性がある。
- **ブラウザからLaravelへの検索GETだけ**、Axiosのリクエストオプションに `timeout: 20_000`（20秒）を指定する。TMDB向けHTTP Clientの5秒とは別の上限であり、Laravel処理・2回分のTMDB通信・ネットワーク遅延のために余裕を設けた**本アプリ独自の設定値**とする。既存のAxios全体の既定値は変更しない。
- Axios timeoutが発生したときは、翻訳キー `admin.tmdb.search.errors.request_timeout` の文言を表示し、`finally` 相当の処理で入力欄・検索ボタンを再有効化する。ブラウザでのタイムアウトはLaravelサーバー内の処理停止まで保証するものではなく、今回は読み取り専用GETのため終了後の再検索を許可する。

## 7. Movie Searchレスポンスとアプリ用JSON（確定）

### 成功JSONの契約（確定）

- TMDBのraw responseをそのまま返さず、Serviceで以下の検証・正規化を行う。
- 成功時はHTTP `200` とし、常に `results` 配列と `summary` オブジェクトを返す。ページ情報は付けない。
- `summary.checked` はTMDB検索結果の**先頭最大5要素の検証対象数**（0〜5の整数）。TMDB全体の検索ヒット数ではない。
- `summary.displayed` は検証後の正常な表示件数（0〜`checked`の整数）で、返す `results` 配列の要素数と一致する。
- エラーJSONに `summary` は追加しない（第9章参照）。
- 作品ごとの既存フィールドは維持し、すべての表示作品に以下のキーを持たせる。TMDBの他のフィールドは公開しない。

| キー | 内容 | 出力型 |
|---|---|---|
| `id` | TMDB映画ID | 整数 |
| `title` | タイトル（日本語優先、補完後） | 文字列 |
| `original_title` | 原題 | 文字列または `null` |
| `release_date` | 公開日 | 文字列または `null` |
| `overview` | 概要 | 文字列または `null` |
| `poster_url` | Configurationから組み立てたポスター公開URL | URL文字列または `null` |

**成功例（すべて架空のデータ、1件を検証して1件表示）**

```json
{
  "results": [
    {
      "id": 12345,
      "title": "サンプル映画",
      "original_title": "Sample Movie",
      "release_date": "2026-01-01",
      "overview": "サンプル用の概要です。",
      "poster_url": null
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

Configurationの検証・フォールバック条件は上記のとおり確定とする。TMDBの公式画像配信ドメインやサイズ仕様が将来変わった場合は、無条件に許可範囲を拡大せず設計を再確認する。

## 9. HTTP応答・障害時の扱い（確定）

### 確定事項

- 全件正常・部分成功・正常な検索結果0件はHTTP `200` とする。検証対象が1件以上で全件不正の場合と、JSON全体または `results` の不正はHTTP `502` とする（第7章参照）。
- Laravel標準の入力バリデーション失敗はJSON要求にHTTP `422` と `message` / `errors` を含む標準応答を利用する。
- Laravel側の未認証と管理者権限不足は、既存の `auth` / `can` に従う（JSON要求での401/403の実動作をテストする）。
- TMDBの認証失敗（上流401）をLaravelの未ログイン401と混同しない。
- TMDBの429、その他4xx/5xx、connection failure、timeout、トークン未設定を安全に扱う。生のレスポンス・例外・認証情報はクライアントへ公開しない。
- Laravel HTTP Clientでは4xx/5xxは標準では例外にならないため、レスポンスの状態を明示判定する。接続失敗・タイムアウトによる `ConnectionException` とは分ける。
- Configurationのみ失敗した場合は、映画検索結果を200で返してNo Image表示とする。

### 確定事項：HTTPステータスとエラーJSON

Issue 212では、MVP向けに以下の応答契約を採用する。

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
| TMDB認証失敗・その他4xx/5xx（429を除く） | 502 | `{"message":"映画情報を取得できませんでした。"}` |
| TMDB 429 | 503 | `{"message":"しばらくしてから再度お試しください。"}` |
| TMDB接続失敗・タイムアウト | 503 | `{"message":"映画情報を取得できませんでした。"}` |
| Laravel側のTMDB Token設定不足 | 503 | `{"message":"検索サービスを利用できません。"}` |

- エラーJSONは `message` を基本とし、独自の `error_code` や `summary` は追加しない。
- 未認証・認可違反・入力不正はLaravel標準の応答を利用する。
- TMDB側の401をLaravel側の未認証401として返さない。
- TMDB 429に対して自動retryは行わない。
- Configuration取得だけが失敗した場合は、映画情報を維持してHTTP 200で返し、ポスターをNo Image表示にする。
### 管理者向けメッセージの一元管理（確定）

- 管理者向けTMDB検索のエラー文言・画面案内文言は、新設する `lang/ja/admin.php` のPHP配列で一元管理する。既存の `lang/ja.json`・`lang/ja/validation.php` は今回の専用メッセージ追加を目的として書き換えない。`config/app.php` の既存の日本語localeを使用する。
- メッセージキーはHTTPステータス番号に依存させず、`admin.tmdb.search.errors.*` と `admin.tmdb.search.ui.*` の意味別キーを用いる。文言はController・Service・Blade・JavaScriptへ直接ハードコードせず、PHPではLaravel標準の `__()` によって取得する。
- サーバーで生成する固定エラーJSONの `message` は上記の翻訳キーを使い、第9章の確定済みレスポンス文言・HTTPステータス・`summary` 非付与を維持する。上流TMDBの生のエラーメッセージや例外詳細を利用者へ返さない。
- **内部診断用例外メッセージの扱い：** Service内で生成する例外のメッセージは、利用者向けの表示・案内文言とは区別し、内部診断専用である場合に限り翻訳キーによる一元管理の対象外とする。この例外は利用者向けの文言をServiceへ直書きすることを許可するものではない。内部診断メッセージにはTMDBの生レスポンス、アクセストークン、検索語その他の機密情報・利用者入力を含めない。Controllerは内部例外のメッセージをHTTPレスポンスへ転用せず、上記の翻訳キーに基づく固定エラーJSONへ変換する。なお、Laravel HTTPクライアント由来の例外は上流レスポンス情報を保持する可能性があるため、例外オブジェクトやメッセージをログへ無条件に記録せず、記録項目を安全な情報に限定する。
- `query` の422では、Controllerの `$request->validate()` のカスタムメッセージに `__('admin.tmdb.search.errors.invalid_query')` を指定し、Laravel標準の422 JSON（`message` / `errors`）を維持する。`required`・`string`・`max` のいずれの失敗でも検索入力用の統一文言とする。共通の `validation.php` をTMDB専用文言で上書きしない。
- Laravel標準の401・403応答や、管理画面URLへ直接アクセスした際の403ページは変更しない。Axiosで検索結果取得APIから401・403を受けた場合のみ、管理者検索画面内に対応する翻訳済みエラー文言を表示する。
- JavaScriptはPHPの言語ファイルを直接読まない。Blade内で必要な翻訳済みメッセージだけを配列にまとめ、`Illuminate\Support\Js::from($messages)` 等のLaravel標準機能で安全に渡す。メッセージの原文をJavaScriptに再定義しない。
- `502`・`503` のレスポンス本文を表示する場合は、サーバーが返す**確定済みの固定メッセージのみ**を許可し、それ以外の本文（HTMLエラーページ・proxy応答・予期せぬ文字列など）は安全な汎用エラー文言にフォールバックする。503はレート制限・トークン未設定・通信失敗を区別するため、ステータス番号だけで文言を推測しない。表示は `textContent` 等を使用する。

**新設する言語ファイルのキー案（文言と構成を確定）**

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

## 10. フロントエンド（確定事項と確認事項）

- 管理者ナビゲーションの「TMDB検索」から検索画面に移動できるようにする（PC・モバイル双方）。既存のモック `href: null` を `route('admin.tmdb.search')` へ切り替え、`active` は `request()->routeIs('admin.tmdb.search')` により検索画面ルートで判定する。検索結果の `admin.tmdb.search.results` はJSON応答のみで管理ナビゲーションを描画しない。
- 検索フォーム送信時はページを再読み込みせず、AxiosからLaravel JSON endpointへGETを送る。**検索GETのみ** `timeout: 20_000` を指定し、タイムアウトしても再検索可能に戻る。
- 初期表示／検索中／全件正常／部分成功／0件／入力エラー／API失敗を画面上で区別できるようにする。検索中・成功・0件・部分成功などの**状態通知**は `role="status"`（または同等のライブリージョン）で読み上げられるようにし、処理中の結果領域へ `aria-busy` を適切に設定する。
- 新しい検索の開始時は前回の検索結果とエラー表示をクリアし、検索失敗後は古い結果を再表示しない。検索語の入力値は保持し、再検索可能にする。
- エラーはブラウザのalertダイアログではなく画面内へ表示し、`role="alert"` 等で支援技術へ通知する。422は検索欄の近くに表示し、検索欄の `label` と `aria-describedby` によるエラー関連付けを行う。401では既存のログインルートへの再ログイン用リンク（`ui.login_link`）を表示し、403はメッセージのみ表示して権限変更や強制リダイレクトは行わない。
- 422は検証対象の `errors.query` の最初のメッセージ（ない場合は `invalid_query`）を使用する。401・403はBladeから渡した `unauthenticated`・`forbidden`、502・503は第9章の許可された固定メッセージ、HTTP応答を受信できないネットワークエラーは `network_failed` を表示する。**Axios検索GETの20秒timeout**（Axiosの `ECONNABORTED` や `ETIMEDOUT` 等、HTTP応答なしのタイムアウトとして識別できるもの）では `request_timeout` を使用する。安全な文言に該当しない場合は `fetch_failed` へフォールバックする。
- 全件正常時は通常の検索結果を表示し、正常な0件は「該当作品なし」とする。全件不正の502を0件表示にしない。
- 部分成功時は `summary.checked` と `summary.displayed` から件数と注意文を表示する。例: 「5件中4件を表示しました。1件はデータ形式が不正だったため除外しました。」（文言は `admin.tmdb.search.ui.partial_success` で一元管理）除外件数は `checked - displayed` とし、TMDB全体の検索ヒット数として表示しない。
- Configuration失敗やポスター欠損は作品の除外に数えず、No Imageで表示する。
- TMDB由来の文字列を `textContent` 等で描画し、信頼できない値を `innerHTML` に渡さない。
- `poster_url` が `null` の場合および画像読み込み失敗時は `/images/no-image.png` を表示し、結果全体を壊さない。ポスターには作品名を含む `ui.poster_alt`、No Imageには `ui.no_poster_alt` の翻訳済み文言をaltへ設定する。
- 検索フォームの `submit` イベントを一元的に処理し、検索開始時にJavaScriptの処理中フラグを即時に立てる。フォーム送信によるページ遷移は抑止し、検索中は検索ボタンと入力欄を無効化して `admin.tmdb.search.ui.searching` の翻訳済み文言を表示する。
- 処理中フラグが立っている間は、クリック・Enterキー・連続 `submit` のいずれからも追加のAxiosリクエストを送らない。UIの無効化だけに依存せず、イベントハンドラの入口でもフラグを確認する。
- 成功・検索結果0件・HTTP 422・401/403・上流APIエラー・通信失敗・**Axios timeout**など、処理の終了時は成功・失敗にかかわらず `finally` 相当の処理でフラグを解除し、入力欄と検索ボタンを再び有効化する。その後の再検索は許可する。無効化に伴うキーボードフォーカス喪失を確認し、必要なら検索入力欄へ復帰できるようにする。ただし、利用者が別の操作対象へ移ったときはフォーカスを不意に奪わない。
- 同じ画面では検索リクエストを同時に複数実行しないため、MVPではリクエストのキャンセルや結果の通し番号による先後逆転制御を導入しない。作品登録を伴わない読み取り専用GETであり、PRGやワンタイムCSRFトークンによる二重登録防止は対象外とする。

## 11. テスト・品質確認

### Feature / Serviceテスト（外部通信なし）

- `Http::fake()` でMovie Search・Configurationをスタブし、`Http::preventStrayRequests()` で想定外の外部HTTP通信を阻止する。
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
- 実装時に新設する `lang/ja/admin.php` の専用キーがすべて定義され、Serviceの `unknown_title`、Controllerの固定エラーJSON（502・503）、検索入力の422バリデーションが翻訳キーを参照して、既存のHTTPステータス／JSON構造を維持することを確認する。新規画面のラベル・代替文言・ログインリンク・ポスターalt・Axios timeout文言もキーから取得し、JavaScriptに日本語を重複定義しない。共通の `validation.php` や既存認証／認可応答を変更しない。

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

### 既存品質ゲート

`git diff --check`、Laravel Pint、PHPStan / Larastan Level 10、PHPUnit、Vite build、必要に応じてPlaywrightを確認する。実行していない項目を成功したと記載しない。

## 12. 実装前の未決事項と合意履歴

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
| TMDBジャンルとアプリ独自カテゴリー | Issue 212対象外・後続登録Issueで検討 |

## 13. 公式一次資料

- [TMDB Application Authentication](https://developer.themoviedb.org/docs/authentication-application)
- [TMDB Search - Movies](https://developer.themoviedb.org/reference/search-movie)
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

2026-10-20にIssue 212、TMDB Movie Search・Search & Query For Details・Image Basics、Laravel 13 HTTP Client・Validation・Requests、PHP 8.4 `mb_trim()` を再確認した。先頭5件の検証・部分成功・`summary` は本アプリで合意した応答契約であり、TMDB公式の仕様とは区別する。Laravel HTTP ClientのJSON取得やHTTP成功判定だけに依存せず、第7章の形状・作品検証を行う。

Codex・Claude Codeの実装前再レビュー指摘を受け、PHP公式の `json_decode`・PCREアンカー・`mb_trim`、Axiosのtimeout、Playwrightのネットワークスタブ方法を確認した。20秒timeoutと文字列のUnicode空白除去はプロジェクトで合意したアプリ独自の設計値・正規化方針であり、外部サービスの推奨値とは区別する。

各APIレスポンスの実データや実トークンは本書に保存しない。テストには合成データだけを使用する。
