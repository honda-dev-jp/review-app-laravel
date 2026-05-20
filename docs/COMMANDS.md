# コマンド集

## 基本方針

このドキュメントでは、映画レビューアプリ Laravel移植版の開発で使用する主なコマンドをまとめる。

このプロジェクトではLaravel Sailを使用しているため、Laravel関連のコマンドは基本的にSail経由で実行する。

環境差を避けるため、コマンド例では原則として `./vendor/bin/sail` 形式で記載する。

Sailのエイリアスを設定している場合は、`./vendor/bin/sail` を `sail` に読み替えて実行できる。

## Sailエイリアス設定

Laravel Sailは、通常 `./vendor/bin/sail` で実行する。

例：

```bash
./vendor/bin/sail up -d
```

毎回入力するのが長い場合は、任意でエイリアスを設定できる。

```bash
alias sail='sh $([ -f sail ] && echo sail || echo vendor/bin/sail)'
```

設定後は、以下のように短く実行できる。

```bash
sail up -d
sail down
sail test
```

このプロジェクトのドキュメントでは、環境差を避けるため、基本的に `./vendor/bin/sail` 形式で記述する。

## Sail起動・停止

```bash
./vendor/bin/sail up -d
./vendor/bin/sail down
```

## コンテナ状態確認

Sailで起動するコンテナの状態を確認する。

```bash
./vendor/bin/sail ps
```

停止中のコンテナも含めて確認する場合は、以下を使用する。

```bash
./vendor/bin/sail ps -a
```

## Laravel関連

Laravelアプリの基本情報確認や、開発中によく使う `artisan` コマンドをまとめる。

### 基本情報の確認

Laravelアプリの環境情報を確認する。

```bash
./vendor/bin/sail artisan about
```

### 使用可能な `artisan` コマンド一覧

使用できる `artisan` コマンドを確認する。

```bash
./vendor/bin/sail artisan list
```

### Tinker起動

LaravelのモデルやDB操作を対話的に確認したい場合に使用する。

```bash
./vendor/bin/sail artisan tinker
```

### ファイル生成コマンド

Controller、Model、FormRequest、Policyなどを作成するときに使用する。

```bash
./vendor/bin/sail artisan make:controller ItemController
./vendor/bin/sail artisan make:model Item
./vendor/bin/sail artisan make:request StoreReviewRequest
./vendor/bin/sail artisan make:policy ReviewPolicy --model=Review
```

ファイル生成コマンドは、画面・DB・ルート・認可方針を確認してから実行する。

## データベース関連

データベース操作やマイグレーション関連のコマンドをまとめる。

### マイグレーション実行

未実行のマイグレーションを実行する。

```bash
./vendor/bin/sail artisan migrate
```

### マイグレーション状態確認

各マイグレーションファイルの実行状態を確認する。

```bash
./vendor/bin/sail artisan migrate:status
```

### マイグレーションのロールバック

直前に実行したマイグレーションを戻す。

```bash
./vendor/bin/sail artisan migrate:rollback
```

ロールバックは、直前に実行したマイグレーションのバッチを戻すコマンドです。

注意：`migrate:rollback` は「直前の1ファイルだけ」を戻すとは限らない。

同じタイミングで実行された複数のマイグレーションが、まとめて戻る場合がある。

実行前に、以下で状態を確認する。

```bash
./vendor/bin/sail artisan migrate:status
```

1ステップ分だけ戻したい場合は、必要に応じて以下を検討する。

```bash
./vendor/bin/sail artisan migrate:rollback --step=1
```

### マイグレーションファイル作成

新しいテーブルを作成するマイグレーションファイルを作成する。

```bash
./vendor/bin/sail artisan make:migration create_items_table
```

既存テーブルにカラムを追加する場合は、対象テーブルを指定する。

```bash
./vendor/bin/sail artisan make:migration add_user_id_to_reviews_table --table=reviews
```

### Seeder実行

初期データや検証用データを投入する。

```bash
./vendor/bin/sail artisan db:seed
```

### マイグレーションとSeederをまとめて実行

マイグレーション実行後にSeederも実行する。

```bash
./vendor/bin/sail artisan migrate --seed
```

### データベース再作成

全テーブルを削除して、マイグレーションを最初から実行する。

```bash
./vendor/bin/sail artisan migrate:fresh
```

Seederも同時に実行する場合は、以下を使用する。

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

`migrate:fresh` は全テーブルを削除するコマンドです。

開発環境以外では原則使用しない。

実行前に、対象DBと `.env` の接続先を確認する。

誤って実行した場合の復旧手順は、必要に応じて `docs/TROUBLESHOOTING.md` に整理する。

## ルーティング確認

Laravelで定義されているルートを確認する。

### ルート一覧確認

```bash
./vendor/bin/sail artisan route:list
```

### パスで絞り込み

特定のURIに関係するルートだけ確認する。

```bash
./vendor/bin/sail artisan route:list --path=items
```

### ルート名で絞り込み

特定の名前が付いたルートを確認する。

```bash
./vendor/bin/sail artisan route:list --name=items
```

### レビュー関連ルートの確認

レビュー投稿、レビュー削除、レビュー返信投稿のルートを確認する。

```bash
./vendor/bin/sail artisan route:list --name=reviews
./vendor/bin/sail artisan route:list --name=reviews.replies
```

### プロフィール関連ルートの確認

プロフィール編集、プロフィール更新、会員退会関連のルートを確認する。

```bash
./vendor/bin/sail artisan route:list --path=profile
```

### お問い合わせ関連ルートの確認

後続フェーズでお問い合わせフォームを実装する場合に、問い合わせ送信関連のルートを確認する。

```bash
./vendor/bin/sail artisan route:list --name=contacts
```

### 管理者お問い合わせ関連ルートの確認

後続フェーズでお問い合わせ管理を実装する場合に、管理者側の問い合わせ管理ルートを確認する。

```bash
./vendor/bin/sail artisan route:list --name=admin.contacts
```

### HTTPメソッドで絞り込み

GETやPOSTなど、特定のHTTPメソッドに絞って確認する。

```bash
./vendor/bin/sail artisan route:list --method=GET
```

### middleware確認

ログイン必須や認可制御が正しく付いているか確認する。

```bash
./vendor/bin/sail artisan route:list
```

確認時は、以下を見る。

- URI
- Name
- Action
- Middleware

ルーティング設計は `docs/ROUTES.md` に整理し、実装後は `route:list` で差分を確認する。

## 認証・Breeze関連

Laravel Breezeによる認証機能に関する確認コマンドをまとめる。

### 認証関連ルートの確認

ログイン、会員登録、ログアウトなどの認証関連ルートを確認する。

```bash
./vendor/bin/sail artisan route:list --path=login
./vendor/bin/sail artisan route:list --path=register
./vendor/bin/sail artisan route:list --path=logout
```

### 認証関連ルート全体の確認

Breezeで追加された認証関連ルートを確認する。

```bash
./vendor/bin/sail artisan route:list --name=login
./vendor/bin/sail artisan route:list --name=register
./vendor/bin/sail artisan route:list --name=logout
```

### 認証関連ファイルの確認

Breezeで使用する認証関連のルートやControllerを確認する。

```bash
ls routes
ls app/Http/Controllers/Auth
```

### Breezeのパッケージ確認

Laravel Breezeが導入されているか確認する。

```bash
./vendor/bin/sail composer show laravel/breeze
```

### 認証ミドルウェアの確認

ログイン必須ページに `auth` ミドルウェアが付いているか確認する。

```bash
./vendor/bin/sail artisan route:list
```

確認時は、以下を見る。

- URI
- Name
- Action
- Middleware

会員のみ利用できる機能は、原則として `auth` ミドルウェアで制御する。

## テスト

PHPUnitによるテスト実行コマンドをまとめる。

### 全テスト実行

プロジェクト全体のテストを実行する。

```bash
./vendor/bin/sail test
```

### 特定のテストファイルを実行

特定のテストファイルだけを実行する。

```bash
./vendor/bin/sail test tests/Feature/ExampleTest.php
```

### 特定のテストメソッドを実行

特定のテストメソッドだけを実行する。

```bash
./vendor/bin/sail test --filter test_example
```

### Featureテスト作成

画面遷移、ルーティング、認証、DB登録などを確認するFeatureテストを作成する。

```bash
./vendor/bin/sail artisan make:test ItemListTest
```

### Unitテスト作成

単体の処理やサービスクラスなどを確認するUnitテストを作成する。

```bash
./vendor/bin/sail artisan make:test ReviewRatingCacheServiceTest --unit
```

### テスト実行時の注意点

テストは、実装後やPR作成前に実行する。

```bash
./vendor/bin/sail test
```

認証、認可、レビュー投稿、削除処理など、データ更新を伴う機能はテストで確認する。

## コード整形

Laravel Pintによるコード整形コマンドをまとめる。

### コードスタイル確認

コードスタイルに問題がないか確認する。

```bash
./vendor/bin/sail php ./vendor/bin/pint --test
```

### コードスタイル自動修正

コードスタイルを自動で整形する。

```bash
./vendor/bin/sail php ./vendor/bin/pint
```

### 対象を指定して確認

特定のファイルやディレクトリだけ、コードスタイルに問題がないか確認する。

```bash
./vendor/bin/sail php ./vendor/bin/pint app --test
./vendor/bin/sail php ./vendor/bin/pint routes --test
./vendor/bin/sail php ./vendor/bin/pint tests --test
```

### 注意点

この環境では、以下のコマンドは使用しない。

```bash
./vendor/bin/sail pint --test
```

このプロジェクトでは、Pintは以下の形式で実行する。

```bash
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail php ./vendor/bin/pint
```

PR前には、コードスタイル確認を実行する。

## 静的解析

PHPStan / Larastanによる静的解析コマンドをまとめる。

### 静的解析の実行

PHPコードを実行せずに解析し、型の不整合や潜在的な問題を確認する。

このプロジェクトでは、Sail環境のPHPバージョン・拡張機能に合わせるため、PHPStan / Larastan もSail経由で実行する。

```bash
./vendor/bin/sail php ./vendor/bin/phpstan analyse
```

### 設定ファイル確認

PHPStan / Larastanの設定は `phpstan.neon` に記述する。

```bash
cat phpstan.neon
```

### Larastan導入確認

Larastanが導入されているか確認する。

```bash
./vendor/bin/sail composer show larastan/larastan
```

### PHPStan導入確認

PHPStanが導入されているか確認する。

```bash
./vendor/bin/sail composer show phpstan/phpstan
```

### 注意点

静的解析は、実行時の動作を完全に保証するものではありません。

PR前には、テストとあわせて静的解析を実行する。

```bash
./vendor/bin/sail test
./vendor/bin/sail php ./vendor/bin/phpstan analyse
```

型エラーや未定義プロパティなどが出た場合は、エラー内容を確認してから修正する。

## フロントエンド関連

Vite、npm、Tailwind CSSなど、フロントエンド関連のコマンドをまとめる。

### 開発用サーバー起動

CSSやJavaScriptの変更を開発中に反映する。

```bash
./vendor/bin/sail npm run dev
```

### 本番用ビルド

本番反映前やPR前に、フロントエンドのビルドが成功するか確認する。

```bash
./vendor/bin/sail npm run build
```

### Tailwind CSS反映確認

BladeやCSSを変更したのに画面へ反映されない場合は、開発用サーバーが起動しているか確認する。

```bash
./vendor/bin/sail npm run dev
```

PR前には、以下でビルドが成功することを確認する。

```bash
./vendor/bin/sail npm run build
```

### 注意点

画面表示やスタイルを確認するときは、必要に応じて `npm run dev` を起動する。

PR前には、`npm run build` が成功することを確認する。

`node_modules/` はGit管理対象に含めない。

## キャッシュクリア

Laravelの設定、ルート、ビューなどのキャッシュをクリアするコマンドをまとめる。

### アプリケーションキャッシュ削除

アプリケーション全体のキャッシュを削除する。

```bash
./vendor/bin/sail artisan cache:clear
```

### 設定キャッシュ削除

`.env` や `config` 配下の設定変更が反映されない場合に使用する。

```bash
./vendor/bin/sail artisan config:clear
```

### ルートキャッシュ削除

ルーティング設定の変更が反映されない場合に使用する。

```bash
./vendor/bin/sail artisan route:clear
```

### ビューキャッシュ削除

Bladeファイルの変更が反映されない場合に使用する。

```bash
./vendor/bin/sail artisan view:clear
```

### キャッシュ一括削除

複数のキャッシュをまとめて削除する。

```bash
./vendor/bin/sail artisan optimize:clear
```

### 注意点

ローカル開発中に変更が反映されない場合は、まず `optimize:clear` を試す。

```bash
./vendor/bin/sail artisan optimize:clear
```

本番環境では、キャッシュ削除により一時的にパフォーマンスへ影響する可能性があるため、実行タイミングに注意する。

## Composer関連

PHPパッケージ管理に使用するComposer関連のコマンドをまとめる。

### Composerパッケージ一覧確認

インストール済みのComposerパッケージを確認する。

```bash
./vendor/bin/sail composer show
```

### Composerパッケージのインストール

`composer.lock` に基づいて依存パッケージをインストールする。

```bash
./vendor/bin/sail composer install
```

既存プロジェクトをcloneした直後や、他の作業環境で `composer.lock` が更新された場合に使用する。

`composer install` は `composer.lock` に記録されたバージョンをインストールするため、依存関係を再現しやすい。

通常の開発では、理由なく `composer update` を実行しない。

### 特定パッケージの確認

特定のパッケージが導入されているか確認する。

```bash
./vendor/bin/sail composer show laravel/breeze
./vendor/bin/sail composer show larastan/larastan
./vendor/bin/sail composer show laravel/pint
```

### Composerパッケージ追加

新しいComposerパッケージを追加する。

```bash
./vendor/bin/sail composer require パッケージ名
```

開発環境でのみ使うパッケージは `--dev` を付ける。

```bash
./vendor/bin/sail composer require --dev パッケージ名
```

### Composerパッケージ更新

Composerパッケージを更新する。

```bash
./vendor/bin/sail composer update
```

特定のパッケージだけ更新する場合は、パッケージ名を指定する。

```bash
./vendor/bin/sail composer update パッケージ名
```

### autoload再生成

クラスやヘルパーファイルの読み込み設定を変更した場合に、autoloadを再生成する。

```bash
./vendor/bin/sail composer dump-autoload
```

### composer.jsonの検証

`composer.json` の内容に問題がないか確認する。

```bash
./vendor/bin/sail composer validate
```

### Composerパッケージの脆弱性確認

Composer依存パッケージに既知の脆弱性がないか確認する。

```bash
./vendor/bin/sail composer audit
```

`composer audit` で警告が出た場合は、内容を確認してから対応する。

安易に `composer update` を実行せず、Laravel 10との互換性、`composer.json` / `composer.lock` の差分、影響範囲を確認する。

### 注意点

`composer update` は依存パッケージのバージョンが変わる可能性があるため、実行前に変更内容を確認する。

パッケージ追加・更新後は、必要に応じて以下を確認する。

```bash
./vendor/bin/sail test
./vendor/bin/sail php ./vendor/bin/phpstan analyse
```

`vendor/` はGit管理対象に含めない。

## npm関連

フロントエンド用パッケージ管理に使用するnpm関連のコマンドをまとめる。

### npmパッケージのインストール

`package.json` に定義されているパッケージをインストールする。

```bash
./vendor/bin/sail npm install
```

### npmパッケージの再現インストール

`package-lock.json` に基づいて依存パッケージを再現する。

```bash
./vendor/bin/sail npm ci
```

### npmパッケージ一覧確認

インストール済みのnpmパッケージを確認する。

```bash
./vendor/bin/sail npm list
```

### npmパッケージ追加

新しいnpmパッケージを追加する。

```bash
./vendor/bin/sail npm install パッケージ名
```

開発環境でのみ使うパッケージは `--save-dev` を付ける。

```bash
./vendor/bin/sail npm install --save-dev パッケージ名
```

### npmパッケージ更新

npmパッケージを更新する。

```bash
./vendor/bin/sail npm update
```

### 古いnpmパッケージの確認

更新可能なnpmパッケージを確認する。

```bash
./vendor/bin/sail npm outdated
```

### 脆弱性情報の確認

npmパッケージの脆弱性情報を確認する。

```bash
./vendor/bin/sail npm audit
```

### package.jsonの確認

フロントエンド関連の依存パッケージやscriptsを確認する。

```bash
cat package.json
```

### 依存関係の差分確認

npmパッケージを追加・更新した場合は、`package.json` と `package-lock.json` の差分を確認する。

```bash
git diff package.json package-lock.json
```

### 注意点

npmパッケージは公式レジストリから取得していても、サプライチェーン攻撃により悪意あるコードが混入する可能性がある。

新しいパッケージを追加する場合は、以下を確認する。

- パッケージ名に typo がないか
- 公式ドキュメントやGitHubリポジトリから案内されているパッケージか
- ダウンロード数やメンテナンス状況に不自然な点がないか
- 直近で不審なメジャーアップデートがないか
- 不要なパッケージを追加していないか
- 追加理由を説明できるパッケージか

`npm update` は依存パッケージのバージョンが変わる可能性があるため、実行前に変更内容を確認する。

`npm audit fix` は依存パッケージのバージョンを変更する可能性があるため、内容を確認せずに安易に実行しない。

特に `npm audit fix --force` は、破壊的なメジャーアップデートを含む可能性があるため、安易に実行しない。

パッケージ追加・更新・脆弱性対応後は、必要に応じて以下を確認する。

```bash
git diff package.json package-lock.json
./vendor/bin/sail npm run build
./vendor/bin/sail test
```

`node_modules/` はGit管理対象に含めない。

## Git操作

Gitでよく使う基本操作をまとめる。

### 状態確認

現在のブランチ、変更ファイル、ステージ状況を確認する。

```bash
git status
```

短い形式で確認する場合は、以下を使用する。

```bash
git status --short
```

### ブランチ確認

ローカルブランチを確認する。

```bash
git branch
```

リモートブランチも含めて確認する。

```bash
git branch -a
```

### 最新状態の取得

現在のブランチに、リモートの最新状態を取り込む。

```bash
git pull origin main
```

### 作業ブランチ作成

新しい作業ブランチを作成して切り替える。

```bash
git switch -c ブランチ名
```

例：

```bash
git switch -c docs/add-initial-project-documents
```

### ブランチ切り替え

既存のブランチへ切り替える。

```bash
git switch ブランチ名
```

### 差分確認

作業中の差分を確認する。

```bash
git diff
```

特定のファイルだけ差分を確認する。

```bash
git diff ファイル名
```

ステージ済みの差分を確認する。

```bash
git diff --staged
```

### ファイルをステージする

特定のファイルをステージする。

```bash
git add ファイル名
```

例：

```bash
git add README.md
```

複数ファイルをまとめてステージする。

```bash
git add ファイル名1 ファイル名2
```

### コミット

ステージした変更をコミットする。

```bash
git commit -m "コミットメッセージ"
```

例：

```bash
git commit -m "docs: READMEにLaravel移植版の概要を追加"
```

### リモートへpush

作業ブランチをGitHubへpushする。

```bash
git push -u origin ブランチ名
```

例：

```bash
git push -u origin docs/add-initial-project-documents
```

2回目以降のpushは、以下で実行できる。

```bash
git push
```

### ローカルブランチ削除

マージ済みのローカルブランチを削除する。

```bash
git branch -d ブランチ名
```

強制削除する場合は、以下を使用する。

```bash
git branch -D ブランチ名
```

`-D` は未マージの変更も削除できるため、使用前に内容を確認する。

### リモート追跡ブランチの整理

GitHub上で削除済みのリモートブランチ情報をローカルから整理する。

```bash
git fetch --prune
```

### 注意点

mainブランチへ直接pushしない。

コミット前には、必ず以下を確認する。

```bash
git status
git diff
```

ステージ後は、以下でコミット対象を確認する。

```bash
git diff --staged
```

関係ない変更を同じコミットに含めない。

## PR前確認

Pull Requestを作成する前に確認するコマンドをまとめる。

### 変更状況の確認

現在の変更状況を確認する。

```bash
git status
```

### 差分確認

作業内容の差分を確認する。

```bash
git diff
```

ステージ済みの差分を確認する。

```bash
git diff --staged
```

### コードスタイル確認

Laravel Pintでコードスタイルに問題がないか確認する。

```bash
./vendor/bin/sail php ./vendor/bin/pint --test
```

### テスト実行

PHPUnitのテストを実行する。

```bash
./vendor/bin/sail test
```

### 静的解析

PHPStan / Larastanで静的解析を実行する。

```bash
./vendor/bin/sail php ./vendor/bin/phpstan analyse
```

### フロントエンドビルド

Viteのビルドが成功するか確認する。

```bash
./vendor/bin/sail npm run build
```

### PR前の基本確認セット

PR前には、必要に応じて以下をまとめて確認する。

```bash
git status
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail test
./vendor/bin/sail php ./vendor/bin/phpstan analyse
./vendor/bin/sail npm run build
```

### 注意点

PRを作成する前に、以下を確認する。

- 関係ない変更が含まれていないか
- `.env` がコミット対象に含まれていないか
- `vendor/` や `node_modules/` が含まれていないか
- 不要なデバッグコードが残っていないか
- コミットメッセージが変更内容と合っているか
- READMEやdocsのリンク切れがないか

実装を含むPRでは、Pint、テスト、静的解析、ビルドを確認する。

ドキュメントのみのPRでは、差分確認とリンク確認を行う。

## 注意点

このドキュメントのコマンドは、基本的にローカル開発環境での使用を想定する。

本番環境や共有環境でコマンドを実行する場合は、事前に影響範囲を確認する。

特に、以下のコマンドはデータや環境に影響する可能性があるため注意する。

- `migrate:rollback`
- `migrate:fresh`
- `composer update`
- `npm update`
- パッケージ追加コマンド
- キャッシュクリア系コマンド

`.env`、`vendor/`、`node_modules/` はGit管理対象に含めない。

パッケージを追加・更新した場合は、以下を確認する。

```bash
git diff composer.json composer.lock
git diff package.json package-lock.json
```

PR前には、必要に応じて以下を確認する。

```bash
git status
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail test
./vendor/bin/sail php ./vendor/bin/phpstan analyse
./vendor/bin/sail npm run build
```

ドキュメントのみの修正では、差分確認とリンク確認を行う。

```bash
git status
git diff
```

コマンドを実行する前に、現在のブランチと変更状況を確認する。

```bash
git status
```

不明なコマンドや影響範囲が分からないコマンドは、実行前に内容を確認する。
