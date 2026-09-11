# Playwrightブラウザテスト運用ガイド

## 目次

1. [このドキュメントの目的と適用範囲](#1-このドキュメントの目的と適用範囲)
2. [導入目的とテスト責務境界](#2-導入目的とテスト責務境界)
3. [構成](#3-構成)
4. [ローカルE2E環境](#4-ローカルe2e環境)
5. [初回セットアップ](#5-初回セットアップ)
6. [ローカル実行](#6-ローカル実行)
7. [`e2e:reset`](#7-e2ereset)
8. [fixture](#8-fixture)
9. [Locator ARIA keyboard focus](#9-locator-aria-keyboard-focus)
10. [CI](#10-ci)
11. [Artifactとセキュリティ](#11-artifactとセキュリティ)
12. [Git管理除外](#12-git管理除外)
13. [手動確認として残す範囲](#13-手動確認として残す範囲)
14. [トラブルシューティング](#14-トラブルシューティング)
15. [関連ドキュメントと公式一次情報](#15-関連ドキュメントと公式一次情報)

---

## 1. このドキュメントの目的と適用範囲

このドキュメントは、このプロジェクトにおけるPlaywrightブラウザテストの構成、責務、ローカル実行、E2E専用データベース、CI、成果物および安全上の注意事項をまとめる正本です。

README、開発フロー、コマンド集、セキュリティ方針には、それぞれの文書に必要な要点とこの文書への入口だけを置きます。Playwright固有の詳細はこの文書で管理します。

対象は現在の`playwright.config.ts`、`e2e/`、`compose.yaml`、E2E DB関連のLaravel実装、`.github/workflows/ci.yml`に実装されている運用です。Laravel Sail自体の初期構築、PHPUnit全般、GitHub運用全般は各専用文書を参照してください。

---

## 2. 導入目的とテスト責務境界

Playwrightは、HTTPレスポンスやHTML断片だけでは保証できない、実ブラウザ上のJavaScript、ユーザー操作、画面遷移、キーボード操作、フォーカス制御を確認するために使用します。

| 確認手段 | 主な責務 |
|---|---|
| PHPUnit Unit | Laravelをbootせず、単一メソッドなど小さく独立したロジックを確認する |
| PHPUnit Feature | Laravelをbootし、HTTP、middleware、認証・認可、validation、DB更新、transaction、Policy、cascade、rating cache、HTML・ARIA構造を確認する |
| Playwright E2E | ChromiumでJavaScriptを実行し、ユーザー操作、Locator解決、画面遷移、モーダル、キーボード、フォーカス、ページネーションを確認する |
| 手動確認 | 視覚的な崩れ、responsive表示、スクリーンリーダー等の支援技術、包括的なアクセシビリティ監査を確認する |

同じ契約をすべての層で重複して網羅しません。現行E2Eでは、削除submit、validation、Policy、cascade、rating cacheなどのサーバー側契約を再確認せず、Featureテストの保証を維持します。E2Eはブラウザでなければ有効に確認できない挙動へ集中します。

---

## 3. 構成

主なファイル構成は次のとおりです。

```text
playwright.config.ts
e2e/
├── tsconfig.json
├── global.setup.ts
├── smoke.spec.ts
├── account-deletion-modal.spec.ts
├── review-deletion-modal.spec.ts
├── pagination.spec.ts
└── support/
    └── login.ts
```

- Playwright TestをTypeScriptで使用する
- PHPUnitの`tests/`とPlaywrightの`e2e/`を分離する
- `e2e/tsconfig.json`はPlaywright関連のTypeScriptだけを`strict`かつ`noEmit`で型検査する
- `setup` projectがE2E DBをresetし、その成功後に`chromium` projectを実行する
- 対象browserはChromiumのみとする
- 認証が必要なtestはログイン画面を操作する共通helperを使用する
- `storageState`は使用せず、各testの独立したBrowserContextでUIログインする

Codegenは現時点の標準運用には採用していません。将来補助的に使用する場合も、生成されたLocatorやassertionをそのまま採用せず、role、label、accessible nameなどユーザー視点のLocatorへ整え、credentialや認証状態を生成コードや保存ファイルへ残さないでください。

---

## 4. ローカルE2E環境

ローカルでは、通常開発用の`laravel.test`とは別に、Docker Composeの`laravel.e2e` serviceを使用します。

| 項目 | 現行値 |
|---|---|
| service | `laravel.e2e` |
| URL | `http://localhost:83` |
| container port | `80` |
| Laravel環境 | `APP_ENV=e2e` |
| DB connection | `e2e_mysql` |
| DB | `e2e_testing` |
| session cookie | `review_app_e2e_session` |

`laravel.e2e`では、通常connection側のDB host・username・passwordを接続不能な値へ固定し、E2E専用の`E2E_DB_*`だけを`e2e_mysql` connectionで使用します。設定不足やconnectionの取り違えから通常開発DBへ接続しないためのfail-closed設計です。

session cookieは、通常開発環境とE2E環境の認証状態が混線しないように分離しています。

E2E環境では通常開発用の`public/hot`ではなく`storage/vite.e2e.hot`をVite hot fileとして参照します。正式なE2EではVite dev serverに依存せず、`npm run build`で生成したassetを使用します。

通常環境とE2E環境はrepositoryをbind mountで共有するため、E2E運用では`config:cache`を使用せず、別環境で生成したconfig cacheが存在する状態で実行しないでください。Laravelのconfig cacheは主にproduction deployment向けであり、ローカルで設定を切り替える運用とは分離します。

---

## 5. 初回セットアップ

### 5.1 前提

既存のLaravel Sail環境とMySQL serviceが動作することを前提とします。Laravel/Sail全体の初期構築やimage buildはこの文書へ重複させません。

Playwrightの依存関係は`package-lock.json`を正本として導入し、Chromiumが未導入の環境ではホスト側で次を実行します。

```bash
npm ci
npx playwright install chromium
```

### 5.2 ローカル設定

`.env.example`の`E2E_DB_*`を参考に、Git管理外のローカル設定へ次の項目を設定します。

```text
E2E_DB_HOST
E2E_DB_PORT
E2E_DB_DATABASE
E2E_DB_USERNAME
E2E_DB_PASSWORD
```

これらが未設定または空の場合、Composeの必須interpolationによりCompose全体の解析が失敗し、E2Eを直接使用しない通常のSail・Compose commandも実行できません。

`.env.example`のpasswordは実credentialではなく、意図的に接続できないplaceholderです。実credentialをdocs、ソースコード、Issue、Pull Request、Git、shell history、ログへ記録しないでください。

### 5.3 E2E DBと専用MySQL account

管理権限を持つ人間が初回のみ、`e2e_testing`とE2E専用accountを作成します。次は構造を示すテンプレートであり、実passwordを文書へ記入して使用するものではありません。

```sql
CREATE DATABASE `e2e_testing`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'review_app_e2e'@'<allowed-host>'
    IDENTIFIED BY '<set-secure-password-outside-documentation>';

GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP,
      REFERENCES, INDEX, ALTER
ON `e2e_testing`.*
TO 'review_app_e2e'@'<allowed-host>';
```

`<allowed-host>`には、Docker Composeからの接続要件を満たす必要最小のhost範囲を選びます。特定環境の`%`を一般的な推奨値として固定しません。passwordをSQL clientの履歴やserver logへ残さない入力方法も確認してください。

現在のローカル環境では`review_app_e2e@%`で接続と権限分離を確認済みですが、これは現在環境の実測値です。新しい環境では`%`をそのまま採用せず、接続要件に応じてhost範囲を判断します。

`ALL PRIVILEGES`や`ON *.*`は使用せず、`CREATE USER`、`GRANT OPTION`、`FILE`、`PROCESS`等の管理・global権限をE2E accountへ付与しません。

### 5.4 権限と拒否の確認

管理用accountから確認する場合は、host部を作成時の値に合わせます。

```sql
SHOW GRANTS FOR 'review_app_e2e'@'<allowed-host>';
```

E2E account自身で確認する場合は、次のいずれかを使用します。

```sql
SHOW GRANTS;
```

または、

```sql
SHOW GRANTS FOR CURRENT_USER;
```

確認事項は次のとおりです。

- data・schema操作権限が`e2e_testing.*`だけに付与されている
- `WITH GRANT OPTION`や意図しないglobal権限がない
- E2E credentialで`USE e2e_testing`が成功する
- 同じcredentialで`USE laravel`と`USE testing`がaccess deniedになる

`SHOW GRANTS`に`USAGE ON *.*`が表示されても、MySQLにおける`USAGE`は権限なしを示すため、それ自体が追加のglobal権限を意味するものではありません。

---

## 6. ローカル実行

### 6.1 通常実行

初回セットアップ済みのSail環境で、E2E専用serviceを起動します。依存するMySQLもComposeにより起動されます。

```bash
docker compose up -d laravel.e2e
```

Node.js、npm、Playwrightはホスト側から実行します。build、型検査、browser testの順に確認します。

```bash
npm run build
npm run typecheck:e2e
npx playwright test
```

`npx playwright test`は最初に`setup` projectを実行し、ローカルでは内部で次のresetを呼び出します。

```bash
docker compose exec -T laravel.e2e php artisan e2e:reset
```

通常手順で手動resetを先に重ねて実行する必要はありません。Playwrightを実行すると`e2e_testing`がwipe、migrate、seedされ、既存のE2E DB内容は失われます。

### 6.2 対象を絞る実行

```bash
npx playwright test --project=chromium
npx playwright test e2e/account-deletion-modal.spec.ts
npx playwright test e2e/account-deletion-modal.spec.ts --headed
npx playwright test e2e/account-deletion-modal.spec.ts --debug
```

対象spec、`--headed`、`--debug`を指定した場合も、project dependencyである`setup`が先に動作し、E2E DBをresetします。

---

## 7. `e2e:reset`

`e2e:reset`はE2E専用DBを初期化する破壊的なArtisan commandです。外部引数で対象connection、DB、Seeder classを変更できないよう、コード側で固定しています。

reset対象connectionは、設計上`e2e_mysql`に固定しています。そのうえで、破壊処理の前に次の実行時ガードを順に確認します。

1. Laravelの実効環境が`e2e`
2. config上のdatabase名が`e2e_testing`
3. `SELECT DATABASE()`で取得した実接続先も`e2e_testing`

すべてを通過した場合だけ、固定した`e2e_mysql` connectionと`--force`を使い、次の順で実行します。

```text
db:wipe
  ↓ 成功時のみ
migrate
  ↓ 成功時のみ
db:seed --class=Database\Seeders\E2eSeeder
```

いずれかのガードが一致しない場合や、DB名を取得できない場合は破壊処理へ進みません。wipe、migration、seedの各段階でも、失敗時は後続処理を行わず異常終了します。

途中で失敗したDBは中間状態の可能性があります。その状態でbrowser testを続けず、原因を解消して`e2e:reset`を最初から成功させてからPlaywrightを再実行してください。

---

## 8. fixture

`E2eSeeder`は通常の`DatabaseSeeder`と分離され、実在データへ依存しない合成fixtureをFactory経由で作成します。

| model | 件数 |
|---|---:|
| users | 5 |
| categories | 1 |
| items | 11 |
| reviews | 11 |

Factoryはモデル1件の基本的な生成方法を担当し、`E2eSeeder`はPlaywrightのシナリオに必要なデータセット全体を組み立てます。Node.jsやTypeScriptからDBへ直接insertしません。

5 usersは、認証済みreviewer、メール未認証、reviewなしの認証済みuser、一般操作用の認証済みuser、管理者画面の認可済みE2E操作に使用するadminという状態を持ちます。adminを含むこれらのユーザーはE2E専用DBだけに生成する合成fixtureであり、本番の管理者作成機能や実在ユーザーではありません。fixture用の認証情報は実在credentialではありません。値を複数文書へ重複させず、実装上の`E2eSeeder`、`UserFactory`とログインhelperを一致させます。

作品名は`E2E Movie 01`から`E2E Movie 11`、review本文は`E2E Review 01`から`E2E Review 11`、ratingは5に固定します。11件は`paginate(10)`で2ページ目を発生させる最小構成です。

reviewの`created_at`は絶対時刻に固定していません。reset時の`now()`を基準に1分間隔の差を付け、`ReviewController::mine()`の`created_at DESC`による順序とページ境界を決定的にします。

`ReviewFactory`は`afterCreating`で`ItemRatingService::refresh()`を呼び出すため、Factoryでreviewを作成した場合も`items`のrating cacheを画面表示と整合させます。

---

## 9. Locator ARIA keyboard focus

LocatorはDOM構造やCSS classだけに依存せず、ユーザーが認識するrole、label、text、accessible nameを優先します。現行specでは主に`getByRole`、`getByLabel`、`getByText`を使用し、同種要素が複数ある場合は意味のある親要素へscopeします。

現行E2Eでは、次の実ブラウザ動作を確認します。

- dialogのrole、accessible name、`aria-modal="true"`の明示検証
- modalを開いた直後のinitial focus
- `Tab`と`Shift+Tab`によるmodal内のfocus循環
- `Escape`、cancel、close、背景clickによるclose
- close後の起動元へのfocus restoration
- 複数のreview modal間で状態とfocus復帰先が混線しないこと
- pagination linkのclickと遷移後の表示

これらは対象UIのブラウザ動作とアクセシブルな名前の契約を確認するものです。WCAG全体、色や視覚表示、スクリーンリーダーの読み上げ、支援技術との組み合わせを含む包括的なアクセシビリティ監査の代替ではありません。

本人review一覧のpaginationは、現行UIが出力するaccessible nameに合わせてLocatorを限定しています。このガイドではUI文言自体を変更せず、UI側の改善は別の変更として扱います。

---

## 10. CI

GitHub Actionsには`Playwright E2E`専用jobがあります。ローカルとは異なりDocker Composeを使用せず、GitHub-hosted runner上でLaravelを直接起動します。

| 項目 | CI構成 |
|---|---|
| runner | `ubuntu-24.04` |
| job timeout | 20分 |
| Node.js | workflowで固定した24.18.0 |
| Laravel URL | `http://127.0.0.1:8000` |
| ready probe | `http://127.0.0.1:8000/login` |
| Laravel環境 | `APP_ENV=e2e`、`APP_DEBUG=false` |
| session cookie | `review_app_e2e_ci_session` |
| DB | MySQL serviceの`e2e_testing` |
| connection | `e2e_mysql` |
| browser | Chromium |

CIのcredentialはworkflow内のE2E専用合成値であり、本番credentialや実在userを使用しません。`.env`は作成せず、workflowのenvironmentから設定します。通常の`DB_*`は接続不能な値にし、E2Eの正規接続を`E2E_DB_*`へ限定します。

E2E jobは次の順で実行します。

```text
composer install
  ↓
npm ci
  ↓
npm run build
  ↓
npm run typecheck:e2e
  ↓
npx playwright install --with-deps chromium
  ↓
npx playwright test --project=chromium
```

CIではPlaywrightの`webServer`が次のcommandでLaravelを起動し、ready probeを待ってから`setup` projectへ進みます。

```bash
php artisan serve --host=127.0.0.1 --port=8000 --no-reload
```

`setup` projectはCIでは`php artisan e2e:reset`を呼び出します。ローカルの`localhost:83`はDockerのport publish、CIの`127.0.0.1:8000`はrunner-native serverという構成差があります。

現行Playwright・CI設定は次のとおりです。

| 設定 | 値 |
|---|---|
| workers | 1（ローカル・CI共通） |
| retries | 0 |
| test timeout | 30秒 |
| webServer timeout | 60秒 |
| reporter | `github`と`list` |
| `forbidOnly` | CIで有効 |

全testが単一のE2E DBを共有するため、workersはローカル・CIともに1へ固定し、test間の相互干渉を避けます。

`python-quality-checks`、`quality-checks`、`Playwright E2E`の3 jobsは`needs`を設定せず並列に実行します。

---

## 11. Artifactとセキュリティ

Playwrightの一般機能と、このrepositoryで採用する成果物方針を区別します。現行方針は次のとおりです。

| 成果物 | ローカル | CI |
|---|---|---|
| screenshot | failure時のみ | failure時のみ |
| trace | failure時に保持 | off |
| video | off | off |
| storageState | 不使用 | 不使用 |
| HTML report | 標準運用として未設定 | 未設定 |

CI jobが失敗した場合だけ、次のPNG screenshotをGitHub Actions Artifactへuploadします。

| 項目 | 値 |
|---|---|
| Artifact名 | `e2e-failure-screenshots` |
| path | `test-results/**/*.png` |
| retention | 7日 |

次の内容はArtifact化しません。

- `playwright-report/`全体
- `test-results/`全体
- trace
- video
- `error-context.md`
- auth state、storageState
- cookie、session、CSRF token等の認証情報

失敗screenshotにも画面上の情報が含まれます。E2Eでは本番データや実在userを使用せず、Artifactへ秘密情報や個人情報が含まれていないことを確認し、必要期間を超えて保持しません。Playwrightのbrowser stateは認証cookie等を含み得るため、将来storageStateを導入する場合もrepositoryやArtifactへ安易に保存しないでください。

---

## 12. Git管理除外

次のPlaywright生成物は`.gitignore`でGit管理から除外します。

```text
/playwright-report/
/test-results/
/playwright/.auth/
```

HTML reporterは現行設定に含まれませんが、`playwright-report/`が生成された場合もcommitしない安全側の除外設定を維持します。browser binaryや`node_modules/`もrepositoryへ含めません。

---

## 13. 手動確認として残す範囲

次はPlaywrightの自動確認だけで完了としません。

- PC、tablet、mobile幅でのresponsive表示
- 星、card、form、modal等の視覚的な崩れ
- 色、contrast、animation、transitionの見え方
- スクリーンリーダー等の支援技術による読み上げと操作
- WCAGに対する包括的なアクセシビリティ監査
- 自動化対象外のbrowser・OS・支援技術の組み合わせ
- 必要に応じたDevTools Console等の目視確認

PlaywrightによるARIA、keyboard、focus確認と、手動・支援技術による評価を組み合わせます。

---

## 14. トラブルシューティング

### E2E用環境変数が未設定

Composeの`E2E_DB_*`は必須です。`.env.example`の項目を参考にローカル設定を追加し、実credentialは共有文書やGitへ記録しません。placeholderのpasswordではMySQL接続に失敗するのが正常です。

### `laravel.e2e`が起動しない

通常のSail環境とMySQL serviceが動作し、MySQL healthcheckが成功していることを確認します。`laravel.e2e`はMySQLがhealthyになった後に起動します。

### `e2e:reset`が失敗する

エラーメッセージに従い、`APP_ENV`、`e2e_mysql`、config上のDB名、実接続先、MySQL権限を確認します。失敗後のDBを使用せず、原因解消後にresetを最初から成功させます。

### 設定変更が反映されない

別環境で作成したconfig cacheが残っていないか確認します。E2E初期運用では`config:cache`を使用しません。

### assetが表示されない

ホスト側で`npm run build`を実行し、build済みassetが存在することを確認します。E2Eは通常開発用Vite dev serverの`public/hot`へ依存しません。

### URLを取り違えている

ローカルは`http://localhost:83`、CIは`http://127.0.0.1:8000`です。CIのrunner-native serverへローカル用URLを適用しません。

---

## 15. 関連ドキュメントと公式一次情報

プロジェクト内の関連文書：

- [README](../README.md)
- [開発フロー](DEVELOPMENT_FLOW.md)
- [コマンド集](COMMANDS.md)
- [セキュリティ方針](SECURITY.md)
- [MVP1テスト対応状況](MVP1_TEST_COVERAGE.md)
- [要件定義](REQUIREMENTS.md)

公式一次情報：

- [Playwright Installation](https://playwright.dev/docs/intro)
- [Playwright Configuration](https://playwright.dev/docs/test-configuration)
- [Playwright Locators](https://playwright.dev/docs/locators)
- [Playwright Continuous Integration](https://playwright.dev/docs/ci)
- [Playwright Web server](https://playwright.dev/docs/test-webserver)
- [Playwright Authentication](https://playwright.dev/docs/auth)
- [Playwright Accessibility testing](https://playwright.dev/docs/accessibility-testing)
- [Laravel 13 Testing](https://laravel.com/docs/13.x/testing)
- [Laravel 13 Configuration](https://laravel.com/docs/13.x/configuration)
- [Laravel 13 Database Testing](https://laravel.com/docs/13.x/database-testing)
- [MySQL 8.4 CREATE USER](https://dev.mysql.com/doc/refman/8.4/en/create-user.html)
- [MySQL 8.4 GRANT](https://dev.mysql.com/doc/refman/8.4/en/grant.html)
- [MySQL 8.4 SHOW GRANTS](https://dev.mysql.com/doc/refman/8.4/en/show-grants.html)
- [MySQL 8.4 Privileges Provided by MySQL](https://dev.mysql.com/doc/refman/8.4/en/privileges-provided.html)
- [GitHub Actions: Store and share data with workflow artifacts](https://docs.github.com/en/actions/tutorials/store-and-share-data)
