# 開発フロー

## 1. このドキュメントの目的

このドキュメントは、映画レビューアプリ Laravel移植版における、要件・設計の確認から実装、テスト、レビュー、ドキュメント整合確認までの開発全体フローを整理します。

Issue、Labels、Milestone、ブランチ、Pull Request、マージ、マージ後整理の詳細は[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)を正本とします。Git、Laravel、テスト、静的解析などのコマンド単体の用途と書式は[コマンド集](COMMANDS.md)を参照してください。

## 2. 基本方針

- 作業ブランチを作成する前にIssueを作成し、目的、対象、対象外、受け入れ条件を明確にする
- 実装前に要件と関連する設計文書を確認する
- Laravel標準の認証、認可、バリデーション、CSRF、Bladeエスケープ、Eloquentを優先する
- 実装、テスト、README、docsの内容を一致させる
- 1目的1コミットを基本とし、関係のない変更を混在させない
- 通常PRと同期PRの運用は[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)に従う

## 3. 開発全体フロー

```text
Issue作成・受け入れ条件の確認
  ↓
要件・仕様・設計の確認
  ↓
最新のdevelopから作業ブランチを作成
  ↓
実装と対象限定の確認
  ↓
テスト・静的解析・コードスタイル・ビルド確認
  ↓
README・docsとの整合確認
  ↓
差分レビュー
  ↓
通常PRをdevelopへMerge commitでマージ
  ↓
公開可能な単位でdevelopからmainへ同期
```

GitHub上の操作順、ブランチ命名、Issue参照、マージ後整理、停止条件は、この文書へ重複させません。

## 4. Issueと実装準備

作業ブランチを作成する前にIssueを作成し、少なくとも次を確認します。

- 解決する問題または追加する価値
- 実装・変更する範囲
- 今回は扱わない範囲
- 完了を判断できる受け入れ条件
- 関連する要件、機能、画面、DB、ルート、セキュリティ文書
- 必要なテストとドキュメント更新

実装前に設計方針、Issueの受け入れ条件、関連ドキュメントとの整合性をClaude Codeで検証する場合は、[Claude Code実装前検証運用手順](CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md)に従います。

## 5. 要件・仕様・設計の確認

変更内容に応じて、次の正本を確認します。

| 観点 | 正本 |
| --- | --- |
| アプリケーション要件 | [要件定義](REQUIREMENTS.md) |
| 機能範囲 | [機能一覧](FEATURES.md) |
| 画面遷移 | [画面遷移](SCREEN_TRANSITIONS.md) |
| DB・Eloquent | [DB設計](DATABASE.md) |
| ルート・HTTPメソッド | [ルーティング設計](ROUTES.md) |
| セキュリティ | [セキュリティ方針](SECURITY.md) |
| 実装順序・MVP1の計画記録 | [実装計画](IMPLEMENTATION_PLAN.md) |

既存文書とIssueが矛盾する場合は、推測で実装へ進まず、どちらを更新するか人間が判断します。

## 6. 実装

実装では、Issueの受け入れ条件を満たす最小範囲を扱います。

- Controller、Form Request、Policy、Service、Model、Viewの責務を分ける
- 認証・認可・バリデーションをLaravel標準機能で実装する
- DB制約、外部キー、nullable、削除時動作を設計と一致させる
- 複数更新の整合性が必要な処理ではトランザクションを検討する
- Bladeの自動エスケープとCSRF保護を維持する
- 関係のないリファクタリングや後続機能を混在させない

実装中は変更の区切りごとに差分を確認します。各コマンドの用途は[コマンド集](COMMANDS.md)、コミットまでの実行順序は[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)を参照してください。

## 7. テストと品質確認

変更内容に応じて、次を確認します。

### 共通

- Issueの受け入れ条件と差分が一致している
- 関係のない変更や秘密情報が含まれていない
- READMEやdocsのリンクと説明が正しい

### Laravel実装を含む場合

- 対象ルートとmiddleware
- 正常系、異常系、境界値、認証・認可のFeatureテスト
- Laravel Pintによるコードスタイル確認
- PHPStan / Larastanによる静的解析
- Vite build

### DB変更を含む場合

- マイグレーション内容とDB設計の一致
- 開発環境の接続先を確認した上での再構築
- 外部キー、UNIQUE制約、nullable、削除時動作
- 既存データとロールバックへの影響

### 依存関係を変更した場合

- `composer.json`と`composer.lock`、または`package.json`と`package-lock.json`の差分
- 依存関係の脆弱性情報とLaravel 10との互換性
- 変更後のテスト、静的解析、Vite build
- `vendor/`と`node_modules/`がGit管理対象へ混入していないこと

コマンド単体の書式は[コマンド集](COMMANDS.md)を参照します。失敗時は結果を隠さず、[トラブルシューティング](TROUBLESHOOTING.md)で切り分けます。

## 8. レビュー

通常PRを作成する前に、人間がIssueの受け入れ条件、差分、実行した確認、未実行の確認を整理します。

Claude CodeでPR差分レビューを行う場合は、[Claude Codeレビュー運用手順](CLAUDE_CODE_REVIEW.md)に従います。Claude Codeは読み取り専用のセカンドオピニオンであり、修正、Git変更操作、Issue・PR変更操作、マージ判断を代行しません。

レビューでは次を優先します。

- Issueの受け入れ条件とスコープ
- Laravel標準機能と各層の責務
- 認証、認可、バリデーション、セキュリティ
- DB・Eloquent・トランザクションの整合性
- 既存画面、ルート、テストへの影響
- README、docs、実装、テストの一致

指摘の採否、修正方針、マージ可否は人間が決定します。

## 9. ドキュメント整合確認

実装完了時は、コードだけでなく関連文書も確認します。

- 要件や対象範囲が変わった場合は`REQUIREMENTS.md`または`FEATURES.md`
- 画面遷移が変わった場合は`SCREEN_TRANSITIONS.md`
- DB構造や削除方針が変わった場合は`DATABASE.md`
- ルートが変わった場合は`ROUTES.md`
- セキュリティ判断が変わった場合は`SECURITY.md`
- 開発・確認コマンドが変わった場合は`COMMANDS.md`
- デプロイ前提が変わった場合は`DEPLOYMENT.md`
- 利用者向けの概要や入口が変わった場合は`README.md`

GitHub運用ルールを変更する場合は`GITHUB_WORKFLOW.md`を正本として更新し、他文書には必要最小限の要約と参照だけを置きます。

## 10. Laravel命名規則

Laravel標準の命名規則を優先し、独自ルールを増やしすぎません。

| 対象 | 命名規則 | 例 |
| --- | --- | --- |
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

GitHub上のIssue、ブランチ、PR、コミットの命名は[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)を参照してください。

## 11. 関連ドキュメント

- [GitHub開発運用ガイド](GITHUB_WORKFLOW.md)
- [コマンド集](COMMANDS.md)
- [トラブルシューティング](TROUBLESHOOTING.md)
- [Claude Code実装前検証運用手順](CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md)
- [Claude Codeレビュー運用手順](CLAUDE_CODE_REVIEW.md)
