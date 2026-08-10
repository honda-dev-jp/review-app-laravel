# TROUBLESHOOTING.md

## 1. このドキュメントの目的

Laravel移植作業中に発生しやすい問題の確認手順をまとめる。

いきなり修正せず、エラーメッセージ、現在のブランチ、未コミット変更、実行したコマンド、直前の変更内容を確認し、原因を切り分けてから対応する。

- DB詳細設計の判断は `docs/DATABASE.md` で扱う
- コマンド一覧は `docs/COMMANDS.md` を参照する
- GitHubの正常系運用は `docs/GITHUB_WORKFLOW.md` を参照する
- セキュリティ方針は `docs/SECURITY.md` を参照する
- デプロイ方針は `docs/DEPLOYMENT.md` を参照する

この文書に記載するコマンドは、人間がローカル環境で実行することを前提とする。`curl`や`rm`を含むコマンドの掲載はClaude Codeへの実行権限付与を意味せず、Claude Codeの権限は既存のHook、settings、allowlistに従う。

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
- `.env` の `APP_PORT`、`FORWARD_DB_PORT`、`PHPMYADMIN_PORT`、`FORWARD_MAILPIT_PORT`、`FORWARD_MAILPIT_DASHBOARD_PORT` が他サービスと競合していないか確認する
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
./vendor/bin/sail artisan route:list --path=password
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

- `POST /reviews/{review}/comments` のルートが存在するか確認する
- ルート名が `reviews.comments.store` と一致しているか確認する
- `auth` middlewareが付いているか確認する
- フォームに `@csrf` があるか確認する
- `review_comments` テーブルを使用しているか確認する
- 初期移植フェーズでは1階層コメントのみ対応する方針になっているか確認する
- `parent_id` は初期移植フェーズでは常に `null` として扱っているか確認する
- 表示側で `comments()` リレーションを読み込んでいるか確認する

```bash
./vendor/bin/sail artisan route:list --name=reviews.comments
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

このプロジェクトでは `./vendor/bin/sail php ./vendor/bin/phpstan analyse` を使う。

確認手順：

- エラー文を読んで、型・リレーション・未定義プロパティを確認する
- nullableなリレーションを考慮しているか確認する
- `reviews.user_id` や `review_comments.user_id` が `null` になり得る設計を型で考慮しているか確認する
- PHPStanは実行時の動作を完全に保証するものではない
- テストと合わせて確認する

```bash
./vendor/bin/sail php ./vendor/bin/phpstan analyse
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

コミット前の正常系チェックリストと実行順序は、[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)を参照する。

想定外のファイルや差分が見つかった場合は、この文書の「Gitで関係ないファイルが混ざった」で切り分ける。原因を確認する前にコミットへ進まない。

## 33. PR前確認

通常PRと同期PRの正常系チェックリスト、CI成功確認、Merge commit方式の確認は、[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)を参照する。

差分、CI、BaseとHead、Issue参照、マージ方式のいずれかが想定と異なる場合は、PRの作成やマージを進めず、該当する異常系で切り分ける。

## 34. `git pull --ff-only`が失敗した

`git pull --ff-only`が失敗した場合は、ローカルとリモートの履歴がfast-forwardできない状態である可能性がある。

確認手順：

- 現在のブランチが`main`、`develop`、作業ブランチのどれか確認する
- 未コミット変更がないか確認する
- pull対象のブランチとリモート名を確認する
- `git branch -a`と限定した`git log`で位置を確認する
- 他の作業やPRによってリモートが進んでいないか確認する

```bash
git status --short
git branch --show-current
git branch -a
git log --oneline --decorate -5
```

非fast-forward merge、rebase、reset、force pushへ切り替えず停止する。

## 35. `git merge --ff-only`が失敗した

同期PR後の`git merge --ff-only main`が失敗した場合は、`develop`が最新の`main`へfast-forwardできるという前提が成立していない。

確認手順：

- 同期PRがMerge commit方式でマージされているか確認する
- 直前の`git pull --ff-only origin main`が成功したか確認する
- ローカル`main`と`origin/main`の位置を確認する
- ローカル`develop`と`origin/develop`の位置を確認する
- 同期PR後に`develop`へ別の変更が入っていないか確認する

追加のmerge、非fast-forward merge、rebase、reset、force pushを行わず停止する。履歴構造を確認してから、人間が対応方針を決める。

## 36. `git push origin develop`が拒否された

同期PR後のfast-forward同期でpushが拒否された場合は、次を確認する。

- `git merge --ff-only main`が成功しているか
- push先が`develop`であるか
- `origin/develop`が別の変更で進んでいないか
- Rulesetや権限によって直接pushが拒否されていないか
- 通常の作業コミットが混入していないか

force push、reset、rebase、非fast-forward mergeで回避しない。リモート状態またはGitHub設定を確認できるまで停止する。

## 37. `git branch -d`が失敗した

`git branch -d`が失敗した場合は、作業ブランチが現在の`develop`へマージ済みと判定できない、対象ブランチが間違っている、または現在そのブランチにいる可能性がある。

確認手順：

- GitHub上で対象の通常PRが`develop`へマージ済みか確認する
- ローカル`develop`を`git pull --ff-only origin develop`で最新化済みか確認する
- 現在のブランチと削除対象名を確認する
- 作業ブランチに未反映のコミットがないか確認する

標準手順で`git branch -D`へ切り替えない。削除してよい根拠を確認できるまでブランチを残す。

## 38. 同期PRでMerge commit以外を選択してしまった

同期PRでSquash mergeまたはRebase mergeを選択した場合は、`develop`から`main`へのfast-forward同期の前提が崩れている可能性がある。

- `git merge --ff-only main`や`git push origin develop`へ進まない
- 即時にreset、rebase、force pushで履歴を書き換えない
- Merge commitを作り直す目的で追加の非fast-forward mergeを行わない
- 対象PR、選択したマージ方式、`main`と`develop`の位置を記録する
- 影響を確認し、人間が別PRや後続対応の要否を判断する

誤操作を隠すための履歴改変は行わない。

## 39. `main`と`develop`の位置が想定と異なる

同期後の最終確認で、ローカルまたはリモート参照が同じ同期PRのMerge commitを指していない場合は同期未完了として扱う。

```bash
git status --short
git branch -a
git log --oneline --decorate -5
```

次を確認する。

- ローカル`main`と`develop`の位置
- `origin/main`と`origin/develop`の位置
- 同期PRのMerge commit
- 同期PR後に追加されたコミットの有無
- 未コミット変更の有無

原因が分かるまで追加のmergeやpushを行わない。最後の履歴確認を省略して同期完了と判断しない。

## 40. CIが失敗している

通常PRまたは同期PRのGitHub Actionsが失敗中、未完了、または結果を確認できない場合はマージしない。

確認手順：

- 失敗したjobとstepを確認する
- Laravel Pint、PHPStan / Larastan、Vite build、PHPUnit、Ruff lint、Ruff format check、Python unittestのどこで失敗したか確認する
- ローカルで同等の確認を実行済みか確認する
- 差分に起因する失敗と、一時的なrunner・外部要因を区別する
- 再実行する場合も、失敗内容を確認せず繰り返さない

CIが実行されることと、Rulesetでrequired status checksとして強制されることは別である。required status checksが未設定でも、運用上はCIがすべて成功するまでマージしない。

### Python CIのRuff lintが失敗する

Issue #95の初回CIでは、`Run Ruff lint`で次のE721を1件検出した。

```text
E721 Use `is` and `is not` for type comparisons
```

lint失敗時は、次の順で切り分ける。

1. エラーコード、対象ファイル、対象行を確認する
2. `--fix`で自動修正せず、対象ロジックと既存のWhyコメントを確認する
3. 既存ロジックを維持できる必要最小限の修正だけを行う
4. Python回帰テストを再実行する
5. `git diff --check`と対象ファイルの差分を確認する
6. 修正後のCIを再実行する

```bash
python3 -m unittest discover -s .claude/hooks/tests -p "test_*.py"
git diff --check
git diff -- 対象ファイル
```

今回のE721は1行だけを修正し、Python回帰テスト成功後の再CIでRuff lintが成功した。

### Python CIのRuff format checkが失敗する

Issue #95の初回CIでは、lint修正後の`Run Ruff format check`で次の4ファイルが未整形として検出された。

```text
Would reformat:
.claude/helpers/github_global_advisories.py
.claude/hooks/pre_tool_use.py
.claude/hooks/tests/test_github_global_advisories.py
.claude/hooks/tests/test_pre_tool_use.py

4 files would be reformatted
```

`Would reformat:`に表示された対象を確認し、先に意図した実装差分をcommitしてworking treeをcleanにしてからformatterを適用する。formatter差分と実装差分、Hook/helperのsecurity policy変更を同じコミットへ混在させない。

formatter適用後は`git diff --stat`と`git diff`で機械的な整形だけであることを確認する。Hook/helperはsecurity-sensitiveなため、見た目だけで安全と判断せず、Ruffの再確認とPython回帰テストまで実行する。

### ローカルにRuffがない場合の一時standalone利用

まず、ローカルでRuffが利用可能か確認する。

```bash
command -v ruff
```

pathが表示された場合だけversionを確認する。

```bash
ruff --version
```

Issue #95対応時のローカル環境にはRuffが導入されていなかった。現行方針どおり、Ruffをproject dependencyへ追加せず、次も使用しない。

```text
pip install
pipx install
uv / uvx
venv
requirements.txt
requirements-dev.txt
```

実測環境はLinux x86_64で、CIと同じRuff 0.15.21の公式GitHub Release assetを`/tmp`配下だけへ一時配置した。次の手順は`uname -m`が`x86_64`である環境を対象とする。

Ruff versionは`.github/workflows/ci.yml`の指定を正本とし、CI側を更新した場合は、この手順のRelease URL、archive名、version確認時の期待値も同じversionへ読み替える。

```bash
uname -m
```

実測値:

```text
x86_64
```

固定versionのstandalone archiveを`/tmp`へ取得して展開する。binaryをrepository配下へ保存したり、PATHへ常設したり、Git管理へ追加したりしない。

```bash
curl -L \
  https://github.com/astral-sh/ruff/releases/download/0.15.21/ruff-x86_64-unknown-linux-gnu.tar.gz \
  -o /tmp/ruff-0.15.21.tar.gz
```

```bash
tar -xzf /tmp/ruff-0.15.21.tar.gz -C /tmp
```

```bash
/tmp/ruff-x86_64-unknown-linux-gnu/ruff --version
```

期待値:

```text
ruff 0.15.21
```

versionがCIと一致しない場合はformatterを実行しない。

### Ruff formatterの対象限定実行

Issue #95で未整形と判定された4ファイルだけへformatterを適用する。repository全体へ適用しない。

```bash
/tmp/ruff-x86_64-unknown-linux-gnu/ruff format \
  .claude/helpers/github_global_advisories.py \
  .claude/hooks/pre_tool_use.py \
  .claude/hooks/tests/test_github_global_advisories.py \
  .claude/hooks/tests/test_pre_tool_use.py
```

適用後は、同じ4ファイルのformat、Claude Code用Pythonのlint、既存回帰テストを確認する。

```bash
/tmp/ruff-x86_64-unknown-linux-gnu/ruff format --check \
  .claude/helpers/github_global_advisories.py \
  .claude/hooks/pre_tool_use.py \
  .claude/hooks/tests/test_github_global_advisories.py \
  .claude/hooks/tests/test_pre_tool_use.py
```

実測結果:

```text
4 files already formatted
```

```bash
/tmp/ruff-x86_64-unknown-linux-gnu/ruff check \
  .claude/helpers/github_global_advisories.py \
  .claude/hooks/pre_tool_use.py \
  .claude/hooks/tests/
```

実測結果:

```text
All checks passed!
```

```bash
python3 -m unittest discover -s .claude/hooks/tests -p "test_*.py"
```

実測結果:

```text
Ran 77 tests
OK
```

最後に、変更対象、差分内容、空白エラーを確認する。

```bash
git status --short
git diff --stat
git diff
git diff --check
```

ローカル確認後はformatter差分を独立したコミットとして確定し、作業ブランチをpushする。push後にGitHub Actions CIが再実行され、Python品質チェックがすべて成功したことを確認する。

### 一時Ruffの後片付け

作業、差分レビュー、回帰確認、GitHub Actions CIの成功確認がすべて完了したあとに限り、一時展開先とarchiveを削除する。

```bash
rm -rf /tmp/ruff-x86_64-unknown-linux-gnu
rm -f /tmp/ruff-0.15.21.tar.gz
```

## 41. Git異常時の共通停止原則

GitまたはGitHubの状態が想定と異なる場合は、次を守る。

- force pushしない
- resetで履歴を書き換えない
- 非fast-forward mergeへ切り替えない
- rebaseへ切り替えない
- 原因確認前に作業を継続しない

## 42. 解決しない場合

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

## 43. 関連ドキュメント

- `docs/GITHUB_WORKFLOW.md`
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

## 44. パスワードリセットメールがMailpitに届かない

確認手順：

1. `mailpit` コンテナが起動しているか確認する

```bash
./vendor/bin/sail ps
```

2. ローカル環境の `.env` で次を確認する

```text
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

`.env` の実値はドキュメントへ転載しない。

3. `.env` を変更した場合は設定キャッシュをクリアする

```bash
./vendor/bin/sail artisan config:clear
```

4. パスワードリセット関連ルートを確認する

```bash
./vendor/bin/sail artisan route:list --path=password
```

5. ログイン中は `/forgot-password` へアクセスできないため、ログアウトしてから再確認する

6. 同一メールアドレスへの再送は60秒間制限される

7. 未登録メールアドレスではエラーとなり、メールは送信されない

8. Mailpit Web UIを確認する

```text
http://localhost:8025
```

9. ホスト側ポートを変更している場合は、`.env` の `FORWARD_MAILPIT_DASHBOARD_PORT` を確認する
