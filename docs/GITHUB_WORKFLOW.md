# GitHub開発運用ガイド

## 1. 目的と適用範囲

このドキュメントは、映画レビューアプリ Laravel移植版におけるGitHub開発運用の正本です。

Issue、Labels、Milestone、ブランチ、Pull Request（PR）、マージ、マージ後整理、`develop`から`main`への同期を扱います。設計からレビューまでの開発全体フローは[開発フロー](DEVELOPMENT_FLOW.md)、コマンド単体の用途と書式は[コマンド集](COMMANDS.md)、失敗や想定外状態の切り分けは[トラブルシューティング](TROUBLESHOOTING.md)を参照してください。

## 2. 基本原則

- `main`は公開・リリース可能な安定状態を保持する
- `develop`は次回リリースへ向けた統合ブランチとする
- 作業ブランチを作成する前にIssueを作成する
- 作業ブランチは最新の`develop`から作成する
- 通常PRは作業ブランチから`develop`へ作成する
- 同期PRは`develop`から`main`へ作成する
- 通常PRと同期PRはMerge commit方式でマージする
- 通常の作業コミットを`main`または`develop`へ直接pushしない
- force pushは使用しない
- Issueは対象変更が`main`へ反映された時点でCloseする
- 異常や想定外の履歴を検知した場合は、履歴変更操作へ切り替えず停止する

現在のGitHubリポジトリでは、Allow merge commitsを有効、Allow squash mergingとAllow rebase mergingを無効にしています。これはマージ方式の設定であり、`develop`のRuleset、CIのrequired status checks、マージ後のhead branch自動削除を保証するものではありません。これらは現時点では未導入で、必要に応じて後続Issueで扱います。

## 3. Issue

### 3.1 Issue作成前の確認

Issueを作成する前に、次を確認します。

- 同じ目的のopen Issueがないか
- 現在の要件、機能一覧、設計文書と矛盾しないか
- 1つの作業目的として説明できるか
- 実装対象と対象外を区別できるか
- 完了を判断できる受け入れ条件があるか
- 対象となるType Label、Priority Label、Milestoneが明確か

### 3.2 Issue先行作成

作業ブランチは、Issue番号と受け入れ条件が確定してから作成します。Issue本文には、目的、背景、対応内容、対象外、受け入れ条件を必要な範囲で記載します。

受け入れ条件はチェックボックス形式にします。

```markdown
## 受け入れ条件

- [ ] 完了条件を具体的に記載する
- [ ] 関連ドキュメントが更新されている
```

### 3.3 Issueタイトル

```text
<種別>: <日本語で作業内容を「〜する」形式で記載>
```

例：

```text
docs: GitHub開発運用ガイドを整備する
feat: 管理者権限を実装する
fix: レビュー削除時のフォーカス制御を修正する
```

## 4. LabelsとMilestone

Labelsは作業の種類と優先度、MilestoneはMVPやリリースなどの反映単位を表します。

### 4.1 Type Labels

`Color`はGitHubでLabelを作成する際に指定する、`#`を含む6桁の16進カラーコードです。Labelの分類は名前と用途を正本とし、色は一覧性を補助するために使用します。

| Label | 用途 | Color |
| --- | --- | --- |
| `type: feature` | 新機能、振る舞いが変わる改善 | `#1D76DB` |
| `type: bug` | 不具合修正 | `#D73A4A` |
| `type: security` | 脆弱性対応、セキュリティ改善 | `#5319E7` |
| `type: documentation` | README、docsなどの文書変更 | `#0075CA` |
| `type: refactor` | 振る舞いを変えない内部構造の改善 | `#D87600` |
| `type: test` | テストの追加・修正・改善 | `#0E8A16` |
| `type: ci` | GitHub Actions、CI/CDの変更 | `#FBCA04` |
| `type: chore` | 設定、依存関係、開発環境などの保守作業 | `#6E7781` |

### 4.2 Priority Labels

| Label | 用途 | Color |
| --- | --- | --- |
| `priority: high` | MVPまたは公開に必要で、優先して対応する | `#B60205` |
| `priority: medium` | 通常の優先度で対応する | `#FBCA04` |
| `priority: low` | 後回しにできる改善項目 | `#0E8A16` |

新規Issueでは、原則として新しい`type:`と`priority:`のLabelを使用します。既存IssueのLabel移行や旧Labelの整理は、このガイドの導入作業には含めません。

### 4.3 Type Label・ブランチ・コミット種別

| Type Label | ブランチの主種別 | 主なコミット種別 | 用途 |
| --- | --- | --- | --- |
| `type: feature` | `feat` | `feat` | 新機能、振る舞いが変わる改善 |
| `type: bug` | `fix` | `fix` | 不具合修正 |
| `type: security` | `security` | `security` | 脆弱性対応、セキュリティ改善 |
| `type: documentation` | `docs` | `docs` | README、docsなどの文書変更 |
| `type: refactor` | `refactor` | `refactor` | 振る舞いを変えない内部構造の改善 |
| `type: test` | `test` | `test` | テストの追加・修正・改善 |
| `type: ci` | `ci` | `ci` | GitHub Actions、CI/CDの変更 |
| `type: chore` | `chore` | `chore` | 設定、依存関係、開発環境などの保守作業 |

ブランチ種別はIssueの主目的を表します。1つの作業ブランチ内に、`test:`や`docs:`など、ブランチの主種別と異なる種別のコミットが含まれても構いません。

## 5. ブランチ

### 5.1 ブランチの役割

```text
main
  ↑
develop
  ↑
作業ブランチ
```

| ブランチ | 役割 | 通常の更新方法 |
| --- | --- | --- |
| `main` | 公開・リリース可能な安定状態 | `develop`からの同期PR |
| `develop` | 次回リリースへ向けた統合先 | 作業ブランチからの通常PR |
| 作業ブランチ | 1つのIssueを実装・修正する場所 | 最新の`develop`から作成 |

### 5.2 作業ブランチ名

```text
<種別>/<Issue番号>-<英小文字kebab-case>
```

使用する種別は次の8種類です。

```text
feat/
fix/
docs/
refactor/
test/
ci/
chore/
security/
```

例：

```text
docs/64-add-github-workflow
feat/65-add-admin-role
```

## 6. 命名規則

### 6.1 通常PRタイトル

原則として対応するIssueと同じタイトルにします。1つのIssueを複数PRへ分割する場合は、対応範囲が分かる補足を付けます。

### 6.2 同期PRタイトル

単一Issueの場合：

```text
release: Issue #<番号>の変更をmainへ反映する
```

複数Issueまたはリリース単位の場合：

```text
release: <反映対象>をmainへ反映する
```

`release:`は同期PRタイトル専用です。作業ブランチや通常コミットの種別には使用しません。

### 6.3 コミットメッセージ

```text
<種別>: <日本語で変更内容を簡潔に記載>
```

例：

```text
docs: GitHub開発運用ガイドを追加
docs: 既存ドキュメントのGitHub運用記述を整理
feat: 管理者権限を追加
```

- 種別は英字、変更内容は日本語で記載する
- 1目的1コミットを基本とする
- 関係のない変更を同じコミットへ含めない
- 既存コミットの表記は遡及して変更しない

## 7. 最新のdevelopから作業を開始する

Issueを作成し、Issue番号、受け入れ条件、ブランチ名を確認してから実行します。

```bash
git status --short
git switch develop
git pull --ff-only origin develop
git status --short
git switch -c <種別>/<Issue番号>-<英小文字kebab-case>
```

最初の`git status --short`で未コミット変更がある場合や、`git pull --ff-only`が失敗した場合は作業ブランチを作成せず停止します。

## 8. 実装中から初回pushまで

### 8.1 実装中の差分確認

変更の区切りごとに状態と差分を確認します。

```bash
git status
git diff
```

コマンドの詳細やステージ済み差分との違いは[コマンド集](COMMANDS.md)を参照してください。

### 8.2 コミット前確認

- 現在のブランチが対象Issueの作業ブランチである
- Issueの対象外の変更が含まれていない
- 秘密情報、認証情報、ローカル専用情報が含まれていない
- `.env`、`vendor/`、`node_modules/`が含まれていない
- 変更に必要なテスト、Pint、PHPStan、Vite buildなどを確認した
- ドキュメントのみの場合は差分とリンクを確認した
- ステージ後の差分がコミット目的と一致している

```bash
git status
git diff
git diff --staged
```

### 8.3 初回push

コミット後、作業ブランチを初めてpushするときは次の形式を使用します。

```bash
git push -u origin <作業ブランチ名>
```

`main`や`develop`をpush先に指定しません。pushが拒否された場合はforce pushへ切り替えず停止します。

## 9. 通常PR

### 9.1 作成

- Head：作業ブランチ
- Base：`develop`
- タイトル：原則としてIssueタイトルと同じ
- 本文：`Refs #<Issue番号>`を記載する

通常PRでは`Closes`を使用しません。Issueは`develop`へのマージ時点ではCloseせず、同期PRによって`main`へ反映された時点でCloseします。

### 9.2 マージ前確認

通常PR本文には、変更内容に応じたマージ前確認をチェックボックス形式で記載します。

```markdown
## 関連Issue

Refs #<Issue番号>

## マージ前確認

- [ ] Baseが`develop`、Headが対象の作業ブランチである
- [ ] Issueの受け入れ条件とPR差分が一致している
- [ ] 関係のない変更や秘密情報が含まれていない
- [ ] 必要なローカル確認が完了している
- [ ] GitHub ActionsのCIがすべて成功している
- [ ] レビュー指摘と未解決の会話を確認した
- [ ] Merge commit方式でマージする
```

Issue・PRテンプレートは現時点では導入しません。必要性が生じた場合は後続Issueで検討します。

### 9.3 レビューとマージ

1. Issueの受け入れ条件と差分を照合する
2. 必要なテストと文書更新を確認する
3. GitHub ActionsのCIがすべて成功していることを確認する
4. レビュー指摘と未解決の会話を確認する
5. Baseが`develop`であることを再確認する
6. Merge commit方式で`develop`へマージする

CIが失敗中、未完了、または結果を確認できない場合はマージしません。CIの実行自体と、CIをRulesetのrequired status checksとして強制する設定は別のものです。

### 9.4 通常PRマージ後の整理

GitHub上で通常PRがマージ済みであることを確認し、PR画面のDelete branchからリモート作業ブランチを人間が削除してからローカルを整理します。head branchの自動削除は現時点では設定していません。

```bash
git status --short
git switch develop
git pull --ff-only origin develop
git branch -d <作業ブランチ名>
git fetch --prune origin
git status --short
git branch -a
```

未コミット変更がある場合、`git pull --ff-only`が失敗した場合、`git branch -d`が失敗した場合は停止します。`git branch -D`へ切り替えません。

## 10. developからmainへの同期PR

### 10.1 反映単位

公開前のMVP2開発中は、各Issueを通常PRで`develop`へ統合し、MVP2の公開条件を満たした時点でまとめて`main`へ反映します。

```text
各Issue
作業ブランチ → develop

MVP2公開条件を満たした時点
develop → main
```

公開後は、本番反映可能な1機能・1修正などの単位で同期PRを作成します。

```text
本番反映可能な1機能・1修正単位
作業ブランチ → develop → main
```

### 10.2 同期PRの作成

- Head：`develop`
- Base：`main`
- タイトル：同期PRの命名規則に従う
- 本文：反映対象の各Issueを`Closes #<Issue番号>`で記載する

複数Issueを反映する場合は、Issueごとに`Closes`を記載し、反映対象と対象外を明確にします。

### 10.3 反映前確認

同期PR本文には、反映前確認をチェックボックス形式で記載します。

```markdown
## 反映対象Issue

Closes #<Issue番号>

## 反映前確認

- [ ] Baseが`main`、Headが`develop`である
- [ ] 反映対象Issueと`Closes`の一覧が一致している
- [ ] 公開前または公開後の反映単位に合っている
- [ ] `develop`へ対象外の変更が混入していない
- [ ] 必要なローカル確認が完了している
- [ ] GitHub ActionsのCIがすべて成功している
- [ ] レビュー指摘と未解決の会話を確認した
- [ ] Merge commit方式でマージする

## マージ後確認

- [ ] ローカルの`main`を最新化した
- [ ] ローカルの`develop`を最新化した
- [ ] `develop`を`main`へfast-forward同期した
- [ ] force pushを使用していない
- [ ] `main`、`develop`、リモート参照の位置を確認した
```

### 10.4 Merge commit方式が必要な理由

同期PRをMerge commit方式でマージすると、`main`に作られた同期PRのMerge commitは、同期時点の`develop`を親として履歴に含みます。そのため、同期PR直後の`develop`は`main`の祖先となり、`git merge --ff-only main`で新しいMerge commitを作らずに`main`と同一コミットへ進められます。

Squash mergeやRebase mergeでは同期元の履歴をそのまま親に持つMerge commitが作られず、このfast-forward同期の前提が崩れます。そのため、通常PRと同期PRはMerge commit方式に統一します。

## 11. 同期PRマージ後のfast-forward同期

同期PRをMerge commit方式で`main`へマージした直後に、人間が次を実行します。

```bash
git status --short
git fetch --prune origin

git switch main
git pull --ff-only origin main

git switch develop
git pull --ff-only origin develop
git merge --ff-only main
git push origin develop

git status --short
git branch -a
git log --oneline --decorate -5
```

### 11.1 成立条件

- 同期PRがMerge commit方式でマージされている
- `git status --short`に意図しない変更がない
- 直前の`git pull --ff-only origin main`が成功し、ローカル`main`が最新である
- `develop`が同期PRのマージ後に別の履歴へ進んでいない
- `git merge --ff-only main`が成功している

`git merge --ff-only main`は、直前の`main`最新化が成功していることを前提とします。

### 11.2 develop直接pushの限定例外

`git push origin develop`は、同期PRのマージ直後に、成功した`git merge --ff-only main`の結果を反映して`develop`を`main`と同一コミットへ揃える場合だけ許可する限定例外です。

- 通常の作業コミットを`develop`へ直接pushしない
- この例外を通常PRの代替にしない
- force pushを使用しない
- pushが拒否された場合は停止する
- 非fast-forward merge、rebase、reset、force pushへ切り替えない

### 11.3 最終確認

最後の`git status --short`、`git branch -a`、`git log --oneline --decorate -5`で、次を確認します。

- 未コミット変更がない
- ローカルの`main`と`develop`が同じ同期PRのMerge commitを指している
- `origin/main`と`origin/develop`が想定した位置にある
- 意図しない作業ブランチやリモート追跡ブランチが残っていない
- 同期PRより後の想定外コミットがない

同期未完了を検知するため、最後の履歴確認を省略しません。位置が想定と異なる場合は、追加のmergeやpushを行わず停止します。

## 12. 停止条件

次の場合は、その手順を中断して[トラブルシューティング](TROUBLESHOOTING.md)で状態を切り分けます。

- 作業開始前またはブランチ切り替え前に未コミット変更がある
- `git pull --ff-only`が失敗した
- `git merge --ff-only`が失敗した
- `git push origin develop`が拒否された
- `git branch -d`が失敗した
- 通常PRまたは同期PRのBaseとHeadが想定と異なる
- 同期PRでMerge commit以外を選択した
- GitHub ActionsのCIが失敗中、未完了、または結果を確認できない
- `main`と`develop`、またはローカルとリモート参照の位置が想定と異なる
- 関係のないファイル、秘密情報、認証情報、ローカル専用情報の混入が疑われる

停止後は、原因確認前に作業を継続しません。force push、resetによる履歴の書き換え、非fast-forward merge、rebaseへ切り替えません。

## 13. 人間とAIの役割境界

- ブランチ作成・切り替え・削除、`git add`、commit、push、pull、fetch、merge、rebase、reset、restore、stashなどのGit変更操作とマージ判断は人間が行う
- Issue・PR・Labels・Milestone・Ruleset・GitHub Settingsの変更操作は人間が行う
- AIは、ユーザーから明示的に依頼された範囲で、調査、設計相談、提案、ファイル作成・編集、差分レビューを担当できる
- AIがコマンドを実行する場合は、プロジェクトで許可された読み取り専用確認に限定する
- AIはGitおよびGitHubの変更操作を代行せず、許可されていないファイルや秘密情報を読まない
- AIは実行していない確認を実行済みとして扱わず、ユーザーから明示的に依頼された範囲を超えて編集しない

セキュリティ原則は[AGENTS.md](../AGENTS.md)、Claude Code固有の読み取り専用制約は[CLAUDE.md](../CLAUDE.md)を正本とします。この文書では詳細を重複させません。

## 14. 関連ドキュメント

- [README](../README.md)
- [AGENTS.md](../AGENTS.md)
- [開発フロー](DEVELOPMENT_FLOW.md)
- [コマンド集](COMMANDS.md)
- [トラブルシューティング](TROUBLESHOOTING.md)
- [デプロイ方針](DEPLOYMENT.md)
- [実装計画](IMPLEMENTATION_PLAN.md)
- [要件定義](REQUIREMENTS.md)
- [Claude Codeレビュー運用手順](CLAUDE_CODE_REVIEW.md)
