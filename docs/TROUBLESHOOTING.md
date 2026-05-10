# TROUBLESHOOTING.md

## 1. このドキュメントの目的

Laravel移植作業中に発生しやすい問題の確認手順をまとめる。

いきなり修正せず、エラーメッセージ、現在のブランチ、未コミット変更、実行したコマンド、直前の変更内容を確認し、原因を切り分けてから対応する。

- DB詳細設計の判断は `docs/DATABASE.md` で扱う
- コマンド一覧は `docs/COMMANDS.md` を参照する
- セキュリティ方針は `docs/SECURITY.md` を参照する
- デプロイ方針は `docs/DEPLOYMENT.md` を参照する

## 2. 基本の切り分け手順

以下の順で確認する。

1. エラーメッセージを省略せず確認する
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
- Bladeに `@vite(['resources/css/app.css', 'resources/js/app.js'])` があるか確認する
- Tailwindの対象ファイルにBladeのパスが含まれているか確認する

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
- `APP_KEY` が設定されているか確認する

```bash
./vendor/bin/sail artisan route:list --path=login
./vendor/bin/sail artisan route:list --path=register
./vendor/bin/sail artisan route:list --path=logout
./vendor/bin/sail artisan migrate:status
```

## 7. APP_KEY が未設定・暗号化キー関連エラーが出る

確認手順：

- `.env` に `APP_KEY` があるか確認する
- `.env.example` だけで `.env` が未作成ではないか確認する
- `APP_KEY` が空になっていないか確認する
- 設定変更後にconfig cacheが残っていないか確認する

確認のみ：

```bash
grep APP_KEY .env
./vendor/bin/sail artisan config:clear
```

`.env` に `APP_KEY` が存在しない、または空の場合のみ実行を検討する：

```bash
./vendor/bin/sail artisan key:generate
```

注意：

`key:generate` は既存の暗号化済みデータやセッションに影響する可能性がある。既に運用中の環境では、実行前に影響範囲を確認する。

## 8. ルートが見つからない

確認手順：

- `routes/web.php` を確認する
- ルート名の typo を確認する
- URLとroute nameを混同していないか確認する
- middlewareで弾かれていないか確認する
- route cacheが残っていないか確認する
- ルーティング設計は `docs/ROUTES.md` と照合する

```bash
./vendor/bin/sail artisan route:list
./vendor/bin/sail artisan route:list --name=items
./vendor/bin/sail artisan route:list --path=items
./vendor/bin/sail artisan route:clear
```

## 9. Class not found / Target class does not exist が出る

確認手順：

- Controller名、Model名、Form Request名、Policy名の typo を確認する
- namespace と `use` の指定を確認する
- ファイル名とクラス名が一致しているか確認する
- ルートに指定したControllerが存在するか確認する
- autoloadの再生成が必要か確認する

```bash
./vendor/bin/sail artisan route:list
./vendor/bin/sail composer dump-autoload
```

## 10. DB接続エラー

確認手順：

- Sailコンテナが起動しているか確認する
- MySQLコンテナが起動しているか確認する
- `.env` のDB設定を確認する
- `DB_HOST` はSail環境では通常MySQLサービス名になる
- 設定変更後はキャッシュをクリアする
- DB詳細設計は `docs/DATABASE.md` で扱う

```bash
./vendor/bin/sail ps
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail artisan config:clear
```

## 11. テーブルが存在しないエラーが出る

`SQLSTATE[42S02] Base table or view not found` などが出る場合は、DB接続自体ではなく、テーブル作成や接続先DBの問題を確認する。

確認手順：

- マイグレーションを実行しているか確認する
- `migrate:status` で該当マイグレーションが実行済みか確認する
- `.env` の `DB_DATABASE` が想定通りか確認する
- 接続先DBを間違えていないか確認する
- テーブル名の単数形・複数形を間違えていないか確認する
- `reviews`、`review_comments` など、DB設計と実装名が一致しているか確認する

```bash
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail artisan migrate
./vendor/bin/sail mysql
```

## 12. マイグレーションで詰まった

確認手順：

- `migrate:status` で状態を確認する
- `rollback` は直前のマイグレーションを戻す
- `migrate:fresh` は全テーブル削除になるため注意する
- 開発環境以外では `migrate:fresh` は原則使わない
- 実行前に接続先DBを確認する
- テーブル削除・カラム削除を伴う場合は特に注意する
- `reviews.user_id` や `review_comments.user_id` の nullable 方針など、DB設計とマイグレーション内容が一致しているか確認する

```bash
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan migrate:rollback
```

注意：`migrate:fresh` は全テーブルを削除するため、実行前に必ず対象DBを確認する。本番環境では原則禁止。

## 13. Seederが反映されない

確認手順：

- Seederクラスが存在するか確認する
- `DatabaseSeeder` から呼び出しているか確認する
- `db:seed` を実行しているか確認する
- `migrate --seed` と `db:seed` の違いを確認する
- 初期カテゴリなど、初期表示に必要なデータが投入されているか確認する

```bash
./vendor/bin/sail artisan db:seed
./vendor/bin/sail artisan migrate --seed
```

## 14. 画像アップロードで詰まった

確認手順：

- formに `enctype="multipart/form-data"` があるか確認する
- バリデーションでMIMEタイプ・サイズを確認しているか確認する
- ファイル名をランダム化しているか確認する
- `storage:link` が必要か確認する
- 公開してよい画像だけを公開する
- 個人情報性が高い画像は公開ディレクトリに直接置かない
- 詳細方針は `docs/SECURITY.md` と `docs/DEPLOYMENT.md` を参照する

```bash
./vendor/bin/sail artisan storage:link
```

## 15. 画像アップロード後に画像が表示されない

確認手順：

- 保存先パスが正しいか確認する
- DBに保存している値がファイル名なのか、パス付きなのか統一されているか確認する
- `storage:link` が必要な構成か確認する
- public配下の固定画像は `asset()`、storage公開画像は `Storage::url()` を使うなど、保存先に応じた参照方法になっているか確認する
- Bladeで画像パスを出力するときに、保存値をそのまま信用していないか確認する
- 公開してよい画像だけを表示しているか確認する

```bash
./vendor/bin/sail artisan storage:link
```

## 16. CSRFエラー・419 Page Expired が出る

確認手順：

- POST / PATCH / DELETE フォームに `@csrf` があるか確認する
- DELETEやPATCHでは `@method` があるか確認する
- セッション切れの可能性を確認する
- Breeze標準フォームとの違いを確認する
- 独自フォームではCSRFが漏れやすいため注意する
- `.env` の `APP_KEY` が設定されているか確認する
- `APP_URL` と実際にアクセスしているURLが大きくズレていないか確認する

```blade
<form method="POST" action="">
    @csrf
    @method('DELETE')
</form>
```

## 17. 認証・認可で弾かれる

確認手順：

- `auth` middlewareが付いているか確認する
- ログイン状態か確認する
- Policyを使う場合はPolicyが登録されているか確認する
- ルート、Controller、Policyの責務を分けて確認する
- レビュー削除は自分のレビューのみ許可しているか確認する
- レビュー削除導線は本人のレビュー一覧画面にのみ表示する方針になっているか確認する
- 会員退会はログインユーザー本人のみ実行できる設計になっているか確認する

```bash
./vendor/bin/sail artisan route:list
```

## 18. レビュー・評価投稿ができない

確認手順：

- `POST /items/{item}/reviews` のルートが存在するか確認する
- `auth` middlewareが付いているか確認する
- フォームに `@csrf` があるか確認する
- `body` と `rating` のname属性がControllerやForm Requestと一致しているか確認する
- `rating` が1〜5の範囲でバリデーションされているか確認する
- 1ユーザーにつき1作品1件までの制約に引っかかっていないか確認する
- `reviews` テーブルの `user_id`、`item_id`、`rating`、`body` が正しく保存対象になっているか確認する
- 投稿後に `items.rating` と `items.rating_count` を更新する処理があるか確認する

```bash
./vendor/bin/sail artisan route:list --name=reviews
./vendor/bin/sail artisan migrate:status
```

## 19. レビュー削除後に平均評価・評価件数が合わない

確認手順：

- レビュー削除時に本文と評価の両方を削除しているか確認する
- 削除後に対象作品の平均評価と評価件数を再計算しているか確認する
- `items.rating` と `items.rating_count` の更新処理があるか確認する
- レビュー削除と評価キャッシュ更新を同じトランザクション内で扱っているか確認する
- 削除対象レビューがログインユーザー本人のレビューか確認する
- レビューに紐づく `review_comments` の削除方針がDB設計と一致しているか確認する

```bash
./vendor/bin/sail artisan route:list --name=reviews
./vendor/bin/sail test --filter Review
```

## 20. レビュー返信コメントが表示されない・投稿できない

確認手順：

- `POST /reviews/{review}/replies` のルートが存在するか確認する
- ルート名が `reviews.replies.store` と一致しているか確認する
- `auth` middlewareが付いているか確認する
- フォームに `@csrf` があるか確認する
- `review_comments` テーブルを使用しているか確認する
- 初期移植フェーズでは1階層コメントのみ対応する方針になっているか確認する
- `parent_id` は初期移植フェーズでは常に `null` として扱っているか確認する
- 表示側で `comments()` リレーションを読み込んでいるか確認する

```bash
./vendor/bin/sail artisan route:list --name=reviews.replies
./vendor/bin/sail artisan migrate:status
```

## 21. 退会ユーザーの投稿者名表示で詰まった

確認手順：

- 会員退会時に `users` レコードを物理削除する方針になっているか確認する
- `reviews.user_id` と `review_comments.user_id` が nullable になっているか確認する
- 外部キーの削除時動作が、退会時に `user_id` を `null` にする設計と一致しているか確認する
- 投稿者ユーザーが存在しない場合に「匿名」と表示しているか確認する
- 匿名表示時もレビュー本文・レビュー返信コメント本文をBladeの `{{ }}` でエスケープしているか確認する
- 退会後のレビュー・レビュー返信コメントを編集不可として扱っているか確認する

```bash
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail test --filter Profile
```

## 22. フォーム送信後に登録・更新できない

確認手順：

- Modelの `$fillable` または `$guarded` が適切か確認する
- `$request->all()` をそのまま `create()` / `update()` に渡していないか確認する
- バリデーション済みデータを保存しているか確認する
- Requestのname属性とDBカラム名が一致しているか確認する
- ルートパラメータとControllerの引数が一致しているか確認する
- `role`、`user_id` など権限に関わる値をリクエスト値から直接更新していないか確認する

```bash
./vendor/bin/sail artisan route:list
./vendor/bin/sail test --filter Store
```

## 23. Bladeで Undefined variable が出る

確認手順：

- Controllerからviewに変数を渡しているか確認する
- `compact()` の変数名が正しいか確認する
- Blade側の変数名とController側の変数名が一致しているか確認する
- `foreach` 対象が `null` になっていないか確認する
- old値やエラー表示の変数が存在する前提になっていないか確認する

```php
return view('items.index', compact('items'));
```

## 24. XSS対策で迷った

確認手順：

- Bladeでは `{{ }}` を基本にする
- `{!! !!}` は原則使用しない
- JavaScriptへ値を渡す場合は `@json()` を検討する
- 作品タイトル、レビュー本文、レビュー返信コメント本文、ユーザー名、プロフィール文を信用しない
- 画像ファイル名や画像パスも信用しない
- 退会ユーザーを「匿名」と表示する場合も、本文表示はエスケープする
- 詳細は `docs/SECURITY.md` を参照する

## 25. Pintが動かない・コマンドが違う

このプロジェクトでは `./vendor/bin/sail pint --test` は使わない。

```bash
# 確認のみ（変更しない）
./vendor/bin/sail php ./vendor/bin/pint --test

# 自動整形
./vendor/bin/sail php ./vendor/bin/pint
```

## 26. PHPStan / Larastanでエラーが出る

このプロジェクトでは `./vendor/bin/phpstan analyse` を使う。

確認手順：

- エラー文を読んで、型・リレーション・未定義プロパティを確認する
- nullableなリレーションを考慮しているか確認する
- `reviews.user_id` や `review_comments.user_id` が `null` になり得る設計を型で考慮しているか確認する
- PHPStanは実行時の動作を完全に保証するものではない
- テストと合わせて確認する

```bash
./vendor/bin/phpstan analyse
```

## 27. テストが失敗する

確認手順：

- 失敗したテスト名を確認する
- 期待値と実際の値を確認する
- `.env.testing` の有無やDB接続先は後続で検討する
- 認証が必要な画面ではログイン状態をテストで作る必要がある
- まずはFeatureテスト中心で考える
- レビュー削除、退会、評価キャッシュ更新など、データ更新を伴う処理はDB状態も確認する

```bash
./vendor/bin/sail test
./vendor/bin/sail test --filter test_example
```

## 28. npm / Viteビルドで失敗する

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

## 29. キャッシュが原因で変更が反映されない

確認手順：

- route / config / view cache の可能性を確認する
- `.env` を変更した場合はconfig cacheを確認する
- ルートを変更した場合はroute cacheを確認する
- Bladeを変更した場合はview cacheを確認する
- ローカルでは `optimize:clear` を試す
- 本番では実行タイミングに注意する

```bash
./vendor/bin/sail artisan optimize:clear
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan view:clear
```

## 30. Gitで関係ないファイルが混ざった

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

## 31. 未追跡ファイルのdiffが出ない

確認手順：

- 未追跡ファイルは通常 `git diff ファイル名` では表示されない
- 内容確認する場合は `git add -N ファイル名` を使う方法がある
- 最終的にコミットする場合は通常の `git add ファイル名` が必要
- 誤って不要ファイルを追加しないよう注意する

```bash
git add -N docs/TROUBLESHOOTING.md
git diff -- docs/TROUBLESHOOTING.md
git add docs/TROUBLESHOOTING.md
```

## 32. コミット前に確認すること

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

## 33. PR前確認

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

## 34. 解決しない場合

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

## 35. 関連ドキュメント

- `docs/COMMANDS.md`
- `docs/DEVELOPMENT_FLOW.md`
- `docs/REQUIREMENTS.md`
- `docs/FEATURES.md`
- `docs/SCREEN_TRANSITIONS.md`
- `docs/ROUTES.md`
- `docs/DATABASE.md`
- `docs/SECURITY.md`
- `docs/DEPLOYMENT.md`
- `README.md`
