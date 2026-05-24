# 開発フロー

## 基本方針

このプロジェクトでは、mainブランチへ直接pushせず、作業ブランチを作成してPull Request経由でmainへマージする。

## Issue運用方針

作業内容が明確な場合は、必要に応じてIssueを作成してから作業ブランチを作成する。

Issueには、目的、作業内容、完了条件を簡潔に記載する。

Issueは、以下の用途で使用する。

- 実装予定の整理
- ドキュメント修正予定の整理
- 調査中・判断待ち項目の記録
- Pull Requestとの紐づけ

個人開発のため、Issueを細かく作りすぎず、1つの作業単位が分かる粒度にする。

## 作業開始時の流れ

作業内容が明確な場合は、必要に応じてIssueを作成または確認してから作業ブランチを作成する。

```bash
git switch main
git pull origin main
git status
git switch -c 作業ブランチ名
```

## 作業ブランチの命名ルール

| 種類 | 用途 | 例 |
| --- | --- | --- |
| docs | ドキュメント追加・修正 | docs/add-initial-project-documents |
| feat | 新規機能追加 | feat/add-item-list |
| fix | 不具合修正 | fix/review-delete-error |
| chore | 設定変更・環境整備 | chore/update-phpstan-config |
| style | コード整形 | style/apply-pint-format |
| refactor | 振る舞いを変えない整理 | refactor/extract-review-service |

## コミット方針

- 1目的1コミットを基本とする
- 変更内容が分かるコミットメッセージにする
- 動作確認できる単位でコミットする
- 関係ない修正を同じコミットに混ぜない

## コミットメッセージ例

```bash
git commit -m "docs: READMEにLaravel移植版の概要を追加"
git commit -m "docs: 開発フローと基本コマンドを追加"
git commit -m "docs: 要件定義と設計ドキュメントの初期版を追加"
git commit -m "feat: 作品一覧画面を追加"
git commit -m "fix: レビュー削除時の認可チェックを修正"
```

## Pull Requestの流れ

1. 必要に応じてIssueを作成する
2. 作業ブランチで変更する
3. コミットする
4. GitHubへpushする
5. GitHubでPull Requestを作成する
6. Pull Requestに関連Issueを紐づける
7. 差分を確認する
8. mainへマージする
9. 不要になったリモートブランチを削除する
10. ローカルmainを最新化する
11. 不要になったローカルブランチを削除する

## PRマージ後の流れ

```bash
git switch main
git pull origin main
git branch -d 作業ブランチ名
git fetch --prune
git status
```

## 確認コマンド

PR前には、変更内容に応じて以下を確認する。

### 共通確認

```bash
git status
git diff
git diff --staged
```

### ドキュメントのみの場合

```bash
git status
git diff
```

### 実装を含む場合

```bash
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail test
./vendor/bin/sail php ./vendor/bin/phpstan analyse
./vendor/bin/sail npm run build
```

### 依存関係を変更した場合

```bash
git diff composer.json composer.lock
git diff package.json package-lock.json
./vendor/bin/sail composer validate
./vendor/bin/sail composer audit
./vendor/bin/sail npm audit
```

Composer / npm の依存関係を変更した場合は、差分確認、脆弱性確認、テスト、静的解析、ビルド確認を行う。

`composer update`、`npm update`、`npm audit fix` は依存関係が変わる可能性があるため、実行前に影響範囲を確認する。

## 命名規則

Laravel標準の命名規則を優先し、独自ルールを増やしすぎない。

| 対象 | 命名規則 | 例 |
|---|---|---|
| Controller | PascalCase + Controller | ItemController |
| Model | 単数形 PascalCase | Item |
| Migration | snake_case | create_items_table |
| Table | 複数形 snake_case | items |
| Column | snake_case | user_id |
| 変数 | camelCase | $reviewCount |
| メソッド | camelCase | calculateAverageRating |
| Route name | ドット区切り | items.index |
| Blade | ディレクトリ区切り | items/index.blade.php |
| CSS class | kebab-case | review-card |

## 注意点

- mainへ直接pushしない
- `.env` はコミットしない
- `vendor/` と `node_modules/` はコミットしない
- 実装前に、要件・画面・DB・ルートの設計を確認する
- セキュリティに関わる処理はLaravel標準機能を優先する
