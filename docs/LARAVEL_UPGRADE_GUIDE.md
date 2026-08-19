# Laravelメジャーアップグレードガイド

## 目次

- [1. このドキュメントの目的](#1-このドキュメントの目的)
- [2. 最初に全体の流れを見る](#2-最初に全体の流れを見る)
- [3. このプロジェクトの原則](#3-このプロジェクトの原則)
- [4. Step 1: Issueと作業範囲を確認する](#4-step-1-issueと作業範囲を確認する)
- [5. Step 2: 最新developから作業ブランチを作る](#5-step-2-最新developから作業ブランチを作る)
- [6. Step 3: 変更前baselineを取る](#6-step-3-変更前baselineを取る)
- [7. Step 4: 公式Upgrade Guideを読む](#7-step-4-公式upgrade-guideを読む)
- [8. Step 5: 依存関係を調べる【重要】](#8-step-5-依存関係を調べる重要)
- [9. Step 6: 「なぜこのpackageが入っている？」を調べる](#9-step-6-なぜこのpackageが入っているを調べる)
- [10. Step 7: 「次のLaravelへの更新を妨げるpackage」を調べる](#10-step-7-次のlaravelへの更新を妨げるpackageを調べる)
- [11. Step 8: packageごとの互換性をどう調べる？](#11-step-8-packageごとの互換性をどう調べる)
- [12. 依存関係調査シート](#12-依存関係調査シート)
- [13. Step 9: composer.jsonを必要最小限変更する](#13-step-9-composerjsonを必要最小限変更する)
- [14. Step 10: dry-runする](#14-step-10-dry-runする)
- [15. Step 11: composer.lockだけ更新する](#15-step-11-composerlockだけ更新する)
- [16. Step 12: lockfileの実解決versionを確認する](#16-step-12-lockfileの実解決versionを確認する)
- [17. Step 13: composer audit](#17-step-13-composer-audit)
- [18. Step 14: vendorを更新する](#18-step-14-vendorを更新する)
- [19. Step 15: Upgrade Guideの該当箇所だけ修正する](#19-step-15-upgrade-guideの該当箇所だけ修正する)
- [20. Step 16: package discovery / bootstrap](#20-step-16-package-discovery--bootstrap)
- [21. Step 17: 自動回帰](#21-step-17-自動回帰)
- [22. Step 18: 目視回帰](#22-step-18-目視回帰)
- [23. Step 19: docsを更新する](#23-step-19-docsを更新する)
- [24. Step 20: レビュー・CI](#24-step-20-レビューci)
- [25. Step 21: develop baseline](#25-step-21-develop-baseline)
- [26. Step 22: 次major Issueを作る](#26-step-22-次major-issueを作る)
- [27. 停止条件早見表](#27-停止条件早見表)
- [28. やってはいけないこと](#28-やってはいけないこと)
- [29. 用語集](#29-用語集)
- [30. 公式一次情報](#30-公式一次情報)
- [31. PHPバージョン更新の標準手順](#31-phpバージョン更新の標準手順)
- [32. 最後に](#32-最後に)

---

## 1. このドキュメントの目的

この文書は、映画レビューアプリ Laravel移植版で Laravel を安全にメジャーアップグレードするための**標準手順書**です。

対象読者は次のような人です。

- Laravelのメジャーアップグレード経験が少ない
- Composerは使ったことがあるが、依存関係の調べ方に自信がない
- コマンドを実行するだけでなく「なぜこの順番なのか」も理解したい
- 将来このプロジェクトを保守する

各メジャーアップグレードで実際に発生した問題・実行結果は、
[Laravelメジャーアップグレード実施履歴](LARAVEL_UPGRADE_HISTORY.md)
へ分離しています。

### 関連ドキュメント

- [コマンド集](COMMANDS.md): 通常コマンド
- [トラブルシューティング](TROUBLESHOOTING.md): 通常の障害切り分け
- [GitHub開発運用ガイド](GITHUB_WORKFLOW.md): Git / GitHub運用
- [デプロイ方針](DEPLOYMENT.md): 本番反映条件とXServerデプロイ
- [Laravelメジャーアップグレード実施履歴](LARAVEL_UPGRADE_HISTORY.md): 実際のアップグレード履歴

---

## 2. 最初に全体の流れを見る

```text
Step 1. Issueと作業範囲を確認する
      ↓
Step 2. 最新developから作業ブランチを作る
      ↓
Step 3. 変更前baselineを取る
      ↓
Step 4. 公式Upgrade Guideを読む
      ↓
Step 5. 現在の依存関係を調べる
      ↓
Step 6. 「なぜこのpackageが入っている？」を調べる
      ↓
Step 7. 次のLaravelへの更新を妨げるpackageを調べる
      ↓
Step 8. packageごとの互換性を調べて分類する
      ↓
Step 9. composer.jsonを必要最小限変更する
      ↓
Step 10. Composerをdry-runする
      ↓
Step 11. composer.lockだけ更新する
      ↓
Step 12. lockfileの実解決versionを確認する
      ↓
Step 13. composer auditで既知脆弱性を確認する
      ↓
Step 14. vendorを更新する
      ↓
Step 15. Upgrade Guideの該当箇所だけ修正する
      ↓
Step 16. package discovery / Laravel bootstrapを確認する
      ↓
Step 17. PHPUnit / PHPStan / Pint / buildで自動回帰確認する
      ↓
Step 18. ブラウザで目視回帰確認する
      ↓
Step 19. 関連docsを更新する
      ↓
Step 20. Codex / Claude Codeレビュー → PR → CIを確認する
      ↓
Step 21. developへマージ後、baselineを再確認する
      ↓
Step 22. 次majorのIssueを作る
```

### 最重要ルール

**途中で失敗したら、その場で止まる。**

続けて別コマンドを実行すると、どの変更が原因だったか分からなくなります。

---

## 3. このプロジェクトの原則

### 3.1 1 majorずつ進める

```text
Laravel 10 → 11
developで確認
↓
Laravel 11 → 12
developで確認
↓
Laravel 12 → 13
```

一気に `10 → 13` へ上げません。

理由:

- Breaking Changeをmajorごとに切り分けられる
- Composer conflictの原因を絞れる
- テスト失敗の原因を絞れる
- PRの責務を小さくできる
- 戻す地点が明確になる

### 3.2 次majorのIssueを先に作らない

前段階が`develop`へマージされ、正常baselineを確認してから次の子Issueを作ります。

### 3.3 無関係な更新を混ぜない

Laravel更新PRへ、理由なく以下を混ぜません。

- PHPの別バージョンへの更新
- PHPUnit / Larastan / Pint等の不要なmajor update
- npm脆弱性修正
- UI改善
- 既存UX改善
- 無関係なdocs負債

---

## 4. Step 1: Issueと作業範囲を確認する

### 何を確認する？

- 親Issue
- 今回の子Issue
- Laravelの移行元・移行先
- 対象外
- 受け入れ条件
- `main`へ出すか
- XServerへデプロイするか

本番反映条件・XServerデプロイの判断基準は
[デプロイ方針](DEPLOYMENT.md)
を確認します。

### 成功条件

次を1文で説明できます。

> 今回は Laravel X→Y だけを扱い、Zは対象外とする。

---

## 5. Step 2: 最新developから作業ブランチを作る

Git / GitHub操作は、[GitHub開発運用ガイド §7「最新のdevelopから作業を開始する」](GITHUB_WORKFLOW.md#7-最新のdevelopから作業を開始する)を正本とし、その手順に従って作業ブランチを作成します。このガイドでは一般的なGit操作を重複記載しません。

### Laravelアップグレード固有のbaseline確認

作業ブランチ作成後、移行元baselineの起点を記録します。

```bash
# 対象Issueの作業ブランチへ切り替わっていることを確認
git branch --show-current

# 移行元baselineの起点コミットを記録
git log --oneline -n 1

# 作業開始時点の差分がないことを確認
git status --short
```

ブランチ名、起点コミット、作業ツリーがIssueの開始条件と一致しない場合は、アップグレード作業を開始せず停止します。

---

## 6. Step 3: 変更前baselineを取る

### baselineとは？

変更前の「正常な状態」を記録した比較基準です。

アップグレード後にエラーが出たとき、

```text
元から壊れていた
```

のか、

```text
Laravel更新で壊れた
```

のかを区別できます。

### 環境・バージョン確認

現在どのLaravel・PHP環境で動いているかを確認します。

```bash
# Laravel・PHP・DBなど現在のアプリ実行環境を確認
./vendor/bin/sail artisan about

# PHPのバージョンを明示的に確認
./vendor/bin/sail php -v
```

Laravel Upgrade Guideにcurlの最低バージョン要件がある場合は、
PHPから利用しているcurlのバージョンも確認します。

```bash
# PHPから利用しているcurlのバージョンを確認
./vendor/bin/sail php -r 'echo curl_version()["version"], PHP_EOL;'
```

フロントエンドのビルド環境もアップグレード前後で比較できるように、
Node.jsとnpmのバージョンを確認します。

```bash
# Sailコンテナで使用しているNode.jsのバージョンを確認
./vendor/bin/sail node -v

# Sailコンテナで使用しているnpmのバージョンを確認
./vendor/bin/sail npm -v
```

### 依存パッケージの実バージョン確認

`composer.json`には使用可能なバージョン範囲が書かれています。
実際に`composer.lock`へ固定されている直接依存パッケージのバージョンも確認します。

```bash
# composer.lockに固定された直接依存パッケージの実バージョンを確認
./vendor/bin/sail composer show --locked --direct
```

### ルートbaseline確認

アップグレード前のルート一覧を記録し、アップグレード後と比較できるようにします。

```bash
# 現在登録されているルート一覧とルート数を確認
./vendor/bin/sail artisan route:list
```

確認する主な点:

- ルート数
- 認証関連ルート
- `/api/user`など主要ルート
- アップグレード後に意図せず追加・削除されたルートがないか

### 品質確認

```bash
# composer.jsonとcomposer.lockの整合性を確認
./vendor/bin/sail composer validate --strict

# PHPUnitテストを実行し、既存機能の回帰がないか確認
./vendor/bin/sail artisan test

# PHPStan / Larastanで静的解析を実行
./vendor/bin/sail php ./vendor/bin/phpstan analyse

# Pintでコードスタイル違反がないか確認（自動修正はしない）
./vendor/bin/sail php ./vendor/bin/pint --test

# package-lock.jsonどおりにSail環境のnode_modulesを再構築
# package.jsonやpackage-lock.jsonは変更しない
./vendor/bin/sail npm ci

# Sail環境でフロントエンドassetsが正常にビルドできるか確認
./vendor/bin/sail npm run build
```

### 記録するもの

アップグレード後と比較できるように、次の結果を記録します。

- Laravelのバージョン
- PHPのバージョン
- Composerのバージョン
- 使用しているDB
- curlのバージョン（Upgrade Guideに最低要件がある場合）
- Node.jsのバージョン
- npmのバージョン
- Viteのバージョン
- 登録されているroute数
- PHPUnitのtests数 / assertions数
- PHPStan / Larastanの解析結果
- Pintの確認結果と対象files数
- フロントエンドbuildの結果
- 既知のwarningや脆弱性など、アップグレード前から存在する問題

これらをアップグレード前のbaselineとして残し、
アップグレード後に同じ項目を確認して差分を比較します。

---

## 7. Step 4: 公式Upgrade Guideを読む

作業開始時点のLaravel公式Release Notesと、移行先majorのUpgrade Guideを確認します。過去Issueの調査結果だけを再利用せず、依存関係を変更する直前にも内容が更新されていないか再確認します。

Issueまたは実施履歴には、確認した対象majorと公式URLを記録します。

### 何を見る？

Laravel Upgrade Guideの各項目を次へ分類します。

```text
該当
非該当
追加確認が必要
```

例:

```text
PHP minimum version
Composer Dependencies
Application Structure
Sanctum
Database / Migration
Carbon
Authentication
Testing
```

移行先majorのPHP要件と現在のPHPを比較し、次を判断します。

- 現在のPHPを維持したまま移行できるか
- PHP更新を今回のIssueへ含める必要があるか
- Laravel更新後にPHP更新を別Issueへ分離するか

現在のPHPで移行でき、Issueの対象外に定めている場合は、PHP更新をLaravelアップグレードへ混在させません。

### 重要

公式ガイドに書かれているpackageだけ見て終わりではありません。

**現在のプロジェクトに実際に入っているpackageを次のStepで確認します。**

前majorで互換と判断したpackageも、次majorで同じとは限りません。`composer.json`のconstraintと`composer.lock`の実解決versionを、毎回あらためて確認します。

---

## 8. Step 5: 依存関係を調べる【重要】

ここはLaravelアップグレードで最も迷いやすい工程です。

### 8.1 まず「依存関係」という言葉を分ける

#### root dependency（直接依存）

`composer.json`に自分たちで直接書いているpackage。

例:

```json
"laravel/framework": "^10.10",
"laravel/sanctum": "^3.3"
```

#### transitive dependency（推移依存）

直接依存したpackageが内部で必要とするpackage。

例:

```text
laravel/framework
  ↓
symfony/console
```

`composer.json`に自分で書いていなくても、`composer.lock`には入っています。

---

### 8.2 最初にcomposer.jsonを見る

```bash
# composer.jsonのrequire / require-devを確認
sed -n '1,220p' composer.json
```

#### どこを見る？

```text
"require"
"require-dev"
```

#### 何をメモする？

次の表を作ります。以下はLaravel 10→11を調査するときの記入例です。

| package | require / require-dev | 現在のconstraint |
|---|---|---|
| laravel/framework | require | ^10.10 |
| laravel/sanctum | require | ^3.3 |
| laravel/breeze | require-dev | ^1.29 |

#### constraintとは？

```text
^10.10
```

は「Laravel 10系のどこまで許可するか」という**範囲**です。

ここだけでは「実際に10.何が入っているか」は分かりません。

---

### 8.3 composer.lockの実バージョンを見る

このプロジェクトでは`composer.lock`が正本です。

直接依存の実バージョンを一覧化します。

```bash
# composer.lockに固定された直接依存パッケージの実バージョンを確認
./vendor/bin/sail composer show --locked --direct
```

Composer公式の`show --locked`は`composer.lock`に固定されたpackageを表示し、`--direct`は直接依存だけへ絞ります。

#### 出力例

以下はLaravel 10→11を調査するときの例です。現在値として流用せず、作業時点の`composer.lock`を確認します。

```text
laravel/breeze      1.29.1
laravel/framework   10.50.2
laravel/sanctum     3.3.3
laravel/sail        1.58.0
phpunit/phpunit     10.5.63
```

#### どこを見る？

```text
package名
実version
```

#### composer.jsonとの違い

```text
composer.json
laravel/framework ^10.10
        ↓
「許可範囲」

composer.lock
laravel/framework 10.50.2
        ↓
「実際に使っているversion」
```

**アップグレード調査では両方必要です。**

---

### 8.4 個別packageを詳しく見る

例:

```bash
# Laravel本体の実バージョンと依存条件を詳しく確認
./vendor/bin/sail composer show --locked laravel/framework
```

別package:

```bash
./vendor/bin/sail composer show --locked laravel/sanctum
./vendor/bin/sail composer show --locked larastan/larastan
./vendor/bin/sail composer show --locked phpunit/phpunit
```

#### どこを見る？

特に:

```text
versions
requires
conflicts
```

packageによって表示内容は異なります。

#### 判断したいこと

例:

```text
Larastan 2.11.2はLaravel 11を許可するか？
PHPUnit 10.5.63はCollision 8と一緒に使えるか？
```

ただし、**最終判断はpackage公式リポジトリのcomposer.json等の一次情報でも確認します。**

---

## 9. Step 6: 「なぜこのpackageが入っている？」を調べる

推移的依存で見覚えのないpackageがあるときに使います。

```bash
# このpackageを必要としている依存元をツリー表示
./vendor/bin/sail composer depends <package> --tree
```

`depends`は`why`の別名です。

例:

```bash
./vendor/bin/sail composer why symfony/console --tree
```

### 何が分かる？

```text
誰がsymfony/consoleを必要としているか
```

を辿れます。

### 使う場面

dry-runで突然、

```text
symfony/console 6.4 → 7.4
```

と出た場合、

> 自分はSymfonyを直接更新していないのに何で変わるの？

を調べるときに使います。

---

## 10. Step 7: 「次のLaravelへの更新を妨げるpackage」を調べる

ここが重要です。

Composerには、指定versionを**何がブロックしているか**調べるコマンドがあります。

```bash
# 次のLaravelへの更新を妨げているpackageを調べる
./vendor/bin/sail composer prohibits laravel/framework '<移行先major>.*' --tree
```

Laravel 10→11の場合:

```bash
./vendor/bin/sail composer prohibits laravel/framework '11.*' --tree
```

`prohibits`は`why-not`の別名です。

### 何を見る？

出力に例えば、

```text
laravel/breeze 1.x requires illuminate/* ^10
laravel/sanctum 3.x requires illuminate/* ^10
nunomaduro/collision 7.x conflicts laravel/framework >=11
```

のような内容があれば、それらはLaravel 11へ進むための**更新必須候補**です。

### 注意

現在のroot `composer.json`自身がLaravel 10を指定している場合もブロッカーとして表示されます。

それは正常です。

#### このコマンドの役割

```text
公式Upgrade Guide
        +
composer prohibits / why-not
        +
package公式composer.json
```

を組み合わせて、更新必須packageを確定します。

---

## 11. Step 8: packageごとの互換性をどう調べる？

各packageについて次の順で確認します。

### 11.1 現在のroot constraint

`composer.json`

### 11.2 現在の実version

```bash
./vendor/bin/sail composer show --locked --direct
```

### 11.3 Laravelの公式Upgrade Guide

例:

```text
Breeze → ^2.0
Sanctum → ^4.0
Collision → ^8.1
```

### 11.4 package公式情報

GitHub公式repositoryの対象tag / branchの`composer.json`などを確認します。

#### 見る項目

```text
php
laravel/framework
illuminate/*
conflict
phpunit
```

Laravel本体だけでなく、プロジェクトで実際に使用している次のようなpackageも対象にします。

```text
Breeze / BreezeJP
Sail
Sanctum
Larastan / PHPStan
PHPUnit / Collision
Pint
Laravel IDE Helper
Carbon / Laravel Promptsなど主要な推移的依存
```

前majorの調査表やlockfileをそのまま流用せず、移行先majorを許可するconstraintと実解決versionを再確認します。

#### 例

Larastanなら:

```text
illuminate/* が Laravel 11を許可しているか
Laravel 11の最低patch version条件がないか
```

Collisionなら:

```text
Laravel 11とconflictしないか
PHPUnit 10を許可しているか
```

### 11.5 分類する

| 判定 | 意味 |
|---|---|
| 更新必須 | 現versionではLaravel次majorと共存できない |
| 現状維持 | 現version / constraintのまま互換 |
| 追加確認 | 条件付き互換・実解決version確認が必要 |
| 今回触らない | Laravel更新に不要 |

### 11.6 不要packageの削除を混在させない

調査中に現在使われていない可能性のあるpackageが見つかっても、Laravel更新に削除が必須でなければ別のcleanup Issueへ分離します。

例えばSanctumを削除するかどうかは、認証方式、ルート、設定、migration、Featureテストへの影響を整理して判断する別責務です。Laravel互換版へ更新すれば移行できる場合、アップグレードIssueでは維持します。

---

## 12. 依存関係調査シート

毎回この表を埋めると判断しやすくなります。

| package | root? | composer.json constraint | lock version | 次Laravel互換 | 根拠 | 今回の扱い |
|---|---|---|---|---|---|---|
| laravel/framework | Yes | ^10.10 | 10.50.2 | No | Laravel Upgrade Guide | ^11.0へ |
| laravel/sanctum | Yes | ^3.3 | 3.3.3 | No | Sanctum / Laravel公式 | ^4.0へ |
| laravel/sail | Yes | ^1.18 | 1.58.0 | Yes | package制約 | 維持 |
| phpunit/phpunit | Yes | ^10.1 | 10.5.63 | 条件確認 | Collision等 | 維持候補 |

### 重要

「最新版がある」ことは「今回更新する理由」ではありません。

判断基準は、

```text
次Laravelへ上げるために必要か？
```

です。

---

## 13. Step 9: composer.jsonを必要最小限変更する

依存調査が終わってから編集します。

変更後:

```bash
# composer.jsonに予定外の変更がないか確認
git diff -- composer.json
```

**予定したconstraintだけ**変わっていることを確認します。

---

## 14. Step 10: dry-runする

```bash
# ファイルを書き換える前に、更新されるpackageをdry-runで事前確認
./vendor/bin/sail composer update <対象package...> \
  --with-all-dependencies \
  --minimal-changes \
  --dry-run \
  --no-scripts
```

### dry-runで見る場所

出力のこの部分です。

```text
Lock file operations:
  X installs
  Y updates
  Z removals
```

続くpackage一覧を見ます。

### 直接依存

```text
laravel/framework
laravel/sanctum
laravel/breeze
collision
```

が想定versionへ行くか。

### 推移依存

```text
symfony/*
termwind
league/*
```

など、想定外のmajor更新がないか。

### 絶対に見る項目

```text
removals
```

意図しない削除がある場合は止まります。

### dry-runがエラーなら

次へ進みません。

分類:

```text
通常のdependency conflict
Security Blocking
PHP version不足
extension不足
```

Security Blockingの実例は
[Laravelメジャーアップグレード実施履歴](LARAVEL_UPGRADE_HISTORY.md)
を参照します。

`--no-security-blocking`、advisory ignore、audit結果を回避する設定は通常手順へ持ち越しません。過去の実施履歴に例外が記録されていても、新しいIssueで自動的に再利用しないでください。

Security Blockingが発生した場合は停止し、対象advisory、影響version、修正版、今回の移行経路を人間が評価します。例外使用をIssueが明示的に許可しない限り、解除せずに依存解決方法を見直します。

---

## 15. Step 11: composer.lockだけ更新する

dry-runの内容を人間が確認してから進みます。

```bash
# vendorはまだ更新せず、依存関係の解決結果をcomposer.lockへ反映
./vendor/bin/sail composer update <対象package...> \
  --with-all-dependencies \
  --minimal-changes \
  --no-install \
  --no-scripts
```

### この状態

```text
composer.json → 新constraint
composer.lock → 新しい解決結果
vendor/ → まだ旧package
```

### なぜ分ける？

vendorを変える前に、lockfileの実versionを確認できるためです。

---

## 16. Step 12: lockfileの実解決versionを確認する

```bash
# composer.lockに固定された直接依存パッケージの実バージョンを確認
./vendor/bin/sail composer show --locked --direct
```

個別:

```bash
./vendor/bin/sail composer show --locked laravel/framework
./vendor/bin/sail composer show --locked larastan/larastan
./vendor/bin/sail composer show --locked phpunit/phpunit
```

直接依存だけでなく、Carbon、Laravel Prompts、PHPStan、PHPUnit関連packageなど、majorが変わった主要な推移的依存も個別に確認します。

### 確認表

次はLaravel 10→11を確認するときの記入例です。実際の移行先majorと予定versionに置き換えます。

| package | 予定 | 実解決 | OK? |
|---|---|---|---|
| Laravel | 移行先major.x | 実解決version | Yes/No |
| 認証package | 互換majorまたは維持 | 実解決version | Yes/No |
| PHPUnit | 互換majorまたは維持 | 実解決version | Yes/No |

**constraintだけでなく実解決versionを見る**ことが重要です。

前majorのbaselineで記録したtests数・assertions数・主要package versionと比較し、減少や想定外のmajor更新がある場合は理由を確認します。

---

## 17. Step 13: composer audit

```bash
# 更新後のcomposer.lockに既知脆弱性がないか確認
./vendor/bin/sail composer audit --locked
```

Composer公式の`--locked`は、現在のvendorではなく`composer.lock`を監査します。

### 見るところ

```text
Package
Severity
Advisory ID
CVE
Affected versions
```

### advisoryが出たら

次を記録します。

```text
何のpackageか
何件か
severity
現在versionがaffectedか
first patched version
今回解消するか
次majorへ持ち越すか
公開可能か
```

**警告を消すことが目的ではありません。**

### Dependabot Alertsとの区別

`composer audit --locked`は現在の`composer.lock`を検査します。一方、GitHub Dependabot Alertsは既定ブランチを基準に評価されるため、作業ブランチや`develop`だけで修正版へ更新しても、既定ブランチへ同期するまでAlertがCloseされない場合があります。

この違いを記録し、Dependabot AlertのCloseを受け入れ条件にするかどうかはIssueで明示します。`composer audit`の成功とDependabot Alertの表示状態を同じ確認として扱いません。

---

## 18. Step 14: vendorを更新する

```bash
# composer.lockどおりにvendorを更新し、Composer scriptsはまだ実行しない
./vendor/bin/sail composer install --no-scripts
```

### このコマンドが変えるもの

```text
vendor/
```

### 変えないもの

```text
composer.jsonのconstraint
composer.lockの解決version
```

`--no-scripts`でLaravelのpackage discoveryはまだ分離します。

---

## 19. Step 15: Upgrade Guideの該当箇所だけ修正する

例:

- Sanctum設定
- middleware
- migration仕様
- Laravelの型定義変更
- DB Breaking Change

### 原則

```text
公式Guideに必要と書いてある
または
実際にテスト・静的解析で必要と判明した
```

差分だけ修正します。

新Laravel skeletonへ全面作り替えません。

---

## 20. Step 16: package discovery / bootstrap

設定修正後:

```bash
# Composerのautoloadを再生成する
# このプロジェクトではpost-autoload-dump script経由で
# Laravelのpackage discoveryも実行される
./vendor/bin/sail composer dump-autoload
```

確認:

```bash
# Laravel・PHP・DBなど現在のアプリ実行環境を確認
./vendor/bin/sail artisan about

# 現在登録されているルート一覧とルート数を確認
./vendor/bin/sail artisan route:list
```

見るところ:

- Laravel version
- PHP version
- DB
- package discovery errorなし
- route数
- 主要routeの消失なし

---

## 21. Step 17: 自動回帰

```bash
# PHPUnitテストを実行し、既存機能の回帰がないか確認
./vendor/bin/sail artisan test

# PHPStan / Larastanで静的解析を実行
./vendor/bin/sail php ./vendor/bin/phpstan analyse

# Pintでコードスタイル違反がないか確認（自動修正はしない）
./vendor/bin/sail php ./vendor/bin/pint --test

# package-lock.jsonどおりにSail環境のnode_modulesを再構築
# package.jsonやpackage-lock.jsonは変更しない
./vendor/bin/sail npm ci

# Sail環境でフロントエンドassetsが正常にビルドできるか確認
./vendor/bin/sail npm run build

# trailing whitespaceなど差分上の書式エラーを確認
git diff --check
```

### 失敗したら

最初に失敗した工程だけを調査します。

例えば:

```text
PHPUnit PASS
PHPStan FAIL
```

なら、アプリが動かないとは限りません。

frameworkの型定義変更などを確認します。

---

## 22. Step 18: 目視回帰

最低限、次の一連の操作を実際のブラウザで確認します。

- ゲストで作品一覧・作品詳細・レビュー・返信を表示できる
- 作品一覧のページネーションで前後ページへ移動でき、件数表示が一致する
- 新規登録、ログイン、ログアウトが成功する
- パスワードリセットメールが届き、パスワードを再設定できる
- プロフィールを表示し、ニックネームや自己紹介を更新できる
- アバターを新規登録・差し替えでき、主要な表示箇所へ反映される
- パスワードを変更でき、旧パスワードと新パスワードの動作が想定どおりである
- レビューを表示・投稿・削除でき、投稿・削除後に平均評価と評価件数が更新される
- 返信コメントを表示・投稿できる
- 本人レビュー一覧を表示でき、10件超の場合はページネーションで移動できる
- 本人レビュー一覧から作品詳細へ移動できる
- レビューを削除すると、そのレビューに属する返信も消え、平均評価・評価件数が再計算される
- アカウントを削除するとログアウト状態になり、既存レビュー・返信の投稿者が匿名表示になる
- 主要画面をデスクトップ幅とモバイル幅で表示し、ナビゲーションと主要フォームのレイアウト崩れがないことを確認する

レビュー編集、返信コメントの編集・単独削除、アバターの単独削除などは、実装済みの場合のみ対象にします。モーダル、focus、特定のJavaScript動作は、該当UIが存在する場合や関連差分がある場合の追加確認として扱います。

### 自動テストがPASSしても必要な理由

- CSS崩れ
- JavaScript
- modal
- focus
- 実メール本文
- browser固有挙動

は自動テストだけでは完全に確認できません。

---

## 23. Step 19: docsを更新する

現在versionやLaravelとの互換性を記載するdocsを、次の表に沿って確認します。

`docs/LARAVEL_UPGRADE_HISTORY.md`には今回の実測結果を追記します。それ以外は内容を確認し、現在値や手順に影響があるファイルだけを更新します。確認した結果、固定versionや互換性記述がなければ無理に変更しません。

| ファイル | 確認する内容 |
|---|---|
| `README.md` | Laravel / PHPなど現在の技術構成 |
| `AGENTS.md` | プロジェクト前提のLaravelバージョン |
| `CLAUDE.md` | Laravel / PHPのプロジェクト前提 |
| `docs/LARAVEL_UPGRADE_GUIDE.md` | 次回も使う標準手順、公式情報、停止条件 |
| `docs/LARAVEL_UPGRADE_HISTORY.md` | 実際の変更、実測結果、問題、次majorへの引継ぎ |
| `docs/DEPLOYMENT.md` | 開発中のLaravel版、本番反映対象版、PHP前提 |
| `docs/COMMANDS.md` | Laravel互換性に関する固定バージョン記述 |
| `docs/DEVELOPMENT_FLOW.md` | 依存関係変更時のLaravel互換性記述 |
| `docs/SECURITY.md` | Composer依存・既知脆弱性に関する方針 |
| `docs/CLAUDE_CODE_PERMISSION_DESIGN.md` | Laravel公式参照・実測バージョンの記録 |
| `docs/CLAUDE_CODE_REVIEW.md` | レビュー時に前提とするLaravelバージョン・品質ゲート |
| `docs/CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md` | 実装前レビュー時に前提とするLaravelバージョン・確認項目 |
| `.claude/skills/pre-implementation-review/SKILL.md` | Laravelプロジェクトの前提バージョン |
| `.claude/skills/pr-diff-review/SKILL.md` | PR差分レビュー時に前提とするLaravelバージョン・品質ゲート |

歴史的記録まで現在値に書き換えません。

`docs/TROUBLESHOOTING.md`は、再現可能な実トラブルと検証済みの解決手順が新たに得られた場合だけ更新します。WSL、エディタ、Vite開発サーバーなどローカル環境の一時的な停止や、再起動後に正常化して再現しない事象は、Laravelアップグレード固有の履歴として残しません。

---

## 24. Step 20: レビュー・CI

確認:

- `git status --short`
- `git diff --stat`
- `git diff --check`
- composer.json
- composer.lock
- 設定
- PHPDoc
- tests
- docs
- 秘密情報なし
- 対象外変更なし

Codex / Claude Codeによるレビュー完了後、
人間がプロジェクトのGitHub運用ルールに従ってPRを`develop`向けに作成し、
GitHub ActionsのCI結果を確認します。

---

## 25. Step 21: develop baseline

PRマージ後に`develop`上で再度:

- Laravel / PHP version
- PHPUnit
- PHPStan
- Pint
- build
- docs
- advisory

を確認します。

ここが次majorの開始地点です。

---

## 26. Step 22: 次major Issueを作る

次のすべてを満たした後だけ作ります。

- 前段階がdevelopへマージ済み
- developで回帰PASS
- docs整合済み
- 残存advisory記録済み
- 次major公式Upgrade Guide調査済み

次majorのPHP要件を現在のPHPが満たさない場合や、サポート期間の都合でPHP更新を先に行う必要がある場合は、Laravelの次major Issueへ混在させず、PHP更新用の子Issueを先に作成するか人間が判断します。

前段階のbaselineが確定する前に、次Laravel majorやPHP更新の依存変更へ着手しません。

---

## 27. 停止条件早見表

| 状況 | どうする？ |
|---|---|
| baseline失敗 | アップグレード開始しない |
| `prohibits`でblocker判明 | package公式互換性を調べる |
| dry-run conflict | その場で停止 |
| Security Blocking | 停止してadvisoryを評価。過去の解除例を通常手順へ流用しない |
| removalsが想定外 | 停止 |
| lockfileの実versionが想定外 | 停止 |
| auditで未評価High | 停止 |
| vendor install失敗 | 次へ進まない |
| package discovery失敗 | 設定・package互換性を調査 |
| PHPUnit失敗 | 最小再現へ |
| PHPStanだけ失敗 | 型定義変更を確認 |
| 目視だけ異常 | browser / extension等を切り分け |
| CI失敗 | developへマージしない |

---

## 28. やってはいけないこと

### 原則

- baselineなしでupdateしない
- dry-runなしでupdateしない
- Security Blockingを通常手順として解除しない
- `composer audit`を隠さない
- `.env`や秘密情報をログへ出さない
- 本番DBをテストに使わない
- 既存migrationを安易に過去編集しない
- 自動テストだけで目視を省略しない

### 今回のような段階アップグレードで避けるもの

- 一気に複数majorへ上げる
- PHP更新を無計画に混ぜる
- PHPUnit等のmajor更新を「ついで」に行う
- npm脆弱性修正を混ぜる
- Breeze scaffoldingを再生成する
- 新Laravel skeletonへ全面作り替える

---

## 29. 用語集

| 用語 | 意味 |
|---|---|
| baseline | 変更前・各段階完了時の正常比較基準 |
| root dependency | composer.jsonに直接記載したpackage |
| 推移依存 | root packageがさらに必要とするpackage |
| constraint | composer.jsonの許可version範囲 |
| lock version | composer.lockに固定された実version |
| vendor | Composerが実際にinstallしたpackage群 |
| dry-run | ファイルを変更しないシミュレーション |
| depends / why | なぜpackageが必要か調べるComposerコマンド |
| prohibits / why-not | target versionを何がblockするか調べるComposerコマンド |
| Security Advisory | 既知脆弱性の勧告 |
| EOL | サポート終了 |
| Breaking Change | 後方互換でない変更 |
| 静的解析 | 実行せず型・コードを解析 |
| 回帰 | 以前動いた機能が変更後に壊れること |

---

## 30. 公式一次情報

毎回最新を確認します。

- Laravel Upgrade Guide: 対象majorの公式ページ
- Laravel Release Notes / Support Policy
- Composer CLI: https://getcomposer.org/doc/03-cli.md
- Composer Config / Security policy: https://getcomposer.org/doc/06-config.md
- PHP supported versions: https://www.php.net/supported-versions.php

Composer公式では:

- `show --locked`でlockfile packageを確認できる
- `--direct`で直接依存へ絞れる
- `depends / why`で「なぜ入っているか」を確認できる
- `prohibits / why-not`でtarget versionをblockするpackageを確認できる

とされています。

---

## 31. PHPバージョン更新の標準手順

### 31.1 この手順の目的

LaravelのメジャーアップグレードやDependabot Alertsへの対応により、
PHPの対応バージョンを変更する必要が生じた場合の標準手順を定める。

PHP更新はLaravelメジャーアップグレードと別Issue・別Pull Requestで扱う。
一度に変更すると、依存関係、Sail、CI、PHP自体の仕様変更のどれが原因で失敗したか切り分けにくくなるためである。

各PHP更新で実際に使用したバージョン、実行結果、発生した問題は、
[Laravelメジャーアップグレード実施履歴](LARAVEL_UPGRADE_HISTORY.md)
へ記録する。

### 31.2 対象範囲を決める

PHP更新Issueでは、原則として次の項目だけを扱う。

- `composer.json`のPHP要件
- `composer.lock`のplatform情報
- `compose.yaml`のSail runtimeとimage
- GitHub Actionsで使用するPHPバージョン
- PHP仕様変更の影響を確認するために必要な最小限のテスト
- PHP更新後の関連ドキュメント

次の変更は混ぜない。

- Laravelのメジャーアップグレード
- Composer依存パッケージの不要な更新
- npm依存パッケージの更新
- UI・UXの変更
- 無関係なリファクタリング
- XServer本番環境への反映

### 31.3 作業開始条件

PHP更新を始める前に、次の条件をすべて満たしていることを確認する。

- 更新前のLaravel baselineが`develop`で確定している
- 更新対象のPHPバージョンが明確である
- 対象Laravel版が更新先PHPをサポートしている
- Sailに更新先PHPのruntimeが存在する
- Composer依存関係に明らかな互換性阻害要因がない
- 作業ブランチが最新の`develop`から作成されている
- 作業開始時点の作業ツリーがcleanである

Laravelのサポート状況、PHP要件、Sail runtimeについては、
Laravelおよび各パッケージの公式一次情報を確認する。

### 31.4 変更前baselineを記録する

変更前に、ブランチ、コミット、作業ツリー、実行環境、依存関係、回帰確認の結果を記録する。

```bash
git branch --show-current
git log --oneline -n 1
git status --short

./vendor/bin/sail artisan about
./vendor/bin/sail php -v
./vendor/bin/sail composer show --locked --direct
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit --locked
./vendor/bin/sail composer check-platform-reqs --lock

./vendor/bin/sail php ./vendor/bin/phpunit \
  --display-deprecations \
  --display-phpunit-deprecations

./vendor/bin/sail php ./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail npm run build
```

次のいずれかに該当する場合は、PHP更新を開始せず停止する。

- Issueの作業ブランチではない
- 作業開始前から未確認の差分がある
- PHPUnit、PHPStan、Pint、Vite buildが失敗する
- Composerのplatform要件を満たしていない
- 未調査のSecurity Advisoryが新たに検出される

既知のSecurity Advisoryが残っている場合は、関連Issue、対象パッケージ、影響範囲、今回のPHP更新へ混ぜない理由を実施履歴へ記録する。

### 31.5 更新先PHPとの互換性を事前確認する

更新先PHPを指定し、現在の依存関係がそのPHPバージョンを拒否していないか確認する。

```bash
./vendor/bin/sail composer prohibits php <更新先PHPの完全なバージョン>
```

例:

```bash
./vendor/bin/sail composer prohibits php 8.4.24
```

`prohibits`で阻害パッケージが表示された場合は、次を確認する。

- 直接依存か間接依存か
- 現在のlock fileに固定されたversion
- 互換versionが存在するか
- Laravel更新Issueで既に対応済みか
- PHP更新Issueへ依存パッケージ更新を含める必要があるか

依存パッケージの更新が必要になった場合は、その更新がPHP対応に不可欠であることを確認する。
無関係なパッケージ更新が必要になる場合は、PHP更新を停止してIssueの分割を検討する。

### 31.6 SailとCIの変更対象を確認する

変更前に、PHPバージョンを固定している箇所を横断確認する。

```bash
rg -n \
  "runtimes/[0-9]+\\.[0-9]+|sail-[0-9]+\\.[0-9]+|php-version|PHP_VERSION|\"php\"" \
  compose.yaml composer.json .github README.md CLAUDE.md docs .claude
```

Sailに更新先PHPのruntimeが存在することも確認する。

```bash
find vendor/laravel/sail/runtimes -maxdepth 1 -mindepth 1 -type d -print
```

主な変更対象は次のとおりとする。

| ファイル                       | 確認・変更内容                   |
| -------------------------- | ------------------------- |
| `composer.json`            | ルートのPHP要件                 |
| `composer.lock`            | content hashとplatform情報   |
| `compose.yaml`             | Sailのruntimeとimage        |
| `.github/workflows/ci.yml` | CIで使用するPHPバージョン           |
| テスト                        | PHP仕様変更の影響を確認する最小限の境界値テスト |
| 関連docs                     | 開発環境の現在値と実施履歴             |

XServerのWeb実行PHPとCLI PHPは、ローカルのSail PHPとは別に管理する。
ローカルPHPの更新だけを根拠に、XServer側のPHPバージョン記録を書き換えない。

### 31.7 PHPバージョンを固定しているファイルを変更する

事前確認で特定した箇所だけを変更する。

主な変更内容は次のとおりとする。

| ファイル | 変更内容の例 |
|---|---|
| `composer.json` | PHP要件を更新先の範囲へ変更する |
| `compose.yaml` | Sail runtimeのパスとimage名を更新する |
| `.github/workflows/ci.yml` | CIで使用するPHPバージョンを更新する |

例としてPHP 8.4へ更新する場合は、プロジェクトで採用する要件に従い、次のように変更する。

```diff
-        "php": "^8.2",
+        "php": "^8.4",
```

```diff
-            context: './vendor/laravel/sail/runtimes/8.2'
+            context: './vendor/laravel/sail/runtimes/8.4'
```

```diff
-        image: 'sail-8.2/app'
+        image: 'sail-8.4/app'
```

CIの指定方法はworkflowの既存構造を維持し、PHPバージョンの値だけを変更する。
Laravel、Composer依存パッケージ、npm依存パッケージ、UI、アプリケーション機能はこの段階で変更しない。

変更後に対象差分を確認する。

```bash
git diff -- composer.json compose.yaml .github/workflows/ci.yml
git diff --check
```

想定外のファイルや設定が変更されている場合は、Sailを再構築せず停止する。

### 31.8 Sailイメージを再構築する

`compose.yaml`のruntime変更は、既存コンテナの再起動だけでは反映されない。
コンテナを停止し、更新先runtimeからSailイメージを再構築する。

```bash
./vendor/bin/sail down
./vendor/bin/sail build --no-cache
./vendor/bin/sail up -d
```

runtime変更の反映には上記の`down`、`build --no-cache`、`up -d`を使用する。volume削除を伴う`./vendor/bin/sail down -v`は、ローカルDBデータを失う可能性があるため使用しない。

起動後、コンテナの状態と実際のPHPバージョンを確認する。

```bash
./vendor/bin/sail ps
./vendor/bin/sail php -v
./vendor/bin/sail artisan about
```

次のいずれかに該当する場合は、lock fileを更新せず停止する。

- イメージのbuildに失敗する
- `laravel.test`、MySQL、Mailpitなど必要なserviceが起動しない
- 実測したPHPバージョンが更新先と一致しない
- `artisan about`が実行できない

失敗原因を確認する場合も、`.env`の値や秘密情報をログへ残さない。

### 31.9 composer.lockのplatform情報を整合させる

更新先PHPでSailが正常起動した後、package versionを更新せずに、
`composer.json`の変更と`composer.lock`のcontent hashおよびplatform情報を整合させる。

```bash
./vendor/bin/sail composer update --lock --no-install --no-scripts
```

続けてlock fileの整合性とplatform要件を確認する。

```bash
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer check-platform-reqs --lock
./vendor/bin/sail composer show --locked --direct
```

`composer update --lock`の前後で、`composer.lock`に記録されたpackageのname、version、source、distが意図せず変わっていないことを差分で確認する。これらが変わった場合は停止する。`plugin-api-version`など、説明できない`composer.lock`のmetadata差分が発生した場合も停止し、使用したComposerのversionと差分内容を記録する。

```bash
git diff -- composer.lock
git diff --stat
git diff --check
```

lock差分が想定どおりであることを人間が確認した後、更新先PHPのSailコンテナ内で通常の`composer install`を実行する。

```bash
./vendor/bin/sail composer install
```

`composer install`は`composer.lock`に記録されたpackage versionをvendorへ再現し、通常のscriptsとLaravel package discoveryを含む起動経路を確認するために使用する。package versionを更新する工程ではない。`composer update --lock --no-install --no-scripts`でlock差分を先に分離して確認する意図は維持する。

`composer install`でlockどおりのpackageをvendorへ新規インストールする正常な`Installing`表示自体は停止理由にしない。一方、次のいずれかに該当する場合は、そのまま検証へ進まず停止する。

- `composer.lock`に記録されたversionと異なるpackageの導入・更新・削除が発生した
- PHP以外のplatform要件が意図せず変わった
- `composer validate`が失敗する
- `composer check-platform-reqs --lock`が失敗する
- Composer scriptまたはpackage discoveryが失敗する

PHP対応に不可欠な依存パッケージ更新が必要と判明した場合は、
更新理由と影響範囲を調査し、PHP更新Issueへ含めるか別Issueへ分割するかを人間が判断する。

### 31.10 更新後の品質ゲートを実行する

更新前baselineと同じ品質ゲートを、更新後のSail環境で実行する。

```bash
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer audit --locked
./vendor/bin/sail composer check-platform-reqs --lock

./vendor/bin/sail php ./vendor/bin/phpunit \
  --display-deprecations \
  --display-phpunit-deprecations

./vendor/bin/sail php ./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail npm run build
```

PHPUnitの成功だけで完了とせず、deprecationおよびPHPUnit deprecationの出力も確認する。
既知のSecurity Advisoryが残る場合は、更新前からの継続であること、関連Issue、今回のPHP更新へ混ぜない理由を記録する。

自動検証後、既存のMVP回帰確認手順に従って主要機能を目視確認する。
少なくとも、認証、プロフィール、レビュー、返信、退会、メール送信、Viteで生成した画面資産について、
今回のPHP更新による回帰がないことを確認する。

更新前は成功していた品質ゲートまたは主要機能が失敗した場合は、
PHP仕様変更、Sail runtime、Composer platform要件、CI設定のどこで差異が生じたか切り分ける。
原因が分からない状態で回避コードや依存パッケージ更新を追加しない。

### 31.11 ドキュメントと実施履歴を更新する

すべての品質ゲートと必要な目視確認が完了した後、現在値を記載する関連ドキュメントを更新する。

確認対象の例:

- `README.md`
- `CLAUDE.md`
- `docs/COMMANDS.md`
- `docs/DEVELOPMENT_FLOW.md`
- `docs/DEPLOYMENT.md`
- `docs/SECURITY.md`
- `docs/CLAUDE_CODE_PERMISSION_DESIGN.md`
- `docs/CLAUDE_CODE_REVIEW.md`
- `docs/CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md`
- `.claude/skills/pre-implementation-review/SKILL.md`
- `.claude/skills/pr-diff-review/SKILL.md`

現在の開発環境、過去の実測記録、本番環境の値を区別する。
ローカルSailのPHP更新だけを根拠に、XServerのWeb実行PHPまたはCLI PHPの実測記録を変更しない。

`docs/LARAVEL_UPGRADE_HISTORY.md`には、少なくとも次を記録する。

- Issue番号と作業ブランチ
- 更新前後のPHPバージョン
- Laravel、Sail、Composer、Node.js、npmなどの実測値
- 変更したファイル
- `composer update --lock`の結果
- PHPUnit、PHPStan、Pint、Vite buildの結果
- deprecationの有無
- `composer audit`の結果と既知advisoryの扱い
- 目視確認の結果
- 発生した問題と解決内容
- `main`および本番環境へ未反映である場合はその状態

歴史的記録は現在値へ書き換えない。
再現しない一時的なWSLやエディタの停止は、PHP更新固有の実施履歴へ混ぜない。

### 31.12 完了条件と停止・切り戻し条件

PHP更新は、次の条件をすべて満たした場合だけレビューへ進める。

- 更新先PHPがSail内で実測できた
- `composer.json`と`composer.lock`が整合している
- Composer依存パッケージの意図しないversion変更がない
- `composer check-platform-reqs --lock`が成功した
- PHPUnit、PHPStan、Pint、Vite buildが成功した
- deprecationを確認し、必要な対応または記録を行った
- Security Advisoryの状態を確認し、未解決分を記録した
- 必要な主要機能の目視確認が完了した
- CI設定と関連ドキュメントが更新先PHPと整合している
- 秘密情報および対象外変更が差分へ含まれていない

最終差分を確認する。

```bash
git status --short
git diff --stat
git diff --check
git diff -- composer.json composer.lock compose.yaml .github README.md CLAUDE.md docs .claude
```

次のいずれかに該当する場合は、コミットやPull Request作成へ進まず停止する。

- 失敗原因を特定できていない
- 品質ゲートに失敗が残っている
- 依存パッケージの想定外変更がある
- 更新対象外の機能変更が混在している
- 本番環境の値を未確認のまま書き換えている
- 未評価のSecurity Advisoryがある

切り戻しが必要な場合は、作業ブランチ内の今回の変更対象だけを更新前へ戻し、
更新前の`compose.yaml`からSailイメージを再構築する。
再構築には`./vendor/bin/sail down`、`./vendor/bin/sail build --no-cache`、`./vendor/bin/sail up -d`を使用する。ローカルDBデータを失う可能性があるため、volume削除を伴う`./vendor/bin/sail down -v`は使用しない。
ユーザーの未確認変更や別Issueの差分をまとめて破棄しない。

レビュー完了後、人間がGitHub運用ルールに従ってPull Requestを`develop`向けに作成する。
マージ後は`develop`上でPHPの実測値と品質ゲートを再確認し、その結果を確定baselineとして記録する。

---

## 32. 最後に

Laravelアップグレードで大事なのは、コマンドを全部覚えることではありません。

```text
今の状態を測る
↓
公式差分を調べる
↓
依存関係を調べる
↓
変更前にdry-runする
↓
1段階ずつ変更する
↓
失敗した地点で止まる
↓
自動・目視の両方で確認する
↓
developを次のbaselineにする
```

この流れを守れば、次回のmajorでも同じ考え方で進められます。
