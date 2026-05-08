# TROUBLESHOOTING.md

## 1. このドキュメントの目的

Laravel移植作業中に発生しやすい問題の確認手順をまとめる。

いきなり修正せず、原因を切り分けてから対応する。

- DB詳細設計の判断は docs/DATABASE.md で扱う
- コマンド一覧は docs/COMMANDS.md を参照する
- セキュリティ方針は docs/SECURITY.md を参照する
- デプロイ方針は docs/DEPLOYMENT.md を参照する

## 2. 基本の切り分け手順

以下の順で確認する。

1. エラーメッセージを確認する
2. 現在のブランチを確認する
3. 未コミット変更を確認する
4. Sailコンテナが起動しているか確認する
5. `.env` の設定を確認する
6. キャッシュをクリアする
7. route / migration / log を確認する
8. 直前の変更を確認する

```bash
git status
git branch
./vendor/bin/sail ps
./vendor/bin/sail artisan optimize:clear
```

## 3. Sailが起動しない

確認手順：

- Docker Desktopが起動しているか確認する
- 既存コンテナとのポート競合を確認する
- `.env` の `APP_PORT`、`FORWARD_DB_PORT` を確認する
- それでも起動しない場合はログを確認する

```bash
./vendor/bin/sail up -d
./vendor/bin/sail ps
./vendor/bin/sail logs
```

## 4. 画面が表示されない

確認手順：

- Sailが起動しているか確認する
- `APP_URL` / `APP_PORT` が正しいか確認する
- ブラウザでアクセスしているURLが正しいか確認する
- ルートが存在するか確認する
- 500エラーの場合はLaravelログを確認する
- 404の場合はルーティングを確認する

```bash
./vendor/bin/sail artisan route:list
./vendor/bin/sail artisan route:list --path=items
```

## 5. CSSやJavaScriptが反映されない

確認手順：

- Vite開発サーバーが起動しているか確認する
- 開発中は `npm run dev` が必要
- 本番反映前は `npm run build` を実行する
- `node_modules/` がない場合は `npm install` を実行する
- ブラウザキャッシュの可能性を確認する
- Tailwind CSSのクラス名が正しいか確認する

```bash
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
./vendor/bin/sail npm run build
```

## 6. Breeze認証画面が動かない

確認手順：

- Breeze関連ルートを確認する
- `login` / `register` / `logout` のルートが存在するか確認する
- マイグレーション済みか確認する
- `users` テーブルがあるか確認する
- `.env` のDB接続先を確認する

```bash
./vendor/bin/sail artisan route:list --path=login
./vendor/bin/sail artisan route:list --path=register
./vendor/bin/sail artisan route:list --path=logout
./vendor/bin/sail artisan migrate:status
```

## 7. ルートが見つからない

確認手順：

- `routes/web.php` を確認する
- ルート名の typo を確認する
- URLとroute nameを混同していないか確認する
- middlewareで弾かれていないか確認する
- route cacheが残っていないか確認する

```bash
./vendor/bin/sail artisan route:list
./vendor/bin/sail artisan route:list --name=items
./vendor/bin/sail artisan route:list --path=items
./vendor/bin/sail artisan route:clear
```

## 8. DB接続エラー

確認手順：

- Sailコンテナが起動しているか確認する
- MySQLコンテナが起動しているか確認する
- `.env` のDB設定を確認する
- `DB_HOST` はSail環境では通常MySQLサービス名になる
- 設定変更後はキャッシュをクリアする
- DB詳細設計は docs/DATABASE.md で扱う

```bash
./vendor/bin/sail ps
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail artisan config:clear
```

## 9. マイグレーションで詰まった

確認手順：

- `migrate:status` で状態を確認する
- `rollback` は直前のマイグレーションを戻す
- `migrate:fresh` は全テーブル削除になるため注意する
- 開発環境以外では `migrate:fresh` は原則使わない
- 実行前に接続先DBを確認する
- テーブル削除・カラム削除を伴う場合は特に注意する

```bash
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:rollback
```

注意：`migrate:fresh` は全テーブルを削除するため、実行前に必ず対象DBを確認する。本番環境では原則禁止。

## 10. Seederが反映されない

確認手順：

- Seederクラスが存在するか確認する
- `DatabaseSeeder` から呼び出しているか確認する
- `db:seed` を実行しているか確認する
- `migrate --seed` と `db:seed` の違いを確認する

```bash
./vendor/bin/sail artisan db:seed
./vendor/bin/sail artisan migrate --seed
```

## 11. 画像アップロードで詰まった

確認手順：

- formに `enctype="multipart/form-data"` があるか確認する
- バリデーションでMIMEタイプ・サイズを確認しているか確認する
- ファイル名をランダム化しているか確認する
- `storage:link` が必要か確認する
- 公開してよい画像だけを公開する
- 個人情報性が高い画像は公開ディレクトリに直接置かない
- 詳細方針は docs/SECURITY.md と docs/DEPLOYMENT.md を参照する

```bash
./vendor/bin/sail artisan storage:link
```

## 12. CSRFエラーが出る

確認手順：

- POST / PATCH / DELETE フォームに `@csrf` があるか確認する
- DELETEやPATCHでは `@method` があるか確認する
- セッション切れの可能性を確認する
- Breeze標準フォームとの違いを確認する
- 独自フォームでは漏れやすいため注意する

```blade
<form method="POST" action="">
    @csrf
    @method('DELETE')
</form>
```

## 13. 認証・認可で弾かれる

確認手順：

- `auth` middlewareが付いているか確認する
- ログイン状態か確認する
- 自分のレビューだけ削除する方針になっているか確認する
- Policyを使う場合はPolicyが登録されているか確認する
- ルート、Controller、Policyの責務を分けて確認する

```bash
./vendor/bin/sail artisan route:list
```

## 14. XSS対策で迷った

確認手順：

- Bladeでは `{{ }}` を基本にする
- `{!! !!}` は原則使用しない
- JavaScriptへ値を渡す場合は `@json()` を検討する
- 画像ファイル名やユーザー入力値も信用しない
- 詳細は docs/SECURITY.md を参照する

## 15. Pintが動かない・コマンドが違う

このプロジェクトでは `./vendor/bin/sail pint --test` は使わない。

```bash
# 確認のみ（変更しない）
./vendor/bin/sail php ./vendor/bin/pint --test

# 自動整形
./vendor/bin/sail php ./vendor/bin/pint
```

## 16. PHPStan / Larastanでエラーが出る

このプロジェクトでは `./vendor/bin/phpstan analyse` を使う。

確認手順：

- エラー文を読んで、型・リレーション・未定義プロパティを確認する
- PHPStanは実行時の動作を完全に保証するものではない
- テストと合わせて確認する

```bash
./vendor/bin/phpstan analyse
```

## 17. テストが失敗する

確認手順：

- 失敗したテスト名を確認する
- 期待値と実際の値を確認する
- `.env.testing` の有無やDB接続先は後続で検討する
- 認証が必要な画面ではログイン状態をテストで作る必要がある
- まずはFeatureテスト中心で考える

```bash
./vendor/bin/sail test
./vendor/bin/sail test --filter test_example
```

## 18. npm / Viteビルドで失敗する

確認手順：

- `node_modules/` がない場合は `npm install` を実行する
- `package.json` と `package-lock.json` を確認する
- パッケージ追加後は差分を確認する
- `npm audit` は脆弱性確認に使う
- `npm ci` は今すぐ必須ではなく、CI/CDや自動デプロイで検討する

```bash
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
./vendor/bin/sail npm audit
```

## 19. キャッシュが原因で変更が反映されない

確認手順：

- route / config / view cache の可能性を確認する
- ローカルでは `optimize:clear` を試す
- 本番では実行タイミングに注意する

```bash
./vendor/bin/sail artisan optimize:clear
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan view:clear
```

## 20. Gitで関係ないファイルが混ざった

確認手順：

- `git status` で変更ファイルを確認する
- `git diff` / `git diff --staged` で差分を確認する
- 関係ない変更は `git restore` で戻す
- 1目的1コミットに分ける
- `.env`、`vendor/`、`node_modules/` を含めない

```bash
git status
git diff
git diff --staged
git restore --staged ファイル名
git restore ファイル名
```

## 21. 未追跡ファイルのdiffが出ない

確認手順：

- 未追跡ファイルは通常 `git diff ファイル名` では表示されない
- 内容確認する場合は `git add -N ファイル名` を使う方法がある
- 最終的にコミットする場合は通常の `git add ファイル名` が必要
- 誤って不要ファイルを追加しないよう注意する

```bash
git add -N docs/SECURITY.md
git diff -- docs/SECURITY.md
git add docs/SECURITY.md
```

## 22. コミット前に確認すること

- 現在のブランチが正しいか確認する
- 関係ない変更が含まれていないか確認する
- `.env` が含まれていないか確認する
- `vendor/` や `node_modules/` が含まれていないか確認する
- ドキュメントのみなら差分確認とリンク確認を行う
- 実装を含むならPint、テスト、PHPStan、Vite buildを確認する

```bash
git status
git diff
git diff --staged
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail test
./vendor/bin/phpstan analyse
./vendor/bin/sail npm run build
```

## 23. PR前確認

```bash
git status
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail test
./vendor/bin/phpstan analyse
./vendor/bin/sail npm run build
```

注意：

- ドキュメントのみのPRでは、差分確認とリンク確認を行う
- 実装を含むPRでは、Pint、テスト、静的解析、ビルドを確認する

## 24. 解決しない場合

以下を整理してから相談する。

- エラーメッセージを省略せず確認する
- 直前に変更したファイルを確認する
- いつから発生したか整理する
- 再現手順をメモする
- 何を試したか記録する

相談時にまとめる情報：

- 実行したコマンド
- エラーメッセージ
- 期待した動作
- 実際の動作
- 直前に変更したファイル
- `git status` の結果
- 関連するログ

## 25. 関連ドキュメント

- `docs/COMMANDS.md`
- `docs/DEVELOPMENT_FLOW.md`
- `docs/REQUIREMENTS.md`
- `docs/FEATURES.md`
- `docs/SCREEN_TRANSITIONS.md`
- `docs/ROUTES.md`
- `docs/DATABASE.md`
- `docs/SECURITY.md`
- `docs/DEPLOYMENT.md`
