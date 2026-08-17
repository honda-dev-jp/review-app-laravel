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
- [31. 最後に](#31-最後に)

---

## 1. このドキュメントの目的

この文書は、映画レビューアプリ Laravel移植版で Laravel を安全にメジャーアップグレードするための**標準手順書**です。

対象読者は次のような人です。

- Laravelのメジャーアップグレード経験が少ない
- Composerは使ったことがあるが、依存関係の調べ方に自信がない
- コマンドを実行するだけでなく「なぜこの順番なのか」も理解したい
- 将来このプロジェクトを保守する

今回の Laravel 10→11 で実際に発生した問題・実行結果は、
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

Git / GitHub操作の標準手順は
[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)
を正本とします。

Laravelアップグレードでも、通常の作業と同じく
**最新の`develop`から作業ブランチを作成します。**

```bash
# 作業ツリーに未コミット変更がないか確認
git status

# developへ移動
git switch develop

# origin/developの最新状態を取得し、
# fast-forwardできる場合だけローカルdevelopを更新
git pull --ff-only origin develop

# Issueに対応するLaravelアップグレード用作業ブランチを作成
git switch -c <ブランチ名>
```

### 確認すること

#### 1. 作業開始前に未コミット変更がないか

```text
git status
→ working tree clean
```

未コミット変更がある場合は、新しい作業ブランチを作らず停止します。

#### 2. developの最新化に成功したか

```text
git pull --ff-only origin develop
→ 成功
```

`--ff-only`で更新できない場合は、その場で停止します。

独自判断でmerge、rebase、reset、force pushへ切り替えません。

#### 3. 作業ブランチをdevelopから作成できたか

```text
git switch -c <ブランチ名>
→ 新しい作業ブランチへ切り替わる
```

ブランチ名は[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)の命名規則と、
対象Issueの内容に合わせます。

### 追加確認

作業ブランチを作成する直前または作成直後に、
直近の履歴と各ブランチが指しているコミットを確認したい場合は、
次のコマンドを使用します。

```bash
# 直近3件のコミットと、
# そのコミットを指しているローカル・remote-tracking branchを確認
git log --oneline --decorate -3
```

例えば、作業ブランチ作成前に次のように表示された場合:

```text
a24d8ab (HEAD -> develop, origin/develop, origin/main, main) ...
```

`develop`と`origin/develop`が同じコミットを指していることを確認できます。

`main`と`origin/main`も同じ位置に表示されていれば、
その時点では`main`と`develop`も同じコミットです。

ただし、Laravelアップグレード開始時の必須条件は、
`main`と`develop`の4参照一致ではありません。

必須なのは次の3点です。

```text
1. 作業ツリーがクリーン
2. developへ移動済み
3. git pull --ff-only origin develop が成功
```

ここまで確認してから、Laravelアップグレード作業を開始します。

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
sail artisan about

# PHPのバージョンを明示的に確認
sail php -v
```

Laravel Upgrade Guideにcurlの最低バージョン要件がある場合は、
PHPから利用しているcurlのバージョンも確認します。

```bash
# PHPから利用しているcurlのバージョンを確認
sail php -r 'echo curl_version()["version"], PHP_EOL;'
```

フロントエンドのビルド環境もアップグレード前後で比較できるように、
Node.jsとnpmのバージョンを確認します。

```bash
# Sailコンテナで使用しているNode.jsのバージョンを確認
sail node -v

# Sailコンテナで使用しているnpmのバージョンを確認
sail npm -v
```

### 依存パッケージの実バージョン確認

`composer.json`には使用可能なバージョン範囲が書かれています。
実際に`composer.lock`へ固定されている直接依存パッケージのバージョンも確認します。

```bash
# composer.lockに固定された直接依存パッケージの実バージョンを確認
sail composer show --locked --direct
```

### ルートbaseline確認

アップグレード前のルート一覧を記録し、アップグレード後と比較できるようにします。

```bash
# 現在登録されているルート一覧とルート数を確認
sail artisan route:list
```

確認する主な点:

- ルート数
- 認証関連ルート
- `/api/user`など主要ルート
- アップグレード後に意図せず追加・削除されたルートがないか

### 品質確認

```bash
# composer.jsonとcomposer.lockの整合性を確認
sail composer validate --strict

# PHPUnitテストを実行し、既存機能の回帰がないか確認
sail artisan test

# PHPStan / Larastanで静的解析を実行
sail php ./vendor/bin/phpstan analyse

# Pintでコードスタイル違反がないか確認（自動修正はしない）
sail php ./vendor/bin/pint --test

# package-lock.jsonどおりにSail環境のnode_modulesを再構築
# package.jsonやpackage-lock.jsonは変更しない
sail npm ci

# Sail環境でフロントエンドassetsが正常にビルドできるか確認
sail npm run build
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

### 重要

公式ガイドに書かれているpackageだけ見て終わりではありません。

**現在のプロジェクトに実際に入っているpackageを次のStepで確認します。**

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

次の表を作ります。

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
sail composer show --locked --direct
```

Composer公式の`show --locked`は`composer.lock`に固定されたpackageを表示し、`--direct`は直接依存だけへ絞ります。

#### 出力例

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
sail composer show --locked laravel/framework
```

別package:

```bash
sail composer show --locked laravel/sanctum
sail composer show --locked larastan/larastan
sail composer show --locked phpunit/phpunit
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

推移依存で見覚えのないpackageがあるときに使います。

```bash
# このpackageを必要としている依存元をツリー表示
sail composer depends <package> --tree
```

`depends`は`why`の別名です。

例:

```bash
sail composer why symfony/console --tree
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
sail composer prohibits laravel/framework '<移行先major>.*' --tree
```

Laravel 10→11の場合:

```bash
sail composer prohibits laravel/framework '11.*' --tree
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
sail composer show --locked --direct
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
sail composer update <対象package...> \
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

---

## 15. Step 11: composer.lockだけ更新する

dry-runの内容を人間が確認してから進みます。

```bash
# vendorはまだ更新せず、依存関係の解決結果をcomposer.lockへ反映
sail composer update <対象package...> \
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
sail composer show --locked --direct
```

個別:

```bash
sail composer show --locked laravel/framework
sail composer show --locked larastan/larastan
sail composer show --locked phpunit/phpunit
```

### 確認表

| package | 予定 | 実解決 | OK? |
|---|---|---|---|
| Laravel | 11.x | 11.xx.x | Yes/No |
| Sanctum | 4.x | 4.xx.x | Yes/No |
| PHPUnit | 維持 | 10.xx.x | Yes/No |

**constraintだけでなく実解決versionを見る**ことが重要です。

---

## 17. Step 13: composer audit

```bash
# 更新後のcomposer.lockに既知脆弱性がないか確認
sail composer audit --locked
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

---

## 18. Step 14: vendorを更新する

```bash
# composer.lockどおりにvendorを更新し、Composer scriptsはまだ実行しない
sail composer install --no-scripts
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
sail composer dump-autoload
```

確認:

```bash
# Laravel・PHP・DBなど現在のアプリ実行環境を確認
sail artisan about

# 現在登録されているルート一覧とルート数を確認
sail artisan route:list
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
sail artisan test

# PHPStan / Larastanで静的解析を実行
sail php ./vendor/bin/phpstan analyse

# Pintでコードスタイル違反がないか確認（自動修正はしない）
sail php ./vendor/bin/pint --test

# package-lock.jsonどおりにSail環境のnode_modulesを再構築
# package.jsonやpackage-lock.jsonは変更しない
sail npm ci

# Sail環境でフロントエンドassetsが正常にビルドできるか確認
sail npm run build

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

最低限:

- ゲスト作品一覧・詳細
- ページネーション
- 会員登録
- ログイン / ログアウト
- パスワードリセット + Mailpit
- Profile
- アバター
- レビュー
- 評価キャッシュ
- 返信
- 本人レビュー一覧
- レビュー削除
- 退会

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
| `.claude/skills/pre-implementation-review/SKILL.md` | Laravelプロジェクトの前提バージョン |

歴史的記録まで現在値に書き換えません。

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

---

## 27. 停止条件早見表

| 状況 | どうする？ |
|---|---|
| baseline失敗 | アップグレード開始しない |
| `prohibits`でblocker判明 | package公式互換性を調べる |
| dry-run conflict | その場で停止 |
| Security Blocking | advisoryを評価。解除を即実行しない |
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
- Security Blockingを理由確認なしに解除しない
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

## 31. 最後に

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
