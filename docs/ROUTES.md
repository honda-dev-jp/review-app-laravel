# ルーティング設計

## このドキュメントの目的

このドキュメントでは、映画レビューアプリ Laravel移植版のルーティング設計を整理する。

画面遷移で整理した画面に対して、URL、HTTPメソッド、ルート名、Controller、認証要否を定義する。

初期移植フェーズでは、共通画面、ゲスト画面、会員画面を中心に整理する。

管理者画面は後続フェーズで検討する。

## ルーティング方針

- URLはLaravelの慣習に合わせて分かりやすくする
- ルート名は `items.index` のようにドット区切りで定義する
- 共通画面は認証不要とする
- 会員機能は `auth` ミドルウェアで保護する
- 自分のレビュー削除など本人確認が必要な処理は、PolicyまたはController側で認可を行う
- POST、PATCH、DELETEなど状態変更を伴う処理ではCSRF保護を前提とする

## 初期移植フェーズのルート一覧

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
| GET | `/register` | `register` | Breeze標準 | 不要 | 会員登録画面を表示する |
| POST | `/register` | `register` | Breeze標準 | 不要 | 会員登録処理を行う |
| GET | `/login` | `login` | Breeze標準 | 不要 | ログイン画面を表示する |
| POST | `/login` | `login` | Breeze標準 | 不要 | ログイン処理を行う |

### 会員画面

会員画面は `auth` ミドルウェアで保護する。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| GET | `/profile` | `profile.edit` | `ProfileController@edit` | 必要 | プロフィール編集画面を表示する |
| PATCH | `/profile` | `profile.update` | `ProfileController@update` | 必要 | プロフィール更新処理を行う |
| GET | `/profile/delete` | `profile.delete` | `ProfileController@delete` | 必要 | 会員退会確認画面を表示する |
| DELETE | `/profile` | `profile.destroy` | `ProfileController@destroy` | 必要 | 会員退会処理を行う |
| GET | `/my-reviews` | `reviews.mine` | `ReviewController@mine` | 必要 | 本人のレビュー一覧を表示する |

### レビュー機能

レビュー・評価投稿、レビュー削除は `auth` ミドルウェアで保護する。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| POST | `/items/{item}/reviews` | `reviews.store` | `ReviewController@store` | 必要 | 作品にレビュー本文と評価を投稿する |
| DELETE | `/reviews/{review}` | `reviews.destroy` | `ReviewController@destroy` | 必要 | 自分のレビューを削除する |

レビュー削除ルートは存在するが、初期移植フェーズでは削除導線を本人のレビュー一覧画面にのみ表示する。

作品詳細画面ではレビュー削除導線を表示しない。

### レビュー返信機能

レビュー返信投稿は `auth` ミドルウェアで保護する。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| POST | `/reviews/{review}/comments` | `reviews.comments.store` | `ReviewCommentController@store` | 必要 | レビューに返信を投稿する |

### ログアウト

Laravel Breezeの認証ルートを使用する。

| HTTPメソッド | URL | ルート名 | Controller | 認証 | 概要 |
|---|---|---|---|---|---|
| POST | `/logout` | `logout` | Breeze標準 | 必要 | ログアウト処理を行う |

## middleware設計

### 認証不要

以下は認証不要で閲覧できる。

- 作品一覧画面
- 作品詳細画面
- レビュー表示
- レビュー返信表示
- 星評価表示
- 会員登録画面
- ログイン画面

### 認証必須

以下は `auth` ミドルウェアで保護する。

- プロフィール編集
- プロフィール更新
- 会員退会確認
- 会員退会
- 本人のレビュー一覧表示
- レビュー・評価投稿
- レビュー削除
- レビュー返信投稿
- ログアウト

## 認可方針

以下の処理は、ログイン済みであるだけでなく、本人確認または操作権限の確認を行う。

| 処理 | 認可方針 |
|---|---|
| レビュー・評価投稿 | 会員のみ投稿可能。1ユーザーにつき1作品1件まで |
| レビュー削除 | 自分が投稿したレビューのみ削除可能 |
| レビュー返信投稿 | 会員のみ投稿可能 |
| プロフィール編集 | 自分のプロフィールのみ編集可能 |
| 会員退会 | 自分のアカウントのみ退会可能 |
| 本人のレビュー一覧表示 | 自分のレビューのみ表示 |

認可は、LaravelのPolicyまたはController側の条件分岐で実装する。

実装時は、Policyの利用を優先して検討する。

## Controller候補

| Controller | 役割 |
|---|---|
| `ItemController` | 作品一覧、作品詳細表示 |
| `ReviewController` | レビュー・評価投稿、本人レビュー一覧、レビュー削除 |
| `ReviewCommentController` | レビュー返信投稿 |
| `ProfileController` | プロフィール編集、更新、退会確認、退会 |
| Breeze標準Controller | 会員登録、ログイン、ログアウト |

## 後続フェーズで検討するルート

### 管理者機能

| HTTPメソッド | URL | ルート名 | 概要 |
|---|---|---|---|
| GET | `/admin/items` | `admin.items.index` | 管理者作品一覧を表示する |
| GET | `/admin/items/create` | `admin.items.create` | 作品登録画面を表示する |
| POST | `/admin/items` | `admin.items.store` | 作品登録処理を行う |
| GET | `/admin/items/{item}/edit` | `admin.items.edit` | 作品編集画面を表示する |
| PATCH | `/admin/items/{item}` | `admin.items.update` | 作品更新処理を行う |
| DELETE | `/admin/items/{item}` | `admin.items.destroy` | 作品削除処理を行う |

### 外部API連携

| HTTPメソッド | URL | ルート名 | 概要 |
|---|---|---|---|
| GET | `/admin/tmdb/search` | `admin.tmdb.search` | TMDB検索画面を表示する |
| POST | `/admin/tmdb/import` | `admin.tmdb.import` | TMDBから取得した作品情報を登録する |

## 補足

このドキュメントでは、URL、HTTPメソッド、ルート名、Controller、認証要否を整理する。

画面遷移は `docs/SCREEN_TRANSITIONS.md` に整理する。

DB設計は `docs/DATABASE.md` に整理する。

認証・認可の詳細は `docs/SECURITY.md` に整理する。
