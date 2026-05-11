# DEPLOYMENT.md

## 1. ドキュメントの位置づけ

初期移植フェーズでは本番デプロイは実施しない。

このドキュメントは、将来のデプロイ時に確認するための方針メモとして作成している。実際の本番デプロイ手順は、XServerの仕様・公開ディレクトリ・PHPバージョン・DB設定が確定してから更新する。

ただし、XServerでLaravel移植版を公開する方針が具体化してきたため、現時点で確定できる範囲の運用方針を整理する。

## 2. デプロイ前提

- Laravel 10 を使用する
- PHPバージョンはLaravel 10に対応したものを使用する
- MySQL を使用する
- Laravel Breeze 導入済み
- `.env` は本番環境用に個別設定する
- `.env` はGit管理しない
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_KEY` を設定する
- 本番DB接続情報を設定する
- 本番環境ではDocker / Laravel Sailは使用しない
- Laravel Sailはローカル開発環境専用とする

## 3. XServer運用方針

Laravel移植版は、XServer上での公開を候補とする。

公開用サブドメインとして、以下を使用する方針とする。

```text
review-laravel.honda-dev.com
```

XServer上で作業する場合は、SSHまたはSFTPを使用する。

SSH接続を行う場合は、以下を前提とする。

- SSHは公開鍵認証を使用する
- SSH接続ポートは `10022` を使用する
- 接続はローカルPCのWSL Ubuntu側CLIから行う
- Dockerコンテナ内から本番サーバーへ接続する運用にはしない

本番環境または本番相当の環境で作業する前に、必ず現在位置を確認する。

```bash
pwd
ls
```

以下のような影響範囲が大きいコマンドは、実行前に対象ディレクトリと目的を確認する。

```bash
rm
mv
cp
chmod
chmod -R
```

初回デプロイ前に、既存のテスト用サブドメインでSSH接続、ディレクトリ移動、ファイル確認などの基本操作を練習する。

## 4. 公開ディレクトリ方針

Laravel の `public` ディレクトリをWeb公開ディレクトリにする。

`app` / `config` / `database` / `resources` / `routes` / `storage` / `.env` などは直接Web公開しない。

Laravelプロジェクト全体をそのまま公開ディレクトリへ置くことは、セキュリティ上の重大なリスクになるため行わない。

公開が必要なファイルだけを公開する。

XServerで運用する場合は、サブドメインの公開ディレクトリとLaravelの `public` 相当をどのように対応させるか確認する。

想定方針は以下の通り。

- Laravelプロジェクト本体は、可能であればWeb公開領域の外に配置する
- サブドメインの公開ディレクトリには、Laravelの `public` 相当のみを配置する
- `.env`、`vendor/`、`storage/`、`routes/`、`resources/` などを直接公開しない

XServer上でドキュメントルートをLaravelの `public` 相当に直接合わせられない場合の対応は、後続フェーズで検討する。

## 5. 本番反映前の確認（ローカル環境）

以下をすべてローカル環境で確認してから、本番反映前の最終確認やPR作成に進む。

- mainブランチが最新であること
- 未コミット変更がないこと
- `.env` をコミットしていないこと
- `composer.lock` があること
- `package-lock.json` があること
- テストが通ること
- Pintで整形済みであること
- PHPStan で大きな問題がないこと
- Viteのbuildが通ること

ローカル確認コマンド：

```bash
git status
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail test
./vendor/bin/sail php ./vendor/bin/phpstan analyse
./vendor/bin/sail npm run build
```

## 6. Composer依存関係

本番環境では `composer install` を使用する。`composer update` は本番環境で安易に実行しない。`composer.lock` に基づいて依存関係を再現する。

本番サーバー上での実行例（確定手順ではない。XServerの仕様確認後に更新する）：

```bash
composer install --no-dev --optimize-autoloader
```

本番サーバー上でComposerが利用できない、またはバージョン・メモリ・権限の問題がある場合は、ローカルで生成した依存関係を反映する方法を検討する。

ただし、`vendor/` をGit管理対象には含めない。

## 7. npm / Viteビルド

ローカル開発での使い分け：

- npmパッケージのインストール：`./vendor/bin/sail npm install`
- 開発用サーバー起動：`./vendor/bin/sail npm run dev`
- ローカルで本番ビルドを確認する場合：`./vendor/bin/sail npm run build`

`node_modules/` はGit管理しない。

XServer上で `npm install` / `npm run build` を実行するかは、Node.js / npm の利用可否やバージョン確認が必要になる。

初期移植フェーズでは、XServer上でのNode.js実行に依存しないため、ローカル環境で本番ビルドを実行し、生成されたビルド成果物を本番環境へ反映する方針とする。

ローカル確認コマンド：

```bash
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

本番反映対象の候補：

```text
public/build
```

注意点：

- `node_modules/` は本番へアップロードしない
- `package-lock.json` はGit管理する
- `public/build` をGit管理するか、SFTP等で反映するかは後続で検討する
- Blade側の `@vite()` が本番ビルド済みファイルを参照できることを確認する
- 反映後はブラウザでCSS / JavaScriptが正しく読み込まれているか確認する

`npm ci` は `package-lock.json` をもとに依存関係を再現するためのコマンド。CI環境や自動デプロイ環境では有効だが、現時点のローカル開発では `npm install` を基本とする。将来のCI/CDや本番ビルド手順を固める段階で検討する。

## 8. マイグレーション方針

本番環境でマイグレーションを実行する場合は慎重に行う。

- 実行前にバックアップ方針を確認する
- 実行前に対象DBが本番DBであることを確認する
- `php artisan migrate:status` で実行状況を確認する
- `migrate:fresh` は本番環境では原則禁止
- `migrate:rollback` は本番環境では安易に実行しない
- テーブル削除・カラム削除・外部キー制約変更を伴うマイグレーションは特に慎重に扱う
- `reviews` / `review_comments` / `items.rating` / `items.rating_count` など、レビュー・評価キャッシュに関わる変更は影響範囲を確認する

本番サーバー上での実行例（確定手順ではない。XServerの仕様確認後に更新する）：

```bash
php artisan migrate --force
```

初期移植フェーズではDB詳細は別ドキュメントで管理するため、ここでは詳細設計に踏み込まない。

## 9. キャッシュ方針

将来的に以下のキャッシュ設定を検討する。

本番サーバー上での実行例（確定手順ではない。XServerの仕様確認後に更新する）：

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

注意点：

- `config:cache` を使う場合、`env()` はconfig配下でのみ使用する
- アプリケーションコード内で直接 `env()` を多用しない
- `.env` 変更後は `config:cache` / `config:clear` の扱いに注意する
- ルーティング変更後は `route:cache` / `route:clear` の扱いに注意する
- Blade変更後は `view:cache` / `view:clear` の扱いに注意する

## 10. storageと画像

ユーザーアバターや作品サムネイルを扱う場合、保存先を整理する。公開が必要なファイルだけを公開する。

個人情報性が高い画像や非公開画像は公開ディレクトリに直接置かない。

Laravelでは `storage:link` の利用を検討する。ファイル削除時のストレージ整理は後続フェーズで検討する。

本番反映時は以下を確認する。

- `storage/app/public` に保存する画像の公開範囲
- `public/storage` のシンボリックリンク状態
- 画像URLが `Storage::url()` や `asset()` の設計と一致していること
- `storage/logs` にログを書き込めること
- `storage/framework` 配下にセッション・キャッシュ・ビュー関連の書き込みができること
- `bootstrap/cache` にキャッシュを書き込めること

## 11. エラー表示

- 本番環境では `APP_DEBUG=false` にする
- エラー詳細をユーザー画面に表示しない
- エラー内容はログで確認する
- 例外詳細・DB情報・パス情報を画面に出さない
- 500エラー発生時にLaravelログを確認できる状態にする

## 12. 環境差分

ローカル環境と本番環境で異なる主な項目を以下に整理する。

| 項目 | 備考 |
|---|---|
| APP_URL | `https://review-laravel.honda-dev.com` を想定 |
| APP_ENV | production |
| APP_DEBUG | false |
| APP_KEY | 本番環境用に設定する。運用中に安易に再生成しない |
| DB接続情報 | 本番DB用に変更 |
| メール設定 | 本番用SMTPまたはサービスに変更 |
| ファイル保存先 | storageパスの確認が必要 |
| 外部APIキー | TMDB APIなど（後続フェーズで検討） |
| ポート番号 | Sail設定と本番の差異に注意 |
| PHPバージョン | XServerのPHPバージョンを確認 |
| Composerバージョン | 本番サーバーのバージョンを確認 |
| Node.js / npm | 初期方針では本番実行に依存しない |
| SSH | 公開鍵認証、ポート10022を想定 |
| 公開ディレクトリ | サブドメインの公開ディレクトリとLaravel public相当の対応を確認 |

## 13. デプロイ手順の仮案

現時点では確定版ではなく、将来のデプロイ時の流れとして整理する。本番サーバー上のコマンドはSailなしで実行する想定だが、XServerの仕様確認後に更新する。

1. mainブランチの内容を確認する
2. ローカル環境でテスト・静的解析・Pint・Viteビルドを確認する
3. 本番環境のバックアップ方針を確認する
4. XServerのPHPバージョンを確認する
5. XServerのDB設定を確認する
6. サブドメイン `review-laravel.honda-dev.com` の公開ディレクトリを確認する
7. SSH接続またはSFTP接続を確認する
8. SSH接続後に `pwd` / `ls` で現在位置を確認する
9. ソースコードを反映する
10. `.env` を本番用に設定する
11. Composer依存関係をインストールする
12. ローカルで作成したViteビルド成果物を反映する
13. マイグレーションを実行する
14. キャッシュを作成する
15. storage linkを確認する
16. 画面表示を確認する
17. ログイン・レビュー投稿など主要機能を確認する
18. エラーログを確認する

## 14. デプロイ後確認

以下の機能が正常に動作することを確認する。

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

## 15. やらないこと（初期移植フェーズ）

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

## 16. 今後検討すること

- XServerでLaravelの `public` ディレクトリをどう公開するか
- サブドメイン `review-laravel.honda-dev.com` の公開ディレクトリ確認
- Laravel本体をWeb公開領域外に置けるか
- 本番DBの作成方法
- 既存スクラッチ版との比較公開方法
- `.env` の本番設定項目
- バックアップ方針
- ComposerをXServer上で実行するか
- `public/build` をGit管理するか、SFTP等で反映するか
- `storage:link` のXServer上での扱い
- デプロイ手順の自動化
- アクセスログ解析
- セキュリティヘッダー
- HTTPS設定
- メール送信設定
- TMDB APIキー管理

---

このドキュメントは、実際の本番デプロイ前にサーバー仕様に合わせて更新する。
