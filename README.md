# 映画レビューアプリ Laravel移植版

## 概要

PHPスクラッチMVCで作成した映画レビューアプリをLaravelへ移植し、サポート対象版へ段階的にアップグレードする。
MVP1で最低限のユーザー向け機能を移植し、MVP2では初回公開に向けた管理者機能、TMDB API連携、公開準備を進めます。

## 目的

- LaravelのMVC構造を学習する
- スクラッチMVCで実装した機能をLaravel流に置き換える
- DB設計、認証、バリデーション、ルーティングを整理する
- ポートフォリオとして、設計・実装・運用方針を説明できる状態にする

## 使用技術

- PHP 8.4.24（PR #131を`develop`へマージ済みの確定baseline。同期PR #138により`main`へ反映済み。XServer本番環境へは未反映）
- Laravel 13.26.1（PR #135を`develop`へマージ済みの確定baseline。同期PR #138により`main`へ反映済み。XServer本番環境へは未反映）
- Laravel Sail
- MySQL
- phpMyAdmin
- Mailpit（ローカルのメール確認用）
- Laravel Breeze
- Blade
- Tailwind CSS
- Vite
- PHPUnit
- Playwright Test / TypeScript
- PHPStan / Larastan
- Laravel Pint
- Laravel IDE Helper
- Git / GitHub

## CI

GitHub Actionsを使用し、Pull Requestおよび`main` / `develop`ブランチへのpush時に、次の品質チェックを自動実行します。

- Laravel Pint
- PHPStan / Larastan Level 10
- Vite build
- PHPUnit（MySQL環境）
- Playwright E2E（Chromium / E2E専用MySQL環境）
- Ruff lint
- Ruff format check
- Python unittest

Composer依存関係は`composer.lock`、npm依存関係は`package-lock.json`を正本として、固定されたバージョンを使用します。

## 開発環境

### ローカル環境のポート設定

このプロジェクトでは、ローカル環境のポート競合を避けるため、Laravel Sailのポート番号を以下のように設定しています。

| 用途 | URL / ポート |
|---|---|
| Laravel | http://localhost:82 |
| Playwright E2E用Laravel | http://localhost:83 |
| MySQL外部接続 | localhost:3308 |
| phpMyAdmin | http://localhost:8083 |
| Mailpit SMTP | localhost:1025 |
| Mailpit Web UI | http://localhost:8025 |

## 主な機能

### MVP1 - 初期移植版

#### 共通機能

- 作品一覧表示
- 作品一覧ページネーション
- 作品詳細表示
- レビュー表示
- レビュー返信表示
- 星評価表示

#### ゲスト機能

- 会員登録
- ログイン
- パスワードリセット

#### 会員機能

- ログアウト
- アカウント管理
- ユーザーアイコンの登録・差し替え・表示
- 会員退会
- レビュー・評価投稿 / 削除（1ユーザーにつき1作品1件）
- レビュー返信投稿
- 本人のレビュー一覧表示
- レビュー履歴ページネーション

### MVP2 - 初回公開版（v1.0.0）

MVP2では、Laravel Breeze標準メール認証、既存のBreeze認証と `users.role` による管理者認可、PHP 8.4 / Laravel 13への更新、PHPStan / Larastan Level 10、Playwright E2E基盤等を整備済みです。

初回公開に向けて、次の領域を進めます。

- 作品詳細画面への評価分布表示
- お問い合わせフォーム、利用規約・プライバシーポリシー表示、会員登録時の同意確認
- 登録済み作品一覧などの管理機能
- TMDB APIを利用した映画検索、1作品ずつの登録、重複登録防止
- 利用規約、プライバシーポリシー、License、本番環境、デプロイ等の公開準備

MVP2の最新スコープ・優先順位・進捗は、GitHub Milestone「MVP2 - 初回公開版（v1.0.0）」と所属Issue / Pull Requestを正本とします。機能・基盤の区分は[機能一覧](docs/FEATURES.md)、大まかな依存順は[実装計画](docs/IMPLEMENTATION_PLAN.md)を参照してください。

### MVP2対象外の将来候補

レビュー編集、お気に入り、管理者による作品編集・削除、作品探索・表示の拡張、追加通知、通報・利用停止等は将来候補です。詳細は[機能一覧](docs/FEATURES.md)を参照してください。

## DB設計

初期移植フェーズでは、既存スクラッチ版のDB構成を参考にしつつ、Laravelのマイグレーション、Eloquentリレーション、Laravel Breezeの認証機能に合わせて再設計しています。

レビュー本文と評価は `reviews` テーブルで一体管理し、作品一覧・作品詳細で表示する平均評価と評価件数は `items` テーブルにキャッシュとして保持します。

また、会員退会時は `users` レコードを物理削除し、投稿済みレビューやレビュー返信コメントは投稿者情報を切り離して匿名表示する方針です。

![ER図](docs/images/database-er.png)

詳細なテーブル定義、リレーション、削除時の方針は、[DB設計](docs/DATABASE.md) に整理しています。

## ドキュメント

- [GitHub開発運用ガイド](docs/GITHUB_WORKFLOW.md)
- [開発フロー](docs/DEVELOPMENT_FLOW.md)
- [実装計画](docs/IMPLEMENTATION_PLAN.md)
- [MVP1テスト対応状況](docs/MVP1_TEST_COVERAGE.md)
- [Playwrightブラウザテスト運用ガイド](docs/PLAYWRIGHT_TESTING.md)
- [Claude Code実装前検証運用手順](docs/CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md)
- [Claude Codeレビュー運用手順](docs/CLAUDE_CODE_REVIEW.md)
- [Claude Code権限設計](docs/CLAUDE_CODE_PERMISSION_DESIGN.md)
- [AI共用ローカル成果物運用](docs/AI_LOCAL_ARTIFACTS.md)
- [要件定義](docs/REQUIREMENTS.md)
- [機能一覧](docs/FEATURES.md)
- [画面遷移](docs/SCREEN_TRANSITIONS.md)
- [DB設計](docs/DATABASE.md)
- [ルーティング設計](docs/ROUTES.md)
- [セキュリティ方針](docs/SECURITY.md)
- [コマンド集](docs/COMMANDS.md)
- [トラブルシューティング](docs/TROUBLESHOOTING.md)
- [デプロイ方針](docs/DEPLOYMENT.md)
- [Laravelメジャーアップグレードガイド](docs/LARAVEL_UPGRADE_GUIDE.md)
- [Laravelメジャーアップグレード実施履歴](docs/LARAVEL_UPGRADE_HISTORY.md)

## 開発方針

- 作業ブランチを作成する前にIssueを作成する
- 最新の`develop`から作業ブランチを作成する
- 通常のPull Requestは作業ブランチから`develop`へ作成する
- 公開可能な単位を`develop`から`main`への同期Pull Requestで反映する
- 通常の作業コミットを`main`または`develop`へ直接pushせず、force pushを使用しない
- コミットは1目的1コミットを基本とする
- Issue、ブランチ、Pull Request、マージ、マージ後整理の詳細は[GitHub開発運用ガイド](docs/GITHUB_WORKFLOW.md)を参照する
- MVP2の最新スコープと進捗はGitHub Milestoneと所属Issue / Pull Requestを参照する
- READMEにはIssue一覧や詳細な進捗表を重複させない
- セキュリティと正常動作のバランスを重視する
