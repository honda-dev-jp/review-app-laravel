# 実装計画

## このドキュメントの目的

このドキュメントでは、映画レビューアプリ Laravel移植版の初期移植フェーズにおける実装順序、Pull Request分割、確認項目を整理する。

`docs/FEATURES.md` は機能一覧と優先度を整理するドキュメントであり、このドキュメントでは実際にどの順番で実装するかを扱う。

DB詳細設計は `docs/DATABASE.md`、開発フローやIssue運用は `docs/DEVELOPMENT_FLOW.md`、コマンド一覧は `docs/COMMANDS.md` に整理する。

## 前提

初期移植フェーズでは、既存スクラッチ版の最低限機能をLaravel 10へ移植する。

主な前提は以下の通り。

- Laravel 10を使用する
- Laravel Sailをローカル開発環境として使用する
- MySQLを使用する
- Laravel Breezeによる認証機能を利用する
- DB基盤、モデル、リレーションを先に整えてから画面実装へ進む
- mainブランチへ直接pushせず、作業ブランチからPull Request経由でmainへマージする
- 必要に応じてIssueを作成し、作業内容、判断待ち、修正予定を整理する
- コミットは1目的1コミットを基本とする

## IssueとPull Requestの関係

作業内容が明確な場合は、必要に応じてIssueを作成してから作業ブランチを作成する。

Issueは以下の用途で使用する。

- 実装予定の整理
- 設計確認
- 判断待ち項目の記録
- ドキュメント修正予定の整理
- Pull Requestとの紐づけ

基本の流れは以下とする。

```text
Issue作成
  ↓
作業ブランチ作成
  ↓
実装・ドキュメント修正
  ↓
コミット
  ↓
Pull Request作成
  ↓
IssueとPull Requestを紐づけ
  ↓
レビュー・確認
  ↓
mainへマージ
```

Pull Request本文では、必要に応じて関連Issueを `Closes #番号` または `Refs #番号` で紐づける。

ただし、Issue番号はGitHub上で作成してから確定するため、このドキュメントでは固定のIssue番号は記載しない。

## 実装フェーズ

| Phase | 内容 | 主な対象 |
|---|---|---|
| Phase 0 | DB基盤準備 | マイグレーション、Seeder |
| Phase 1 | 設計確認・ブランチ作成 | Issue確認、作業ブランチ作成 |
| Phase 2 | DB基盤・共通機能 | Model、Relation、作品一覧、作品詳細 |
| Phase 3 | 会員機能 | Breeze確認、プロフィール、レビュー投稿、削除、返信、退会 |
| Phase 4 | 品質確認 | route:list、migrate:fresh --seed、Pint、PHPStan、テスト、Viteビルド |

## Pull Request分割方針

初期移植フェーズでは、実装単位を小さく分け、確認しやすいPull Requestにする。

一人開発のため、必要に応じて隣接するPull Requestをまとめてもよい。

特に、DBスキーマとEloquentモデルは密接に関係するため、実装状況によっては同一Pull Requestにまとめることも検討する。

### PR番号の扱い

このドキュメント内の `PR1`、`PR2`、`PR3` などの表記は、初期移植フェーズにおける実装順序を示すラベルとして扱う。

GitHub上で作成される実際のPull Request番号とは一致しない場合がある。

途中でドキュメント修正PRや緊急修正PRを挟んだ場合でも、このドキュメント内のPR番号は原則として変更しない。

## 実装順序

### PR1. DBマイグレーション + Seeder

DB基盤を作成する。

主な作業内容:

- 初期移植フェーズで必要なマイグレーションを作成する
- `users` テーブルをLaravel Breeze前提で調整する
- `categories` テーブルを作成する
- `items` テーブルを作成する
- `reviews` テーブルを作成する
- `review_comments` テーブルを作成する
- `CategorySeeder` で `未定義` カテゴリを登録する
- 作品一覧・作品詳細の確認に必要な初期作品データの扱いを検討する

確認項目:

- `migrate` が成功すること
- `migrate:fresh --seed` が成功すること
- 初期カテゴリが投入されること
- 作品一覧・作品詳細の実装に必要なデータ方針が明確になっていること

### PR2. Eloquentモデル + リレーション

DB設計に対応するEloquentモデルとリレーションを作成する。

主な作業内容:

- `User` モデルのリレーションを整理する
- `Category` モデルを作成する
- `Item` モデルを作成する
- `Review` モデルを作成する
- `ReviewComment` モデルを作成する
- `$fillable` または `$guarded` を適切に設定する
- nullableなリレーションを考慮する

主なリレーション:

- User has many Reviews
- User has many ReviewComments
- Category has many Items
- Item belongs to Category
- Item has many Reviews
- Review belongs to User
- Review belongs to Item
- Review has many ReviewComments
- ReviewComment belongs to User
- ReviewComment belongs to Review
- ReviewComment belongs to parent comment
- ReviewComment has many child comments

初期移植フェーズでは、`review_comments.parent_id` は将来拡張用として保持するが、常に `null` として扱う。

### PR3. 作品一覧・詳細表示

認証不要の共通画面を実装する。

主な作業内容:

- `ItemController@index` を作成する
- `ItemController@show` を作成する
- 作品一覧画面を作成する
- 作品詳細画面を作成する
- 作品一覧のページネーションを実装する
- 作品の平均評価と評価件数を表示する

確認項目:

- ゲストでも作品一覧を閲覧できること
- ゲストでも作品詳細を閲覧できること
- ページネーションが動作すること
- 作品が存在しない場合の表示が破綻しないこと

### PR4. レビュー表示・レビュー返信表示

作品詳細画面にレビューとレビュー返信を表示する。

主な作業内容:

- 作品詳細画面でレビュー一覧を表示する
- レビューに紐づくレビュー返信コメントを表示する
- 必要に応じてEager Loadを使用する
- 退会済みユーザーの投稿者名を匿名表示する
- レビュー本文・レビュー返信本文をBladeでエスケープして表示する

確認項目:

- レビューが作品詳細画面に表示されること
- レビュー返信がレビューに紐づいて表示されること
- 投稿者が存在しない場合に匿名表示されること
- N+1が発生しにくい取得になっていること

### PR5. Breeze認証確認

Laravel Breezeの認証機能を確認し、既存の画面遷移方針に合わせて認証後の遷移先を調整する。

主な作業内容:

- 会員登録ができることを確認する
- ログインができることを確認する
- ログアウトができることを確認する
- ログイン後・会員登録後の遷移先を、ダッシュボードではなく作品一覧画面へ調整する
- `auth` ミドルウェアの対象画面を確認する
- 会員登録画面・ログイン画面のUI調整は、後続PRまたは別Issueで対応する

確認項目:

- 会員登録できること
- ログインできること
- ログアウトできること
- ログイン後・会員登録後に作品一覧画面へ遷移すること
- ログイン必須画面に未ログインでアクセスした場合、適切に制御されること
- 会員登録画面・ログイン画面は、現時点ではBreezeデフォルトUIをベースとしていること

補足:

- 認証処理はLaravel Breeze標準を利用する
- 映画レビューアプリ向けの認証画面UI調整は、後続PRまたは別Issueで対応する

### PR6. プロフィール編集

ログイン必須画面の実装に慣れるため、プロフィール編集を先に実装する。

主な作業内容:

- Breeze標準のプロフィール編集機能を確認する
- 必要に応じてプロフィール項目を調整する
- `profile` を更新できるようにする
- `avatar_path` の扱いを検討する
- 認証済みユーザー本人のみ更新できるようにする

確認項目:

- ログインユーザーが自分のプロフィールを編集できること
- 他ユーザーのプロフィールを編集できないこと
- バリデーションが適切に動作すること

### PR7. レビュー投稿・削除 + 評価キャッシュ更新

レビュー本文と評価の投稿、削除、評価キャッシュ更新を実装する。

主な作業内容:

- `ReviewController@store` を作成する
- `ReviewController@destroy` を作成する
- `StoreReviewRequest` を作成する
- `ReviewPolicy` を作成する
- `ItemRatingService` を作成する
- レビュー投稿時に `items.rating` / `items.rating_count` を更新する
- レビュー削除時に `items.rating` / `items.rating_count` を再計算する
- レビュー作成・削除と評価キャッシュ更新を同一トランザクション内で実行する

確認項目:

- ログインユーザーがレビューと評価を投稿できること
- 1ユーザーにつき1作品1件までの制約が守られること
- 自分のレビューのみ削除できること
- レビュー削除後に平均評価と評価件数が正しく再計算されること
- 作品一覧・作品詳細の評価表示が正しいこと

### PR8. 本人レビュー一覧 + ページネーション

ログインユーザー本人のレビュー一覧画面を実装する。

主な作業内容:

- `ReviewController@mine` を作成する
- 本人のレビュー一覧画面を作成する
- ページネーションを実装する
- レビュー削除導線を本人レビュー一覧画面に配置する

確認項目:

- 自分のレビューのみ表示されること
- 他ユーザーのレビューが混ざらないこと
- ページネーションが動作すること
- レビュー削除導線が本人レビュー一覧画面にのみ表示されること

### PR9. レビュー返信投稿

レビューへの返信投稿を実装する。

主な作業内容:

- `ReviewCommentController@store` を作成する
- `StoreReviewCommentRequest` を作成する
- レビュー返信投稿フォームを作成する
- 初期移植フェーズでは1階層コメントのみ扱う
- `parent_id` は常に `null` として保存する

確認項目:

- ログインユーザーがレビューへ返信できること
- 未ログインユーザーは返信できないこと
- 返信本文のバリデーションが動作すること
- 投稿後、作品詳細画面に返信が表示されること

### PR10. 会員退会

会員退会機能を実装する。

主な作業内容:

- 会員退会確認画面を作成する
- `ProfileController@destroy` を調整する
- ログインユーザー本人のみ退会できるようにする
- 退会時に `users` レコードを物理削除する
- 退会後のレビュー・レビュー返信コメントは本文を残し、投稿者を匿名表示する
- 退会後のレビュー・レビュー返信コメントは編集不可として扱う

確認項目:

- ログインユーザー本人のみ退会できること
- 退会後にレビュー本文が残ること
- 退会後にレビュー返信本文が残ること
- 退会後の投稿者名が匿名表示されること
- 退会後にログインできないこと

## DB実装時の補足方針

DB実装時は、`docs/DATABASE.md` を正とする。

ただし、実装前に以下の内容を `docs/DATABASE.md` へ反映するか確認する。

### 初期データ

初期移植フェーズでは、`CategorySeeder` により `未定義` カテゴリを登録する。

作品一覧・作品詳細の動作確認には作品データが必要になるため、以下のどちらかを検討する。

- `ItemSeeder` で最低限の初期作品データを登録する
- 既存スクラッチ版から最低限の検証用データを移行する

既存DBデータの本格移行は初期移植フェーズでは対象外とする。

### unique制約

以下の一意制約を検討する。

- `categories.name`
- `reviews(user_id, item_id)`

`reviews(user_id, item_id)` は、1ユーザーにつき1作品1件までのレビュー制約を保証するために使用する。

`reviews.user_id` は会員退会時に `null` になり得る。
MySQLではユニーク制約に含まれる `null` は複数許容されるため、退会済みユーザーのレビューは重複投稿制御の対象外とする。

### index

以下のindexを検討する。

- `reviews(item_id, created_at)`
- `review_comments(review_id, created_at)`
- `review_comments(parent_id)`

`reviews(item_id, created_at)` は、作品詳細画面でレビューを取得する用途を想定する。

`review_comments(review_id, created_at)` は、レビューに紐づく返信コメントを取得する用途を想定する。

`review_comments(parent_id)` は、将来の階層返信に備えて用意する。

### 外部キー削除方針

以下の削除方針を検討する。

| 対象 | 削除時の動作 | 理由 |
|---|---|---|
| `reviews.user_id` | `nullOnDelete` | 会員退会後もレビュー本文を残し、投稿者を匿名表示するため |
| `review_comments.user_id` | `nullOnDelete` | 会員退会後も返信本文を残し、投稿者を匿名表示するため |
| `reviews.item_id` | `cascadeOnDelete` | 作品削除時に紐づくレビューを残さないため |
| `review_comments.review_id` | `cascadeOnDelete` | レビュー削除時に紐づく返信コメントを残さないため |
| `review_comments.parent_id` | `nullOnDelete` または `cascadeOnDelete` を後続フェーズで検討 | 初期移植フェーズでは常に `null` として扱うため |

### rating制約

`reviews.rating` は1〜5の整数とする。

初期移植フェーズでは、DB CHECK制約は見送り、Form RequestとFeatureテストで保証する。

理由は、Laravel 10のマイグレーションでCHECK制約を扱う場合、raw SQLが必要になり、初期移植フェーズでは実装・保守の負担が増えるため。

将来的にDB側でも厳密に保証したい場合は、MySQLバージョン、Laravelマイグレーション方針、運用コストを確認したうえで再検討する。

### 評価キャッシュ

`reviews.rating` を評価データの正とする。

`items.rating` と `items.rating_count` は表示高速化用のキャッシュとして扱う。

レビュー投稿・削除時は、対象作品の平均評価と評価件数を再計算する。

キャッシュ更新処理は、レビュー投稿・削除処理と同じトランザクション内で行う。

同時更新時の競合やロック戦略は、実装・負荷状況を確認してから後続フェーズで検討する。

## Service / FormRequest / Policy 方針

### Service

評価キャッシュ更新処理は、専用Serviceに分離する。

想定ファイル:

```text
app/Services/ItemRatingService.php
```

想定メソッド:

```php
refresh(Item $item): void
```

呼び出し元:

- `ReviewController@store`
- `ReviewController@destroy`

ObserverやModel Eventは、暗黙的な実行により処理の流れが追いにくくなるため、初期移植フェーズでは使用しない。

### Form Request

バリデーションが増える書き込み処理では、Form Requestへの分離を検討する。

候補:

- `StoreReviewRequest`
- `StoreReviewCommentRequest`
- `UpdateProfileRequest`

導入タイミングは、各Controllerの書き込みアクション実装と同じPull Request内とする。

### Policy

レビュー削除など、特定モデルに対する本人確認が必要な操作ではPolicyの利用を優先する。

候補:

- `ReviewPolicy`
- `UserPolicy`

レビュー削除では、以下のような方針とする。

```php
public function delete(User $user, Review $review): bool
{
    return $user->id === $review->user_id;
}
```

Laravel 10では、ModelとPolicyを規約通りに配置した場合、Policyの自動検出を利用できる。

動作が不明確な場合や明示性を優先する場合は、`AuthServiceProvider` で明示登録する。

## Featureテスト候補

実装が進んだ段階で、以下のFeatureテストを検討する。

- ログインユーザーはレビューを投稿できる
- 同一作品への2件目のレビュー投稿は拒否される
- レビュー削除後、`items.rating` / `items.rating_count` が再計算される
- 会員退会後、`reviews.user_id` が `null` になる
- 会員退会後もレビュー本文が残る
- 会員退会後、`review_comments.user_id` が `null` になる
- 会員退会後もレビュー返信本文が残る
- 他ユーザーのレビューを削除できない
- 未ログインユーザーはレビュー・評価投稿できない
- 未ログインユーザーはレビュー返信投稿できない

## PRごとの確認項目

各Pull Requestでは、変更内容に応じて以下を確認する。

### 共通確認

```bash
git status
git diff
git diff --staged
```

### Laravel確認

```bash
./vendor/bin/sail artisan route:list
./vendor/bin/sail artisan migrate:status
```

### DB再構築確認

マイグレーションやSeederを変更した場合は、開発環境で以下を確認する。

```bash
./vendor/bin/sail artisan migrate:fresh --seed
```

`migrate:fresh` は全テーブル削除を伴うため、開発環境以外では原則使用しない。

### 品質確認

```bash
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail test
./vendor/bin/sail php ./vendor/bin/phpstan analyse
./vendor/bin/sail npm run build
```

## 注意点

- Pull Request数は実装状況に応じて調整してよい
- DB基盤とModel実装は、必要に応じて同一Pull Requestにまとめてもよい
- 実装前に、要件、機能、画面遷移、DB設計、ルーティング、セキュリティ方針を確認する
- DB設計の修正は影響範囲が大きいため、実装前に確定させる
- 評価キャッシュ更新はレビュー投稿・削除処理と同一トランザクション内で行う
- Service方式では呼び忘れリスクがあるため、Featureテストで担保する
- SQLiteでFeatureテストを実行する場合は、MySQLとの型・制約挙動差に注意する
- `migrate:fresh` は開発環境以外では原則使用しない

## 関連ドキュメント

- `README.md`
- `docs/DEVELOPMENT_FLOW.md`
- `docs/COMMANDS.md`
- `docs/REQUIREMENTS.md`
- `docs/FEATURES.md`
- `docs/SCREEN_TRANSITIONS.md`
- `docs/DATABASE.md`
- `docs/ROUTES.md`
- `docs/SECURITY.md`
- `docs/TROUBLESHOOTING.md`
- `docs/DEPLOYMENT.md`
