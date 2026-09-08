# ルーティング設計

## このドキュメントの目的

このドキュメントでは、映画レビューアプリ Laravel移植版のルーティング設計を整理する。

画面遷移で整理した画面に対して、URL、HTTPメソッド、ルート名、Controller、認証要否を定義する。

初期移植フェーズでは、共通画面、ゲスト画面、会員画面を中心に整理する。

管理者画面はMVP1では対象外とし、MVP2で管理者画面モック、登録済み作品一覧、TMDB検索からの1作品登録に必要なルートを段階的に設計する。

## ルーティング方針

- URLはLaravelの慣習に合わせて分かりやすくする
- ルート名は `items.index` のようにドット区切りで定義する
- 共通画面は認証不要とする
- 未ログイン限定画面は `guest` ミドルウェアで保護する
- 会員機能は `auth` ミドルウェアで保護する
- メール認証必須の会員機能は `auth` と `verified` ミドルウェアで保護する
- 認可は処理の性質に応じてLaravel標準のGate、PolicyまたはController側の条件分岐を使用する
- POST、PATCH、DELETEなど状態変更を伴う処理ではCSRF保護を前提とする

## 主要な実装済みルート一覧

### 共通画面

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| GET | `/` | `home` | `ItemController@index` | 不要 | トップページとして作品一覧を表示する |
| GET | `/items` | `items.index` | `ItemController@index` | 不要 | 作品一覧を表示する |
| GET | `/items/{item}` | `items.show` | `ItemController@show` | 不要 | 作品詳細、レビュー、レビュー返信、星評価を表示する |

### ゲスト画面

Laravel Breezeの認証ルートを使用する。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| GET | `/register` | `register` | Breeze標準 | 未ログイン限定（`guest`） | 会員登録画面を表示する |
| POST | `/register` | なし | Breeze標準 | 未ログイン限定（`guest`） | 会員登録処理を行う |
| GET | `/login` | `login` | Breeze標準 | 未ログイン限定（`guest`） | ログイン画面を表示する |
| POST | `/login` | なし | Breeze標準 | 未ログイン限定（`guest`） | ログイン処理を行う |
| GET | `/forgot-password` | `password.request` | Breeze標準 | 未ログイン限定（`guest`） | パスワードリセット申請画面を表示する |
| POST | `/forgot-password` | `password.email` | Breeze標準 | 未ログイン限定（`guest`） | パスワードリセットメールを送信する |
| GET | `/reset-password/{token}` | `password.reset` | Breeze標準 | 未ログイン限定（`guest`） | パスワード再設定画面を表示する |
| POST | `/reset-password` | `password.store` | Breeze標準 | 未ログイン限定（`guest`） | 新しいパスワードを保存する |

これらのルートは `routes/auth.php` の `guest` ミドルウェア配下にあり、ログイン済みユーザーがアクセスした場合は `/` へリダイレクトされる。

### 会員画面

会員画面は `auth` ミドルウェアで保護する。アカウント画面、本人のレビュー一覧、レビュー削除、会員退会はメール未認証でも利用できる。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| GET | `/profile` | `profile.edit` | `ProfileController@edit` | 必要 | アカウント画面を表示する |
| PATCH | `/profile` | `profile.update` | `ProfileController@update` | 必要 | アカウント情報更新処理を行う |
| PUT | `/password` | `password.update` | `Auth\PasswordController@update` | 必要 | パスワード更新処理を行う |
| DELETE | `/profile` | `profile.destroy` | `ProfileController@destroy` | 必要 | アカウント画面の確認モーダルから会員退会処理を行う |
| GET | `/my-reviews` | `reviews.mine` | `ReviewController@mine` | 必要 | 本人のレビュー一覧を表示する |

### 管理者画面

管理画面は既存のLaravel Breeze認証を使用し、`auth` と `can:access-admin-panel` ミドルウェアで保護する。メール認証は要求しない。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| GET | `/admin` | `admin.dashboard` | `Route::view` | 必要 + 管理者認可 | 管理画面ダッシュボードを表示する |

### レビュー機能

レビュー・評価投稿は `auth` と `verified` ミドルウェアで保護する。レビュー削除は `auth` ミドルウェアで保護し、メール未認証でも自分のレビューを削除できる。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| POST | `/items/{item}/reviews` | `reviews.store` | `ReviewController@store` | 必要 + メール認証必須 | 作品にレビュー本文と評価を投稿する |
| DELETE | `/reviews/{review}` | `reviews.destroy` | `ReviewController@destroy` | 必要 | 自分のレビューを削除する |

レビュー削除ルートは存在するが、初期移植フェーズでは削除導線を本人のレビュー一覧画面にのみ表示する。

作品詳細画面ではレビュー削除導線を表示しない。

### レビュー返信機能

レビュー返信投稿は `auth` と `verified` ミドルウェアで保護する。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| POST | `/reviews/{review}/comments` | `reviews.comments.store` | `ReviewCommentController@store` | 必要 + メール認証必須 | レビューに返信を投稿する |

### ログアウト

Laravel Breezeの認証ルートを使用する。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| POST | `/logout` | `logout` | Breeze標準 | 必要 | ログアウト処理を行う |

## middleware設計

`User` モデルは `MustVerifyEmail` を実装し、Laravel Breeze標準のメール認証ルートを使用する。

### 認証不要

以下は認証不要で閲覧できる。

- 作品一覧画面
- 作品詳細画面
- レビュー表示
- レビュー返信表示
- 星評価表示

### 未ログイン限定（`guest`）

以下は `guest` ミドルウェアで保護し、ログイン済みの場合は `/` へリダイレクトする。

- 会員登録画面
- ログイン画面
- パスワードリセット申請画面
- パスワード再設定画面

### 認証必須

以下は `auth` ミドルウェアで保護する。

- アカウント画面表示
- アカウント情報更新
- パスワード更新
- 会員退会
- 本人のレビュー一覧表示
- レビュー削除
- ログアウト
- メール認証案内表示
- 認証メール再送

### 認証 + メール認証必須（`auth` + `verified`）

以下は `auth` と `verified` ミドルウェアで保護する。

- レビュー・評価投稿
- レビュー返信投稿

### 認証 + 管理者認可（`auth` + `can:access-admin-panel`）

管理画面ダッシュボードは `auth` の後に `can:access-admin-panel` で保護する。未ログインユーザーはログイン画面へ誘導し、認証済みでも管理者権限がなければ403で拒否する。`verified` ミドルウェアは使用しない。

### メール認証関連

以下は `routes/auth.php` で定義するLaravel Breeze標準のメール認証ルートである。

| HTTPメソッド | URL | ルート名 | Controller | middleware | 概要 |
|---|---|---|---|---|---|
| GET | `/verify-email` | `verification.notice` | `Auth\EmailVerificationPromptController` | `auth` | メール認証案内画面を表示する |
| GET | `/verify-email/{id}/{hash}` | `verification.verify` | `Auth\VerifyEmailController` | `auth`, `signed`, `throttle:6,1` | 署名付きURLからメール認証を完了する |
| POST | `/email/verification-notification` | `verification.send` | `Auth\EmailVerificationNotificationController@store` | `auth`, `throttle:6,1` | 認証メールを再送する |

会員登録後は未認証状態でログインし、`RouteServiceProvider::HOME` である `/` へリダイレクトする。登録直後に `/verify-email` へ自動遷移しない。

## 認可方針

以下の処理は、ログイン済みであるだけでなく、本人確認または操作権限の確認を行う。

| 処理 | 認可方針 |
|---|---|
| レビュー・評価投稿 | 会員のみ投稿可能。1ユーザーにつき1作品1件まで |
| レビュー削除 | 自分が投稿したレビューのみ削除可能 |
| レビュー返信投稿 | 会員のみ投稿可能 |
| アカウント情報の編集 | 自分のプロフィールのみ編集可能 |
| 会員退会 | 自分のアカウントのみ退会可能 |
| 本人のレビュー一覧表示 | 自分のレビューのみ表示 |
| 管理画面ダッシュボード | `users.role` が `admin` のユーザーのみ表示可能 |

認可は、Laravel標準のGate、PolicyまたはController側の条件分岐で実装する。

特定モデルに紐づく操作ではPolicyを優先して検討し、管理画面ダッシュボードのようなモデル非依存の認可にはGateを使用する。

## Controller候補

| Controller | 役割 |
|---|---|
| `ItemController` | 作品一覧、作品詳細表示 |
| `ReviewController` | レビュー・評価投稿、本人レビュー一覧、レビュー削除 |
| `ReviewCommentController` | レビュー返信投稿 |
| `ProfileController` | アカウント画面表示、アカウント情報更新、退会 |
| Breeze標準Controller | 会員登録、ログイン、ログアウト、パスワードリセット、パスワード更新 |

## MVP2で検討するルート候補

以下はMVP2で今後追加する画面と操作の候補であり、URL、HTTPメソッド、ルート名、Controller、middlewareの確定仕様ではない。管理画面ダッシュボードの認証・認可方式は確定済みだが、登録済み作品一覧とTMDB関連ルートは後続の技術調査・設計で決定する。

お問い合わせフォーム、利用規約、プライバシーポリシー、会員登録時の同意確認に必要なルートもMVP2で追加する。URL、HTTPメソッド、ルート名は各実装Issueで決定する。

### 管理者機能

| HTTPメソッド | URL | ルート名 | 概要 |
|---|---|---|---|
| GET | `/admin/items` | `admin.items.index` | 管理者作品一覧を表示する |
| POST | `/admin/items` | `admin.items.store` | TMDB検索結果から選択した作品を1作品登録する候補 |

### 外部API連携

| HTTPメソッド | URL | ルート名 | 概要 |
|---|---|---|---|
| GET | `/admin/tmdb/search` | `admin.tmdb.search` | TMDB検索画面を表示する |

TMDB検索の送信方法、検索結果のURL、登録操作のルート、重複時の応答は未確定とする。

## MVP2対象外の将来ルート候補

- 管理者作品詳細モーダルに必要な取得処理
- 作品編集
- 作品削除
- お問い合わせ管理

## 補足

このドキュメントでは、URL、HTTPメソッド、ルート名、Controller、認証要否を整理する。

画面遷移は `docs/SCREEN_TRANSITIONS.md` に整理する。

DB設計は `docs/DATABASE.md` に整理する。

認証・認可の詳細は `docs/SECURITY.md` に整理する。
