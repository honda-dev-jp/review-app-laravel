# デプロイ方針

## 1. ドキュメントの位置づけ

このドキュメントは、映画レビューアプリ Laravel移植版を将来 XServer のサブドメインへデプロイするための方針を整理する。

初期移植フェーズでは、本番デプロイは実施しない。

ただし、XServerでLaravel移植版を公開する方針が具体化してきたため、現時点で分かっている情報を以下の区分で整理する。

| 区分 | 意味 |
|---|---|
| 確定 | 調査・確認済み、またはプロジェクト方針として確定している内容 |
| 方針決定済み | 実行前だが、現時点の運用方針として採用する内容 |
| 未検証 | 実際のサーバー上では未確認の内容 |
| 要確認 | 実行前に確認が必要な内容 |
| 保留 | 危険操作を含むため、検証前に確定手順として扱わない内容 |

このドキュメントは、確定情報と未検証情報を混ぜないための暫定完成版とする。

今後、XServer上で実際に検証した結果に応じて、各項目の状態を更新する。

---

## 2. 現時点の検証状況

| 項目 | 状態 | 備考 |
|---|---|---|
| Laravel 10 を使用する | 確定 | Laravel移植版の前提 |
| Laravel Sail をローカル開発で使用する | 確定 | 本番環境では使用しない |
| 本番環境では Docker / Sail を使用しない | 確定 | XServer共有サーバー運用のため |
| 公開用サブドメイン | 確定 | `review-laravel.honda-dev.com` |
| XServerでのサブドメイン作成 | 確認済み | サブドメイン作成済み |
| Web実行時PHPバージョン | 確認済み | PHP 8.3.30 を確認済み |
| SSH接続 | 確認済み | WSL Ubuntuから接続確認済み |
| SSH接続ポート | 確認済み | `10022` |
| SSH認証方式 | 確認済み | 公開鍵認証 |
| SSH上の通常 `php` | 確認済み | 初期状態では PHP 8.0.30 だった |
| SSH上のPHP 8.3 | 確認済み | `/usr/bin/php8.3` で PHP 8.3.30 を確認済み |
| SSH上の `php` を PHP 8.3 に切替 | 確認済み | `~/bin/php` と `PATH` 設定で確認済み |
| Laravel本体配置方針 | 方針決定済み | `public_html` の外側に置く |
| サブドメイン公開ディレクトリ | 確認済み | `public_html/サブドメイン名/` が作成されることを確認済み |
| Laravel `public` とXServer公開ディレクトリの対応 | 未検証 | 方式は未確定 |
| Composer install | 未検証 | XServer上で要確認 |
| 本番用 `.env` 作成 | 未検証 | 本番DB情報確定後に作成 |
| APP_KEY設定 | 未検証 | 初回デプロイ時に要確認 |
| 本番DB作成・接続 | 未検証 | XServer上で要確認 |
| マイグレーション実行 | 未検証 | 危険操作のため慎重に扱う |
| storage link | 未検証 | XServer上でシンボリックリンク可否を要確認 |
| キャッシュ系コマンド | 未検証 | 実行タイミングを要確認 |
| ブラウザでのLaravel表示 | 未検証 | 実デプロイ後に確認 |

---

## 3. デプロイ前提

以下はプロジェクト方針として確定している。

- Laravel 10 を使用する
- MySQL を使用する
- Laravel Breeze 導入済み
- Laravel Sail はローカル開発環境専用とする
- 本番環境では Docker / Laravel Sail は使用しない
- `.env` は本番環境用に個別設定する
- `.env` はGit管理しない
- `APP_ENV=production` にする
- `APP_DEBUG=false` にする
- `APP_KEY` を本番環境用に設定する
- 本番DB接続情報を設定する
- `vendor/` はGit管理しない
- `node_modules/` はGit管理しない

---

## 4. XServer運用方針

Laravel移植版は、XServer上の以下サブドメインで公開する方針とする。

```text
review-laravel.honda-dev.com
```

XServer上で作業する場合は、SSHまたはSFTPを使用する。

SSH接続については、以下を確認済み。

- WSL UbuntuからXServerへSSH接続できる
- SSHは公開鍵認証を使用する
- SSH接続ポートは `10022` を使用する
- SSH接続後、`pwd` / `whoami` / `ls` で現在位置と接続ユーザーを確認する

本番環境または本番相当の環境で作業する前に、必ず現在位置を確認する。

```bash
pwd
ls
```

本番作業では、以下のような影響範囲が大きいコマンドを安易に実行しない。

```bash
rm
mv
cp
chmod
chmod -R
ln -s
rsync --delete
```

上記コマンドは、対象ディレクトリ・目的・影響範囲を確認してから実行する。

---

## 5. 公開ディレクトリ方針

Laravelでは、Webから直接公開する対象は基本的に `public` ディレクトリとする。

以下のディレクトリやファイルは、直接Web公開しない。

- `.env`
- `app/`
- `bootstrap/`
- `config/`
- `database/`
- `resources/`
- `routes/`
- `storage/`
- `vendor/`

Laravelプロジェクト全体をそのままサブドメイン公開ディレクトリへ置くことは、セキュリティ上の重大なリスクになるため行わない。

### 方針決定済みの配置方針

Laravel本体は、原則として `public_html` の外側に配置する。

想定構成は以下。

```text
/home/ユーザー名/ドメイン名/
├── laravel-review/          ← Laravel本体の配置候補
└── public_html/
    └── review-laravel/      ← サブドメイン公開ディレクトリ
```

### 未検証の公開方法

Laravel本体の `public` と、XServerのサブドメイン公開ディレクトリをどう対応させるかは未検証。

候補は以下。

| 方法 | 状態 | 備考 |
|---|---|---|
| Laravel `public` の中身をサブドメイン公開ディレクトリへ反映する | 要確認 | 初回候補として安全性を検証する |
| サブドメイン公開ディレクトリの `index.php` からLaravel本体を参照する | 要確認 | パス調整が必要 |
| シンボリックリンク方式 | 保留 | `ln -s` を伴うため検証前に確定手順にしない |

この段階では、`rm`、`mv`、`ln -s` などの操作を確定手順として書かない。

---

## 6. 本番反映前の確認（ローカル環境）

本番反映前に、ローカル環境で以下を確認する。

- mainブランチが最新であること
- 未コミット変更がないこと
- `.env` をコミットしていないこと
- `composer.lock` があること
- `package-lock.json` があること
- テストが通ること
- Pintで整形済みであること
- PHPStan / Larastan で大きな問題がないこと
- Viteのbuildが通ること

確認コマンド。

```bash
git status
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail test
./vendor/bin/sail php ./vendor/bin/phpstan analyse
./vendor/bin/sail npm run build
```

---

## 7. Composer依存関係

本番環境では `composer install` を使用する方針とする。

`composer update` は本番環境で安易に実行しない。

`composer.lock` に基づいて依存関係を再現する。

### セキュリティ確認方針

Composer依存関係は、`composer.lock` に記録されたバージョンを前提に再現する。

本番反映前には、ローカル環境で以下を確認する。

```bash
./vendor/bin/sail composer validate
./vendor/bin/sail composer audit
```

XServer上でComposerを実行する場合も、可能であればLaravel本体ディレクトリで以下を確認する。

```bash
composer validate
composer audit
```

Composer公式ドキュメントでは、`composer audit` によってセキュリティアドバイザリ、マルウェア判定、放棄されたパッケージなどを確認できる。

このプロジェクトでは、以下を方針とする。

- `composer.lock` をGit管理し、依存関係を固定する
- 本番環境では `composer update` を安易に実行しない
- パッケージ更新はローカルまたは作業ブランチで実施し、差分確認、テスト、静的解析後に反映する
- `composer audit` で脆弱性が出た場合は、内容を確認してから更新または対応方針を決める
- `composer audit` の警告を理由なく無視しない
- `vendor/` はGit管理しない

### 未検証

XServer上で以下が実行できるかは未検証。

```bash
composer --version
which composer
composer validate
composer audit
composer install --no-dev --optimize-autoloader
```

### 注意点

- SSH上の `php` が PHP 8.3 を指していることを確認してから実行する
- メモリ制限やタイムアウトで失敗する可能性がある
- 本番環境では `--no-dev` を付ける
- Composerが利用できない場合は、別の反映方法を検討する
- 脆弱性対応で依存関係を更新した場合は、`composer.json` と `composer.lock` の差分を確認する

---

## 8. npm / Viteビルド

XServer上で `npm install` / `npm run build` を実行する運用にはしない方針とする。

初期移植フェーズでは、ローカル環境で本番ビルドを行い、生成されたビルド成果物を本番環境へ反映する。

### ローカルビルド方針

本番反映用のビルドでは、`package-lock.json` に基づいて依存関係を再現するため、原則として `npm ci` を使用する方針とする。

```bash
./vendor/bin/sail npm ci
./vendor/bin/sail npm audit
./vendor/bin/sail npm run build
```

ただし、初回導入時や依存パッケージを追加・更新する場合は `npm install` を使用し、`package.json` と `package-lock.json` の差分を確認する。

```bash
./vendor/bin/sail npm install
git diff package.json package-lock.json
```

本番反映対象の候補。

```text
public/build
```

### セキュリティ確認方針

公式ドキュメントでは、`package-lock.json` は依存関係ツリーを再現するためのファイルであり、リポジトリへコミットすることが想定されている。

また、`npm ci` は既存の `package-lock.json` を前提にし、`package.json` と内容が一致しない場合はエラー終了する。

このプロジェクトでは、以下を方針とする。

- `package-lock.json` をGit管理する
- `node_modules/` はGit管理しない
- 本番反映用ビルドでは `npm ci` を優先する
- 依存関係を追加・更新する場合のみ `npm install` を使う
- `npm audit` で脆弱性を確認する
- `npm audit fix` は自動で依存関係を変更する可能性があるため、実行前に内容を確認する
- `npm audit fix --force` は破壊的なメジャー更新を含む可能性があるため、安易に実行しない
- 脆弱性対応で依存関係を更新した場合は、ビルドとテストを確認する

### 未検証

以下は未検証。

- `public/build` をGit管理するか
- SFTPで `public/build` を反映するか
- 別の転送手段を使うか
- Blade側の `@vite()` が本番ビルド済みファイルを正しく参照できるか
- XServer上でNode.js / npmを使わない運用で、CSS / JavaScriptが問題なく反映できるか

### 注意点

- `node_modules/` は本番へアップロードしない
- `package-lock.json` はGit管理する
- CSS / JavaScript が反映されているかブラウザで確認する
- npmパッケージはサプライチェーン攻撃の影響を受ける可能性があるため、不要なパッケージを追加しない

---

## 9. 本番用 `.env` 方針

本番用 `.env` は、XServer上のLaravel本体ディレクトリに作成する方針とする。

`.env` はGit管理しない。

本番用 `.env` では、少なくとも以下を設定する。

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://review-laravel.honda-dev.com
```

DB接続情報は、XServerで作成した本番DB情報を設定する。

```env
DB_CONNECTION=mysql
DB_HOST=要確認
DB_PORT=3306
DB_DATABASE=要確認
DB_USERNAME=要確認
DB_PASSWORD=要確認
```

後続フェーズでお問い合わせフォームやメール通知を実装する場合は、メール送信設定を本番用 `.env` に追加する。

設定候補：

```env
MAIL_MAILER=要確認
MAIL_HOST=要確認
MAIL_PORT=要確認
MAIL_USERNAME=要確認
MAIL_PASSWORD=要確認
MAIL_FROM_ADDRESS=要確認
MAIL_FROM_NAME=要確認
```

ただし、初期移植フェーズではお問い合わせフォームとメール通知は実装対象外とする。

### 未検証

以下は未検証。

- XServer上のDBホスト名
- 本番DB名
- 本番DBユーザー名
- 本番DBパスワード
- Laravelから本番DBへ接続できるか

### 注意点

- `.env` の内容をREADMEやdocsへ記載しない
- `.env` の内容を記事やスクリーンショットに載せない
- 本番DB情報を公開しない

---

## 10. APP_KEY 方針

本番環境では、APP_KEYを必ず設定する。

初回デプロイ時にAPP_KEYが未設定の場合のみ、以下の実行を検討する。

```bash
php artisan key:generate
```

### 注意点

既に運用中の環境でAPP_KEYを再生成すると、暗号化済みデータやセッションに影響する可能性がある。

そのため、APP_KEYが設定済みの場合は安易に再生成しない。

---

## 11. マイグレーション方針

本番環境でマイグレーションを実行する場合は慎重に行う。

### 実行前確認

- 接続先DBが本番DBであること
- バックアップ方針を確認していること
- `php artisan migrate:status` で状態を確認すること
- 実行されるマイグレーションの内容を確認していること
- 後続フェーズでお問い合わせフォームを追加する場合は、`contacts` テーブル追加マイグレーションの影響範囲を確認すること
- `contacts` テーブルでは問い合わせ者名、メールアドレス、件名、本文などの個人情報を扱う可能性があるため、保存範囲とバックアップ方針を確認すること

### 未検証

以下は未検証。

```bash
php artisan migrate --force
```

### 原則禁止・慎重扱い

本番環境では以下を原則実行しない。

```bash
php artisan migrate:fresh
php artisan migrate:rollback
```

特に、テーブル削除・カラム削除・外部キー制約変更を伴うマイグレーションは慎重に扱う。

`reviews`、`review_comments`、`items.rating`、`items.rating_count` など、レビュー・評価キャッシュに関わる変更は影響範囲を確認する。

---

## 12. storageと画像

ユーザーアバターや作品サムネイルを扱う場合、公開が必要なファイルだけを公開する。

個人情報性が高い画像や非公開画像は公開ディレクトリに直接置かない。

Laravelでは `storage:link` の利用を検討する。

### 未検証

以下は未検証。

```bash
php artisan storage:link
```

### 要確認

- XServer上でシンボリックリンクが期待通り使えるか
- `public/storage` が既に存在しないか
- 既存ファイルと衝突しないか
- `storage/app/public` の公開範囲
- `storage/logs` にログを書き込めるか
- `storage/framework` 配下にセッション・キャッシュ・ビュー関連の書き込みができるか
- `bootstrap/cache` にキャッシュを書き込めるか

---

## 13. キャッシュ方針

本番環境では、必要に応じて設定・ルート・ビューのキャッシュを使用する。

### 未検証

以下は未検証。

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 注意点

- `config:cache` を使う場合、`env()` はconfig配下でのみ使用する
- アプリケーションコード内で直接 `env()` を多用しない
- `.env` 変更後は `config:cache` / `config:clear` の扱いに注意する
- ルーティング変更後は `route:cache` / `route:clear` の扱いに注意する
- Blade変更後は `view:cache` / `view:clear` の扱いに注意する

---

## 14. エラー表示

本番環境では以下を守る。

- `APP_DEBUG=false` にする
- エラー詳細をユーザー画面に表示しない
- DBエラーやパス情報を画面に出さない
- エラー内容はLaravelログで確認する
- 500エラー発生時にLaravelログを確認できる状態にする

---

## 15. デプロイ手順の暫定案

この手順は、現時点では確定手順ではない。

未検証の項目を含むため、実行前に状態を確認しながら進める。

| 順番 | 作業 | 状態 |
|---|---|---|
| 1 | mainブランチの内容を確認する | 方針決定済み |
| 2 | ローカル環境でテスト・静的解析・Pint・Viteビルドを確認する | 方針決定済み |
| 3 | XServerのサブドメイン公開ディレクトリを確認する | 確認済み |
| 4 | SSH接続後に `pwd` / `whoami` / `ls` を確認する | 確認済み |
| 5 | Laravel本体を `public_html` 外へ配置する方法を決める | 方針決定済み・未実行 |
| 6 | Laravel `public` と公開ディレクトリの対応方法を決める | 未検証 |
| 7 | LaravelプロジェクトをXServerへ反映する | 未検証 |
| 8 | 本番用 `.env` を作成する | 未検証 |
| 9 | Composer install を実行する | 未検証 |
| 10 | APP_KEY を設定する | 未検証 |
| 11 | DB接続情報を設定する | 未検証 |
| 12 | `migrate --force` を実行する | 未検証・危険操作 |
| 13 | storage link を確認する | 未検証・危険操作 |
| 14 | キャッシュ系コマンドを実行する | 未検証 |
| 15 | ブラウザで画面表示を確認する | 未検証 |
| 16 | 主要機能を確認する | 未検証 |
| 17 | Laravelログを確認する | 未検証 |

---

## 16. デプロイ後確認

実デプロイ後、以下を確認する。

- `APP_ENV=production` であること
- `APP_DEBUG=false` であること
- `APP_KEY` が設定されていること
- トップページ表示
- 作品一覧表示
- 作品一覧ページネーション
- 作品詳細表示
- レビュー表示
- レビュー返信表示
- 星評価表示
- 会員登録
- ログイン
- ログアウト
- プロフィール編集
- 本人のレビュー一覧表示
- レビュー・評価投稿
- レビュー削除
- レビュー返信投稿
- 会員退会
- 画像表示
- CSS / JavaScript反映
- CSRF保護が有効であること
- 419 Page Expired が不要に発生しないこと
- 404 / 500系エラー表示
- Laravelログ確認
- `storage` / `bootstrap/cache` の書き込み確認

---

## 17. 危険操作として保留する項目

以下は、検証前に確定手順として扱わない。

| 操作 | 保留理由 |
|---|---|
| `rm` | 削除対象を誤ると復旧困難になるため |
| `mv` | 公開ディレクトリや既存ファイルを移動する可能性があるため |
| `chmod -R` | 広範囲の権限変更で既存サイトへ影響する可能性があるため |
| `ln -s` | シンボリックリンク先を誤ると公開設定に影響するため |
| `rsync --delete` | 転送先を誤ると既存ファイルを削除する可能性があるため |
| `php artisan migrate --force` | 本番DBを変更するため |
| `php artisan migrate:fresh` | 全テーブル削除を伴うため、本番では原則禁止 |
| `php artisan migrate:rollback` | 既存データやテーブル構造へ影響する可能性があるため |
| `php artisan key:generate` | 既存APP_KEYを再生成すると暗号化済みデータやセッションへ影響する可能性があるため |
| `php artisan storage:link` | 既存 `public/storage` との衝突やリンク先誤りがあり得るため |

---

## 18. やらないこと（初期移植フェーズ）

以下は初期移植フェーズでは実施しない。

- 本番デプロイの実施
- 自動デプロイの構築
- GitHub Actionsによる本番反映
- Docker本番運用
- ゼロダウンタイムデプロイ
- キュー / スケジューラ運用
- CDN設定
- 監視ツール導入
- 本番DBの本格移行
- XServer上でのNode.jsビルド前提の運用

---

## 19. 今後検討すること

- Laravelの `public` ディレクトリとXServerサブドメイン公開ディレクトリの対応方法
- Laravel本体を配置する具体的なディレクトリ名
- Laravelプロジェクトの反映方法
- ComposerをXServer上で実行するか
- 本番DBの作成方法
- 本番用 `.env` の設定項目
- `public/build` をGit管理するか、SFTP等で反映するか
- `storage:link` のXServer上での扱い
- `storage` / `bootstrap/cache` のパーミッション
- バックアップ方針
- WAF設定の影響確認
- HTTPS設定
- メール送信設定
- セキュリティヘッダー
- アクセスログ解析
- デプロイ手順の自動化
- お問い合わせフォーム追加時のメール送信設定
- お問い合わせ内容を保存する場合の本番DBマイグレーション方針
- お問い合わせフォームのスパム対策
- お問い合わせフォームで扱う個人情報の保護方針

---

## 20. 次に検証する順番

次回は、危険操作を避け、確認だけで進められる項目から検証する。

1. SSH接続する
2. `pwd` / `whoami` / `ls` で現在位置を確認する
3. サブドメイン公開ディレクトリを確認する
4. Laravel本体配置候補ディレクトリを確認する
5. Composerの利用可否を確認する
6. Laravel `public` と公開ディレクトリの対応方法を検討する
7. 危険操作を伴わない範囲で、公開方法を検証する
8. 本番DB作成・接続情報を確認する
9. `.env` 作成方針を確認する
10. マイグレーション実行前のバックアップ方針を確認する

---

このドキュメントは、現時点で分かる情報をもとにした暫定完成版である。

確定情報と未検証情報を分けて管理し、XServer上で検証した結果に応じて更新する。
