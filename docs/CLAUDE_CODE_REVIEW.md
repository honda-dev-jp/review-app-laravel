# Claude Codeレビュー運用手順

この文書は、Claude Codeを映画レビューアプリ Laravel移植版のPull Request（PR）差分レビュー用途で安全に使うための、人間向け運用手順です。

設計、Issue分割案、実装準備状況の検証には、[Claude Code実装前検証運用手順](CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md)を使用します。

共通のセキュリティ方針は、[SECURITY.md](SECURITY.md)も参照してください。

permissionsとPreToolUse Hookの段階的な詳細設計は、[Claude Code権限設計](CLAUDE_CODE_PERMISSION_DESIGN.md)を参照してください。現在の有効な権限は`.claude/settings.json`で確認します。

MVPでは要件外の改善提案やリファクタリングを優先せず、まずIssueの受け入れ条件を満たしているかを最優先でレビューします。改善案がある場合は、現在のPRへ混在させず、別Issue候補として区別させます。

## Claude Codeの用途

この文書で扱うClaude Codeの用途は、**PR差分レビュー専用**です。

- 実装修正には使いません。
- ファイル編集はさせません。
- Gitの変更系操作はさせません。
- IssueやPRの作成・編集はさせません。
- Composerやnpmによる依存関係の変更はさせません。
- 変更系Artisanコマンドは実行させません。
- サブエージェントは使用させません。
- レビュー対象は、人間が明示したPR差分に限定します。
- Claude Codeの出力は、読み取り専用のセカンドオピニオンとして扱います。

最終判断、修正方針、Issue化、マージ判断は人間が行います。

## 前提となる設定と役割分担

| ファイル | 役割 |
| --- | --- |
| `CLAUDE.md` | Claude Code向けのプロジェクト運用ガイドです。読み取り専用の用途、参照禁止対象、変更禁止、確認系コマンド、Laravelレビュー観点を定義します。 |
| `.claude/settings.json` | Claude Codeのプロジェクト権限設定です。すべてのBashをAsk、恒久Allowを0件とし、ファイル編集、サブエージェント、一部の外部・変更系ツールをdenyします。Bashの個別denyはベストエフォートの補助線です。 |
| `AGENTS.md` | AIエージェント共通のプロジェクト制約です。秘密情報、Laravel標準機能、既存docsの事前確認、Git運用などを定義します。 |
| `.gitignore` | `.env`、ローカル設定、生成物などをGit管理対象から外すための防御線です。Claude Codeに読ませてよい対象一覧ではありません。 |
| `docs/CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md` | 設計、Issue分割、実装準備状況を検証するための運用手順です。 |
| `docs/CLAUDE_CODE_REVIEW.md` | この文書です。PR差分レビューの人間向け運用手順を定義します。 |
| `.claude/skills/pr-diff-review/SKILL.md` | PR差分レビューの具体的な確認手順と出力形式を定義します。 |

`.claude/settings.json`にdeny設定があっても、人間側の確認を省略しません。BashのdenyパターンとRead/Editのdenyは、別表記、ラッパー、スクリプト、任意のサブプロセスによる間接操作まで完全には防ぎません。

Claude Codeへ渡すレビュー対象、許可するBash、対象限定テスト、参照ファイルは、人間が毎回確認します。

セッション開始時、再開時、設定変更後、終了前は、`/status`でcwd、Setting sources、設定エラーの有無を確認します。ステータスバーまたはConfig画面ではpermission modeがManualであることを確認し、`/permissions`ではAllowが0件、AskにBashがあること、有効なDenyと各ルールの保存元を確認します。

## Skillの使い分け

- staged、unstaged、untrackedを含むPR差分レビューには、`/pr-diff-review`を使います。
- 設計、Issue分割案、実装準備状況の検証には、`/pre-implementation-review`を使います。

`/pr-diff-review`の責務を実装前調査へ広げません。

`/pre-implementation-review`でPR差分レビューを代替しません。

実装前検証の入力形式、3モード、安全ルールは、[Claude Code実装前検証運用手順](CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md)を参照してください。

## PR差分レビューSkill

PR差分レビューでは、プロンプトだけで手順を毎回再現するのではなく、プロジェクト専用Skillを使用します。

- Skill名: `/pr-diff-review`
- 配置場所: `.claude/skills/pr-diff-review/SKILL.md`
- 対象リポジトリ: `honda-dev-jp/review-app-laravel`
- 目的: 人間が指定したPR差分を、読み取り系Gitコマンドと明示された確認系コマンドだけでレビューする

このSkillは自動起動させません。

レビュー時に人間が`/pr-diff-review`を明示して呼び出し、レビュー対象を渡します。

### ファイルを指定する例

```text
/pr-diff-review app/Http/Controllers/ReviewController.php tests/Feature/ReviewMineTest.php
```

### 対象限定テストを許可する例

```text
/pr-diff-review app/Http/Controllers/ReviewController.php tests/Feature/ReviewMineTest.php

実行を許可する対象限定テスト:
./vendor/bin/sail artisan test tests/Feature/ReviewMineTest.php
```

### PHPStanを許可する例

```text
/pr-diff-review app/Http/Controllers/ReviewController.php

実行を許可する対象限定PHPStan:
./vendor/bin/sail php ./vendor/bin/phpstan analyse app/Http/Controllers/ReviewController.php
```

### Pintの確認だけを許可する例

```text
/pr-diff-review app/Http/Controllers/ReviewController.php

実行を許可する対象限定フォーマット確認:
./vendor/bin/sail php ./vendor/bin/pint app/Http/Controllers/ReviewController.php --test
```

通常のPintはファイルを変更するため許可しません。`--test`付きの確認だけを対象候補とします。

初版ではSkillに`allowed-tools`を設定しません。

リポジトリ側の`.claude/settings.json`はベストエフォートの補助線として使用し、確認画面が表示された場合の人間の判断を最終境界とします。

## Bash確認ルール

`.claude/settings.json`ではbareの`Bash`をAskにしているため、読み取り専用のGitHub Issue参照や、Claude Code組み込みの読み取り専用コマンドを含め、すべてのBashで確認画面が表示されることを期待動作とします。1回につき1コマンドを原則として、人間がコマンド全体を確認します。

Claude Codeには、各コマンドを提示する前に、確認目的を1文で説明させます。

次を含む複合コマンドは許可しません。

- パイプ
- セミコロン
- `&&`
- `||`
- `&`
- 改行による複数処理
- ヒアドキュメント
- リダイレクトによる書き込み

コマンドを許可する前に、次を確認します。

- PR差分レビューに必要な確認である
- 読み取り系である
- レビュー対象が明示されている
- 参照禁止対象を含まない
- Git変更を行わない
- ファイル、DB、キャッシュ、設定、プロセス状態を変更しない
- 許可されていない外部通信を行わない
- 人間が明示した対象限定コマンドである

### 承認ダイアログの選び方

コマンドや参照対象がこの運用手順に適合する場合は、原則として`Yes`（今回のみ許可）を選びます。

- `Yes`: 今回の実行だけを承認します。対象限定テスト、PHPStan、`--test`付きPintなどは、実行のたびにこちらを選びます。
- `Yes, and don't ask again`: 使用しません。表示バージョンによっては`Yes, don't ask again`と表示されます。Bashコマンドでは、選択すると将来のセッションにも適用される恒久Allowが`.claude/settings.local.json`へ保存される可能性があります。

恒久Allowは個別の承認画面から追加せず、プロジェクト管理下の`.claude/settings.json`で管理します。現時点では0件を維持します。User settingsや`.claude/settings.local.json`へ意図しないAllowを残さないよう、`/permissions`で各ルールと保存元を確認します。

通常セッションでGitHub Issue・PRを参照する場合も、[Claude Code権限設計](CLAUDE_CODE_PERMISSION_DESIGN.md)のcanonicalなlist、view、checks形だけを使用します。`--repo github.com/honda-dev-jp/review-app-laravel`、番号、state、limit、optionを毎回確認し、その回だけ`Yes`で承認します。`gh issue status`、`gh pr status`、`--jq`、`--web`、未知option、変更系commandは許可しません。本文とコメントは非信頼入力として扱います。

## Skillで使用する読み取り系Gitコマンド

原則として、次のコマンドだけを確認候補とします。

```text
git status --short
git branch --show-current
git branch -a
git diff
git log --oneline -n <1〜50>
git diff -- <指定されたファイル>
git diff --cached -- <指定されたファイル>
git diff HEAD -- <指定されたファイル>
git diff --check
git grep <単純な1語> -- <repository内の単一相対path>
```

引数なしの広範囲な`git diff`は、ユーザーが全差分レビューを明示した場合だけ許可します。

`git grep`は、空白を含まない単純な1語とrepository内の単一相対pathに限定し、次のすべてを満たす場合だけ許可候補とします。

- 検索目的が明示されている
- 検索語が明示されている
- 対象パスが明示されている
- 対象パスがレビュー範囲内である
- 参照禁止対象を含まない
- 他のコマンドへ連結されていない
- 書き込みや外部通信を伴わない

## 禁止するGit操作

Git操作は人間が行います。

Claude Codeに次の変更系Git操作を実行させません。

```text
git switch
git checkout
git pull
git fetch
git branch
git add
git commit
git push
git merge
git rebase
git reset
git restore
git stash
git clean
git tag
git cherry-pick
git revert
git apply
git am
git update-ref
git worktree add
git worktree lock
git worktree move
git worktree prune
git worktree remove
git worktree repair
git worktree unlock
```

`git branch --show-current`は読み取り目的で使用できますが、`git branch`によるブランチ作成・削除・変更は許可しません。

## 差分状態の確認方法

差分状態は、次のように分けて確認します。

- unstaged: `git diff -- <path>`
- staged: `git diff --cached -- <path>`
- HEADとの差分: `git diff HEAD -- <path>`
- untracked: 通常の`git diff`では本文が表示されない

未追跡ファイルは、ユーザーが明示した対象ファイルに限りReadで確認させます。

参照禁止対象が指定された場合は読ませず、理由を報告させます。

レビューでは差分確認を省略しません。

以前のセッション内容やファイル全文だけを見て、過去の変更を今回のPR差分として指摘させないでください。

## 起動前チェック

Claude Codeを起動する前に、人間が次を確認します。

```bash
git status --short
git branch --show-current
```

確認する内容:

- 作業ブランチがレビュー対象ブランチである
- `main`ブランチでレビュー作業を開始していない
- 意図しない未コミット変更が混ざっていない
- staged、unstaged、untrackedのどれをレビューするか明確である
- PR差分レビューの対象ファイルが明確である
- `.env`や秘密情報が差分へ混入していない
- キャッシュ、ログ、セッション、生成Viewなどが差分へ混入していない
- 依存関係の意図しない変更が混ざっていない
- Issueの受け入れ条件が確認できる状態である

Claude Codeにブランチ切り替えやPullを依頼しません。

ブランチ準備、最新化、コミット、push、PR作成は人間が行います。

## 読ませてはいけないもの

Claude Codeに次のファイル、ディレクトリ、データを読ませません。

要約、引用、コピー、検索対象化も禁止します。

- `.env`
- `.env.*`（秘密情報を含まない`.env.example`を除く）
- `bootstrap/cache/`
- `storage/logs/`
- `storage/private/`
- `storage/app/private/`
- `storage/framework/sessions/`
- `storage/framework/cache/`
- `storage/framework/views/`
- SQL dump
- データベースバックアップ
- 秘密鍵
- tokenファイル
- 認証情報ファイル
- APIキーを含むローカル設定
- 個人情報を含むローカルデータ
- Git管理外の秘密情報

設定例やDB構造の確認が必要な場合は、秘密情報を含まない次の資料を使用します。

- `.env.example`
- README
- `docs/`
- マイグレーション
- Factory
- Seeder
- テスト用の合成データ

`.env.example`のReadには確認画面が表示される場合があります。人間が対象ファイル名を確認し、`.env.example`である場合だけ許可します。それ以外の`.env.*`は許可しません。

`database/`や`storage/`全体を一律に禁止するのではなく、マイグレーション、Factory、Seederなど、レビューに必要なGit管理対象ファイルだけを明示して読ませます。

## 変更系コマンドの禁止

このレビューでは、ユーザーが依頼した場合でも、Claude Codeに変更系コマンドを実行させません。

### 変更系Artisan

禁止する代表例:

```text
key:generate
make:*
migrate*
db:seed
db:wipe
schema:dump
config:cache
config:clear
route:cache
route:clear
view:cache
view:clear
event:cache
event:clear
cache:clear
optimize
optimize:clear
storage:link
storage:unlink
vendor:publish
tinker
queue:work
queue:listen
queue:restart
schedule:run
schedule:work
down
up
model:prune
auth:clear-resets
```

この一覧に記載されていないコマンドでも、ファイル、DB、キャッシュ、設定、プロセス状態を変更するものはすべて禁止します。

### Composer

次を許可しません。

```text
composer install
composer update
composer require
composer remove
```

Sail経由も同様です。

`.claude/settings.json`では、`./vendor/bin/sail`形式と`sail`エイリアス形式の代表的な変更系コマンドをdenyします。別表記を完全には防げないため、すべてのBash承認画面でコマンド全体を確認します。

### npm

次を許可しません。

```text
npm install
npm ci
npm update
npm uninstall
npm run build
```

Sail経由も同様です。

`.claude/settings.json`では、`./vendor/bin/sail`形式と`sail`エイリアス形式の代表的な変更系コマンドをdenyします。別表記を完全には防げないため、すべてのBash承認画面でコマンド全体を確認します。

### ファイル変更を伴うその他の操作

次を許可しません。

- 通常のPint実行
- Vite build
- ファイル作成・更新・削除
- ディレクトリ作成・削除
- シンボリックリンク作成・削除
- DB変更
- キャッシュ作成・削除
- レビュー成果物としてのプロジェクト内のPlanファイル作成
- レビュー成果物としてのプロジェクト内のmemoryファイル作成
- メモファイル作成
- ブラウザ操作
- 外部APIへの接続

## 確認系コマンドの扱い

対象限定テスト、静的解析、フォーマット確認は、人間が正確なコマンドを明示した場合だけ実行候補にします。

Skill側にコマンドを推測させません。

### 許可候補

```bash
./vendor/bin/sail artisan route:list
```

```bash
./vendor/bin/sail artisan test tests/Feature/ReviewMineTest.php
```

```bash
./vendor/bin/sail php ./vendor/bin/phpstan analyse app/Http/Controllers/ReviewController.php
```

```bash
./vendor/bin/sail php ./vendor/bin/pint app/Http/Controllers/ReviewController.php --test
```

通常Pintと`--test`付きPintを、Bashのdenyパターンだけで完全に分離できるとは扱いません。広いPint denyは、denyがAskより先に評価されるため、許可対象の`--test`付きコマンドまで塞ぎます。人間が承認画面で対象パスと末尾の`--test`を毎回確認します。

対象限定テストは、テスト用DBへ一時的な書き込みを行う場合があります。

そのため、ユーザーが対象とコマンドを明示した場合だけ実行候補とし、Skill側で対象を広げさせません。

全テスト、coverage、通常Pint、build、外部通信を伴うテストは既定では実行しません。

実行していないテスト、PHPStan、Pint、buildを、実行済みとして報告させません。

## Laravel PRレビューの優先観点

PR差分を、Issueの受け入れ条件と既存ドキュメントに照らしてレビューします。

### 1. Issueとスコープ

- Issueの目的と受け入れ条件を満たしているか
- 要件外の機能追加やリファクタリングが混入していないか
- 1つのPRへ複数目的が混在していないか
- 後続フェーズの機能を先行実装していないか
- 追加改善を別Issue候補として区別できているか

### 2. Laravel標準機能と責務

- 認証、認可、バリデーション、CSRF、Bladeエスケープ、EloquentでLaravel標準機能を優先しているか
- Controllerが肥大化していないか
- Form Requestへ入力検証を分離できているか
- PolicyまたはGateで認可を適切に扱っているか
- Serviceが必要な処理と、不要な過剰抽象化を区別できているか
- Model、Controller、Viewへ責務が不自然に分散していないか
- Breeze標準機能を不必要に置き換えていないか

### 3. 認証・認可・セキュリティ

- 会員機能に`auth` middlewareが適用されているか
- 本人以外のレビュー、返信、プロフィールを操作できないか
- Route Model Bindingだけに依存せず、必要な認可を行っているか
- IDORが発生しないか
- CSRF保護を外していないか
- Bladeの自動エスケープを無効化していないか
- `{!! !!}`の利用に妥当な根拠があるか
- Mass Assignment対策が維持されているか
- 機密情報、個人情報、認証情報をログや画面へ出力していないか
- `.env`の値をコードへ直書きしていないか

### 4. バリデーション

- Form RequestまたはLaravel標準バリデーションを使用しているか
- 必須、nullable、型、文字数、数値範囲が要件と一致しているか
- レビュー評価が1から5の範囲を維持しているか
- レビュー本文、返信本文、自己紹介などの最大文字数が仕様と一致しているか
- エラーメッセージと入力保持が適切か
- DB制約とアプリケーション側の検証が矛盾していないか

### 5. DB・Eloquent

- マイグレーションと`docs/DATABASE.md`が一致しているか
- 外部キー、UNIQUE制約、nullable、削除時動作が仕様と一致しているか
- EloquentリレーションがDB構造と一致しているか
- N+1クエリを発生させていないか
- 必要なEager Loadingがあるか
- 不要な全件取得を行っていないか
- ページネーション要件を維持しているか
- トランザクションが必要な複数更新を安全に扱っているか
- 会員退会後のレビュー・返信匿名表示方針を壊していないか

### 6. 評価キャッシュ

- `items.rating`と`items.rating_count`の整合性を維持しているか
- レビュー投稿・削除時の再計算が必要な箇所で行われているか
- DB更新と評価キャッシュ更新の途中失敗を考慮しているか
- 重複レビューを防ぐUNIQUE制約とアプリケーション側検証を維持しているか
- 平均値の丸め方が既存仕様と一致しているか

### 7. ルート・Controller・画面遷移

- HTTPメソッド、URL、ルート名、Controllerが`docs/ROUTES.md`と一致しているか
- GETでデータ変更を行っていないか
- redirect先とフラッシュメッセージが要件と一致しているか
- ゲストと会員の表示・操作境界を壊していないか
- 既存の画面遷移を壊していないか
- ルート名を使わずURLを不必要に直書きしていないか

### 8. Blade・UI・アクセシビリティ

- Bladeコンポーネントや既存レイアウトを適切に再利用しているか
- フォームの`label`と入力要素が関連付いているか
- バリデーションエラーが対象入力と関連付いているか
- `aria-describedby`、`aria-invalid`など既存のアクセシビリティ方針を壊していないか
- キーボード操作を阻害していないか
- ボタンとリンクの役割を混同していないか
- 既存のTailwind CSS設計から不必要に逸脱していないか
- モバイル表示を明らかに破壊していないか

### 9. テスト

- Issueの受け入れ条件に対応するFeatureテストがあるか
- 正常系だけでなく未認証、認可違反、バリデーション失敗を確認しているか
- 境界値を確認しているか
- DB保存内容を具体的に確認しているか
- redirect先、フラッシュメッセージ、画面表示を必要に応じて確認しているか
- Factoryが実運用の制約と矛盾していないか
- 既存テストの意図を弱めていないか
- テストを通すためだけの実装や過剰なモックになっていないか
- 外部API連携はHTTP fakeなどで実通信を避けているか

### 10. ドキュメント

- 実装変更に応じてREADMEまたは関連docsが更新されているか
- `docs/REQUIREMENTS.md`
- `docs/FEATURES.md`
- `docs/SCREEN_TRANSITIONS.md`
- `docs/DATABASE.md`
- `docs/ROUTES.md`
- `docs/SECURITY.md`
- `docs/IMPLEMENTATION_PLAN.md`
- `docs/COMMANDS.md`
- `docs/DEPLOYMENT.md`

変更と無関係なドキュメントを広範囲に書き換えていないかも確認します。

### 11. コード品質

- 変数名、メソッド名、クラス名が責務を表しているか
- 不要な重複がないか
- 複雑な条件分岐が増えていないか
- Laravelの規約や既存コードの書き方と大きく食い違っていないか
- PHPStanで問題になり得る型の曖昧さがないか
- コメントがコードの説明ではなく、判断理由を補足しているか
- 過剰なコメントやAI生成らしい冗長な説明が混入していないか

## Claude Code起動後の安全確認プロンプト例

Claude Code起動後、レビューを依頼する前に、次の安全確認を行います。

```text
このリポジトリでは、Claude CodeをPR差分レビュー専用の読み取り専用セカンドオピニオンとして使います。

まずCLAUDE.mdとAGENTS.mdを確認し、このセッションで守る禁止事項を要約してください。

条件:
- この安全確認ではBashを実行しないでください。
- ファイル編集はしないでください。
- Edit、Write、NotebookEditを使用しないでください。
- .env、.env.example以外の.env.*、bootstrap/cache/、storage/logs/、storage/private/、storage/app/private/、storage/framework/sessions/、storage/framework/cache/、storage/framework/views/、SQL dump、バックアップ、秘密鍵、token、認証情報、個人情報を読まないでください。
- Gitの変更系操作は行わないでください。
- 変更系Artisan、Composer、npm、通常Pint、buildを実行しないでください。
- レビュー成果物として、プロジェクト内にmemory、plan、メモファイルを作成しないでください。
- まだレビューや修正提案には進まず、禁止事項の要約だけを出してください。
```

この段階で禁止事項の理解が不十分な場合は、PR差分レビューに進みません。

## Skillが利用できない場合のPR差分レビュー用プロンプト例

Skillが利用できない場合は、次のプロンプトでレビュー範囲と禁止事項を明示します。

```text
このPR差分だけをレビューしてください。

修正、ファイル編集、Git変更操作は行わないでください。
Issueの受け入れ条件を最優先とし、要件外の改善は現在のPRの必須修正と混同せず、別Issue候補として区別してください。

レビュー対象:
- <対象ファイル>
- <対象ファイル>

参照許可:
- AGENTS.md
- CLAUDE.md
- README.md
- <関連するdocs>
- <対象ファイル>
- <対象テスト>

使用可能な読み取り系Gitコマンド:
- git status --short
- git branch --show-current
- git diff -- <指定されたファイル>
- git diff --cached -- <指定されたファイル>
- git diff HEAD -- <指定されたファイル>
- git diff --check

実行を許可する確認系コマンド:
- <人間が明示した対象限定コマンドだけを記載する>
- 許可しない場合は「なし」と記載する

禁止事項:
- 指定範囲外を参照しない
- Edit、Write、NotebookEditを使わない
- ファイルを作成、更新、削除しない
- Git変更操作をしない
- 変更系Artisan、Composer、npm、通常Pint、buildを実行しない
- .env、.env.example以外の.env.*、bootstrap/cache/、storage/logs/、storage/private/、storage/app/private/、storage/framework/sessions/、storage/framework/cache/、storage/framework/views/を読まない
- SQL dump、バックアップ、秘密鍵、token、認証情報、個人情報を読まない
- レビュー成果物として、プロジェクト内にmemory、plan、メモファイルを作成しない
- 外部通信を行わない
- 実行していない確認を実行済みと報告しない

レビュー観点:
- Issueの受け入れ条件とPR差分が一致しているか
- 要件外の変更が混入していないか
- Laravel標準の認証、認可、バリデーション、CSRF、Bladeエスケープ、Eloquentを維持しているか
- Controller、Form Request、Policy、Service、Model、Viewの責務が適切か
- DB制約、Eloquentリレーション、削除時動作が設計と一致しているか
- items.ratingとitems.rating_countの評価キャッシュ整合性を壊していないか
- 会員退会後の匿名表示方針を壊していないか
- HTTPメソッド、ルート名、画面遷移が設計と一致しているか
- 認証、認可、正常系、異常系、境界値のテストが揃っているか
- Bladeフォームのアクセシビリティを壊していないか
- README、docs、実装、テストの整合性が取れているか

出力形式:

## レビュー範囲

## Issue受け入れ条件との対応

## 指摘事項

### High
- セキュリティ、認可漏れ、データ破損、秘密情報、重大な仕様違反など、マージ前に必ず直すべき問題

### Medium
- 仕様不整合、DB整合性、テスト不足、画面遷移や運用上の問題など、修正または明確な判断が必要な問題

### Low
- 表記ゆれ、説明不足、保守性、軽微なアクセシビリティ改善など、必要に応じて直す問題

## 別Issue候補
- 現在のIssueの受け入れ条件外だが、後続対応を検討できる事項
- 特になければ「特になし」

## 確認できたこと

## 実行した確認

## 実行していない確認

## 人間が追加確認すべきこと

注意:
- 指摘だけを出してください。
- 実際の編集はしないでください。
- 修正案は方針レベルに留めてください。
- 事実、推測、提案を区別してください。
- 根拠となるファイル、クラス、メソッド、ルート、テーブルを示してください。
- High、Medium、Lowに指摘がない場合も「特になし」と記載してください。
```

High、Medium、Lowの分類は、人間が優先順位を判断するために使用します。

Claude Codeの分類をそのまま採用せず、Issue、影響範囲、既存ドキュメント、プロジェクトのMVP方針に照らして人間が判断します。

## レビュー結果の扱い

- Claude Codeの指摘はそのまま採用しません。
- 指摘の根拠となるIssue、差分、実装、テスト、docsを人間が確認します。
- Highは、原則としてマージ前に解消します。
- Mediumは、修正するか、仕様判断として明示するかを人間が決定します。
- Lowは、現在のPRで直すか、別Issueへ分けるか、対応しないかを人間が決定します。
- 要件外の改善は、現在のPRへ安易に追加せず、別Issue候補として扱います。
- 修正はClaude Codeに行わせません。
- コミット、push、PRコメント、マージは人間が行います。
- 実行していない検証をマージ判断の根拠にしません。

## トラブル対応

### Claude CodeがBash許可を求めた場合

コマンド内容を確認し、PR差分レビューに必要な読み取り系または人間が明示した対象限定確認だけを許可します。

変更系Git、許可されたIssue参照以外の外部通信、禁止ファイル参照、変更系Artisan、Composer、npm、通常Pint、buildは許可しません。Bash denyは完全防御ではないため、コマンド全体を人間が確認します。

### 複合コマンドを提示した場合

許可せず、次のように指示します。

```text
複数の処理を1つのBashコマンドへ連結しないでください。

パイプ、セミコロン、&&、||、&、改行を使わず、
1回の確認につき1コマンドへ分割してください。

各コマンドを提示する前に、確認目的を1文で説明してください。
```

### ファイル変更を要求した場合

許可せず、次のように指示します。

```text
このレビューでは、Bash経由を含めてファイルの作成、更新、削除を許可しません。

Edit、Write、NotebookEditを使用しないでください。
レビュー成果物として、プロジェクト内にmemory、plan、メモファイルを作成しないでください。

cat >、cat >>、tee、>、>>、ヒアドキュメントを使用せず、
読み取り系Gitコマンドと指定済みの対象限定確認だけで続けてください。

レビュー結果はチャットへ直接出力してください。
```

### 禁止対象を読もうとした場合

許可せず、セッションを止めます。

必要に応じて新しいセッションを開始し、`CLAUDE.md`、`AGENTS.md`、参照禁止対象、レビュー範囲を再確認させます。

### 未認識denyルール警告が出た場合

`.claude/settings.json`のdenyルールが期待どおり解釈されていない可能性があります。

警告内容を確認し、禁止対象の保護が有効だと判断できるまでレビューに進みません。

その場で権限を緩めません。

設定修正は人間が行い、必要に応じて別Issueで管理します。

### Sailまたはテスト環境に問題がある場合

Claude Codeに次を行わせません。

- Sail設定変更
- `.env`変更
- パッケージ導入
- Composer更新
- npm install
- DB再構築
- マイグレーション
- キャッシュ削除
- 権限変更

実行できなかった確認をレビュー結果へ記載させ、必要な検証は人間またはCodex側で行います。

### 対象限定テストが失敗した場合

失敗内容を、差分に起因する可能性と環境要因の可能性に分けて報告させます。

修正、設定変更、DB操作はさせません。

失敗を隠したり、未実行として扱ったりさせません。

### プロジェクト内にPlanまたはmemoryファイルを作成しようとした場合

PR差分レビューでは不要です。

ファイル作成を許可せず、チャットへ直接レビュー結果を出力させます。

恒久的な書き込み許可を追加しません。

plan modeで`/pr-diff-review`を起動すること自体は禁止しません。ただし、計画を承認して編集へ移行せず、レビュー結果、計画、メモをプロジェクトファイルへ保存させません。

### セッションが長くなった場合

レビュー範囲、確認済み事項、未解決の指摘、未実行の確認を整理して区切ります。

文脈が曖昧な状態でレビュー対象を広げたり、ファイル編集へ進ませたりしません。

### `claude --resume`または`/compact`を使う場合

前回の禁止事項、レビュー範囲、Skill手順が継続していると仮定しません。

再開後に次を行います。

1. `CLAUDE.md`と`AGENTS.md`を再確認する
2. `/status`でcwd、Setting sources、設定エラーの有無を確認する
3. ステータスバーまたはConfig画面でpermission modeがManualであることを確認する
4. `/permissions`でAllowが0件、AskにBashがあること、有効なDenyと各ルールの保存元を確認する
5. `/pr-diff-review`を再度呼び出す
6. レビュー対象と許可する確認系コマンドを再指定する
7. 実行していない確認を実行済みとして扱わない

## レビュー終了時の確認

Claude Codeのレビュー終了後、人間が次を確認します。

- レビュー対象が指定した差分だけだった
- 参照禁止対象を読んでいない
- ファイル変更が発生していない
- Git変更操作を行っていない
- 変更系Artisan、Composer、npm、通常Pint、buildを実行していない
- 実行した確認と未実行の確認が区別されている
- Issueの受け入れ条件との対応が説明されている
- 要件外の改善が別Issue候補として区別されている
- High、Medium、Lowの根拠が示されている
- Claude Codeの指摘を人間が一次資料と差分で再確認した

必要に応じて、Claude Code終了後に人間が次を確認します。

```bash
git status --short
git diff --check
```

意図しない変更が発生していないことを確認してから、修正、コミット、push、PR更新、マージ判断へ進みます。
