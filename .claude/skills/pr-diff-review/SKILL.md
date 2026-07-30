---
name: pr-diff-review
description: 指定されたPR差分を、映画レビューアプリLaravel移植版向けの観点で、読み取り系Gitコマンドとユーザーが明示した対象限定テストだけで安全にレビューする。
argument-hint: "[レビュー対象ファイルまたは差分範囲] [任意: 実行を許可する対象限定テスト]"
disable-model-invocation: true
---

# PR差分レビューSkill

このSkillは、Claude Codeで指定されたPR差分だけを安全にレビューするための手順です。修正は行わず、レビュー結果だけを出します。

## 基本方針

- `CLAUDE.md` と `AGENTS.md` の禁止事項を先に確認します。
- `$ARGUMENTS` からレビュー対象ファイル、差分範囲、任意の対象限定テストを確認します。
- レビュー対象が不明確な場合は、差分確認へ進まずユーザーへ確認します。
- 指定範囲以外へレビュー対象を広げません。
- 今回の差分と既存コード・過去の変更を区別します。
- 実行していないテストを実行済みと報告しません。
- `Edit`、`Write`、`NotebookEdit` は使用しません。
- 動的シェル埋め込みは使用しません。
- Skillが自動実行される構成にしません。
- サブエージェントを使用しません。
- `allowed-tools` は設定しません。すべてのBashはAskです。承認ダイアログでは`CLAUDE.md`の運用に従い、その回だけ`Yes`で承認を受けます。権限設定とSkill本文はベストエフォートの補助線とし、人間の判断を最終境界にします。
- Bashは原則として1回の確認につき1コマンドにし、各コマンドの前に確認目的を短く説明します。
- `|`、`|&`、`;`、`&&`、`||`、`&`、改行で複数処理を連結しません。
- 複合コマンドが必要に見える場合も、ユーザーへ提示する前に単一コマンドへ分割します。
- Bash経由でもファイルを作成・更新・削除しません。
- レビュー成果物として、プロジェクト内にmemory、plan、メモファイルを作成しません。
- `cat >`、`cat >>`、`tee`、`>`、`>>`、ヒアドキュメント `<<` は使用しません。
- Read専用レビューの結果はチャットへ直接出力します。
- Bash denyは別表記、ラッパー、スクリプト、間接操作を完全には防がないベストエフォートの防御です。すべてのBash承認画面で、人間が書き込み、外部通信、禁止対象参照、複合コマンドを最終的に拒否します。承認する場合は原則として`Yes`（今回のみ許可）を使用し、`Yes, and don't ask again`は使用しません。恒久Allowは承認画面から追加せず、現時点では0件を維持します。

## 読まない対象

次のファイル、ディレクトリ、データは、レビュー対象として指定されても読まず、理由を報告します。

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

レビューコメントやテスト結果には、秘密情報、認証情報、APIキー、token、個人情報、ローカル設定値を含めません。

`.env.example`のReadに確認画面が表示された場合は、人間がファイル名を確認し、`.env.example`である場合だけ許可します。それ以外の`.env.*`は許可しません。

## 使用できる確認コマンド

Bash実行時に確認画面が表示された場合は、人間の承認を待ちます。レビュー範囲の確認には、次の読み取り系Gitコマンドだけを使用できます。

```text
git status --short
git branch --show-current
git log --oneline -n <number>
git diff -- <指定されたファイル>
git diff --cached -- <指定されたファイル>
git diff HEAD -- <指定されたファイル>
git diff --check
```

引数なしの広範囲な `git diff` は、ユーザーがレビュー範囲として全差分を明示した場合だけ使用できます。

`git grep` は、検索目的、検索語、対象パスがユーザーに明示され、対象がレビュー範囲内で禁止対象を含まない場合だけ許可候補にします。実行前に目的・検索語・対象パスを説明し、パイプや別コマンドへ連結しません。

## staged / unstaged / untracked の確認

- unstaged の差分は `git diff -- <path>` で確認します。
- staged の差分は `git diff --cached -- <path>` で確認します。
- HEADとの差分は `git diff HEAD -- <path>` で確認します。
- untracked ファイルは通常の `git diff` では本文が表示されません。
- untracked ファイルは、ユーザーが明示した対象ファイルに限ってReadツールで確認します。
- untracked でも禁止対象ファイルは読みません。

## Laravelレビュー観点

- Laravel Breeze、Policy、Form Request、middlewareの適用漏れ
- CSRF、Blade自動エスケープ、Mass Assignment対策
- Eloquentリレーション、Eager Loading、N+1
- DB制約、外部キー、UNIQUE制約、nullable、削除時動作
- レビュー本文・評価と`items`テーブルの平均評価キャッシュ整合性
- 会員退会時の匿名表示方針
- ルート名、URL、画面遷移との互換性
- Controller、Service、Model、Blade、テストの整合性
- Featureテスト（正常系・境界値・未認証・権限違反）
- README、docs更新漏れ
- MVPでは要件外の改善や大規模リファクタリングを優先しない

## 対象限定テスト

対象限定テストは、Laravel Sail環境でユーザーが正確な実行コマンドを明示した場合だけ実行候補にします。

例:

```text
./vendor/bin/sail artisan test tests/Feature/ReviewMineTest.php
./vendor/bin/sail php ./vendor/bin/phpstan analyse app/Http/Controllers/ReviewController.php
./vendor/bin/sail php ./vendor/bin/pint app/Http/Controllers/ReviewController.php --test
```

テスト実行時は次を守ります。

- Skill側でテスト対象やコマンドを推測しません。
- ユーザーが提示した正確なコマンドだけを使います。
- 実行前にコマンド内容を確認します。
- 外部通信や禁止対象参照を含むコマンドは実行しません。
- 対象限定テストを指定されていなければ実行しません。

次は既定では実行しません。

- 全テスト
- coverage
- lint
- formatter
- build
- 外部通信を伴うテスト
- 実ログを参照するテスト

## 禁止するGit操作

次のGit変更操作は実行しません。

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
```

## 禁止する外部通信

次の外部問い合わせ・転送コマンドは実行しません。

```text
curl
wget
ssh
scp
rsync
```

`WebFetch`、`WebSearch`、MCP、外部AIコネクタも使用しません。

## レビュー手順

1. `CLAUDE.md`、`AGENTS.md`、`README.md`、`docs/CLAUDE_CODE_REVIEW.md`の禁止事項と運用手順を確認します。
2. `$ARGUMENTS` からレビュー対象を確認します。
3. 対象が不明確ならユーザーへ確認します。
4. `git status --short` で変更状態を確認します。
5. `git branch --show-current` で現在ブランチを確認します。
6. staged / unstaged / untracked を判断します。
7. 指定範囲の差分だけを確認します。
8. 未追跡ファイルは指定されたファイルだけReadします。
9. 指定された場合だけ対象限定テストを実行します。
10. High / Medium / Lowで指摘を整理します。
11. 修正は行いません。
12. 今回の差分と過去の変更を混同しません。

## 出力形式

指摘がない分類も省略せず、「特になし」と記載します。

```markdown
## レビュー範囲

## 実行した確認

## 指摘事項

### High

### Medium

### Low

## 良い点

## テスト・検証結果

## コミット前に人間が確認すべきこと
```
