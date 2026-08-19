# Laravelメジャーアップグレード実施履歴

## 目次

- [1. このドキュメントの役割](#1-このドキュメントの役割)
- [2. Laravel 10.50.2 → 11.55.1](#2-laravel-10502--11551)
- [3. Laravel 11 → 12への引継ぎ](#3-laravel-11--12への引継ぎ)
- [4. Laravel 10 → 11 完了確認](#4-laravel-10--11-完了確認)
- [5. Laravel 11.55.1 → 12.66.0](#5-laravel-11551--12660)
- [6. PHP 8.2.30 → 8.4.24](#6-php-8230--8424)
- [7. PHP 8.4のdevelop baseline確定](#7-php-84のdevelop-baseline確定)
- [8. Laravel 12.66.0 → 13.26.1](#8-laravel-12660--13261)
- [9. Laravel 13のdevelop baseline確定](#9-laravel-13のdevelop-baseline確定)

---

## 1. このドキュメントの役割

この文書は、Laravelメジャーアップグレードで**実際に行った操作・実測結果・発生した問題・切り分け・判断**を残す履歴です。

毎年使う標準手順は
[Laravelメジャーアップグレードガイド](LARAVEL_UPGRADE_GUIDE.md)
を参照してください。

---

## 2. Laravel 10.50.2 → 11.55.1

### 2.1 Issue

```text
親Issue: #69
子Issue: #122
branch: chore/122-upgrade-laravel-11
```

Laravel 11はEOLのため、公開版ではなく**Laravel 12へ進むための短期中継baseline**として扱う。

```text
mainへ同期しない
XServerへデプロイしない
通常機能開発を挟まない
```

---

### 2.2 変更前baseline

```text
Laravel: 10.50.2
Sail PHP: 8.2.30
Composer: 2.9.7
Database: MySQL
Vite: 6.4.3
esbuild: 0.25.12
laravel-vite-plugin: 1.3.0
curl: 8.5.0
routes: 30
```

確認:

| 項目 | 結果 |
|---|---|
| composer validate | PASS |
| PHPUnit | 126 tests / 1371 assertions PASS |
| PHPStan / Larastan | 66/66 No errors |
| Pint | 109 files PASS |
| npm ci | success |
| Vite build | 56 modules PASS |
| route:list | 30 routes |
| git status | clean |

---

### 2.3 依存関係調査

#### composer.json

```text
php ^8.1
laravel/framework ^10.10
laravel/sanctum ^3.3
laravel/breeze ^1.29
nunomaduro/collision ^7.0
```

#### 実version確認

```bash
./vendor/bin/sail composer show --locked --direct
```

主要実version:

```text
Laravel Framework 10.50.2
Sanctum 3.3.3
Breeze 1.29.1
Collision 7.12.0
Larastan 2.11.2
PHPUnit 10.5.63
Sail 1.58.0
Pint 1.30.4
```

#### Laravel 11互換性調査結果

更新必須:

```text
php ^8.1 → ^8.2
laravel/framework ^10.10 → ^11.0
laravel/sanctum ^3.3 → ^4.0
laravel/breeze ^1.29 → ^2.0
nunomaduro/collision ^7.0 → ^8.1
```

維持:

```text
PHPUnit 10
Larastan 2
Pint 1
Sail 1
Tinker
IDE Helper
BreezeJP
Ignition
Guzzle
Faker
Mockery
```

注意:

- Collision 7.12.0もLaravel 11のblocker
- Larastan 2.11.2はLaravel 11.41.3以上を要求
- PHPUnit 10を維持するにはCollisionの実解決version確認が必要

---

### 2.4 Composer Security Blocking

最初のdry-run:

```bash
./vendor/bin/sail composer update laravel/framework laravel/sanctum laravel/breeze nunomaduro/collision \
  --with-all-dependencies \
  --minimal-changes \
  --dry-run \
  --no-scripts
```

結果:

```text
Laravel 11候補は存在した
しかしSecurity Advisory対象のためComposerが候補から除外
依存解決停止
```

通常のdependency conflictではなく**Security Blocking**だった。

Laravel 11はEOLだが、

```text
本番へ出さない
mainへ出さない
Laravel 12への短期中継
```

という例外条件を人間が確認し、今回だけSecurity Blocking解除を使用。

```bash
./vendor/bin/sail composer update laravel/framework laravel/sanctum laravel/breeze nunomaduro/collision \
  --with-all-dependencies \
  --minimal-changes \
  --dry-run \
  --no-scripts \
  --no-security-blocking
```

#### 注意

`--no-security-blocking`は今回使ったComposer 2.9.7での実施履歴。

将来はその時点のComposer公式CLIを再確認する。

---

### 2.5 dry-run結果

```text
Laravel Framework 10.50.2 → 11.55.1
Breeze 1.29.1 → 2.4.2
Sanctum 3.3.3 → 4.3.3
Collision 7.12.0 → 8.5.0

3 installs
16 updates
0 removals
```

主な推移依存:

```text
Symfony 6.4 → 7.4
Termwind 1.17 → 2.4
league/uri追加
league/uri-interfaces追加
symfony/polyfill-php85追加
```

---

### 2.6 lockfile更新

```bash
./vendor/bin/sail composer update laravel/framework laravel/sanctum laravel/breeze nunomaduro/collision \
  --with-all-dependencies \
  --minimal-changes \
  --no-install \
  --no-scripts \
  --no-security-blocking
```

中間状態:

```text
composer.json → Laravel 11 constraint
composer.lock → Laravel 11解決
vendor → Laravel 10
```

---

### 2.7 lock実version

```text
Laravel Framework: 11.55.1
Sanctum: 4.3.3
Breeze: 2.4.2
Collision: 8.5.0
PHPUnit: 10.5.63
Larastan: 2.11.2
Pint: 1.30.4
Sail: 1.58.0
Carbon: 2.73.0
```

確認:

```text
Laravel 11.55.1 >= Larastan必要条件11.41.3
Carbon 2維持 → Carbon 3対応不要
PHPUnit 10維持
```

---

### 2.8 composer audit

```bash
./vendor/bin/sail composer audit --locked
```

結果:

```text
3 advisories
1 package
laravel/framework
```

実質2脆弱性:

```text
GHSA-crmm-hgp2-wgrp
Temporary Signed URL Path Confusion
medium

GHSA-5vg9-5847-vvmq
CRLF injection in default email rule
high
```

Laravel 11では解消不能。

判断:

```text
audit結果は残す
Laravel 11を公開しない
Laravel 12で解消する
```

---

### 2.9 vendor更新

```bash
./vendor/bin/sail composer install --no-scripts
```

結果:

```text
3 installs
16 updates
0 removals
autoload生成成功
```

---

### 2.10 Sanctum 3 → 4

#### 現状調査

```text
routes/api.phpにauth:sanctum /api/user
UserにHasApiTokens
personal_access_tokens migrationあり

app内createToken利用なし
SPA stateful middleware無効
Sanctum専用testなし
```

削除は別責務のため、Sanctumを4系へ互換更新して維持。

#### config/sanctum.php

変更3点:

```text
App\Http\Middleware\EncryptCookies
→ Illuminate\Cookie\Middleware\EncryptCookies

App\Http\Middleware\VerifyCsrfToken
→ Illuminate\Foundation\Http\Middleware\ValidateCsrfToken

verify_csrf_token
→ validate_csrf_token
```

#### migration

既存migrationは書き換えない。

```text
Sanctum 4.0.0 migration → 現アプリと同schema
Sanctum 4.3.x → name text / expires_at index
```

後者は3→4互換必須変更ではないため今回のscopeから分離。

無条件`vendor:publish`もしない。

---

### 2.11 package discovery / bootstrap

```bash
./vendor/bin/sail composer dump-autoload
```

結果:

```text
package discovery 全package DONE
7006 classes
```

```text
Laravel 11.55.1
PHP 8.2.30
Composer 2.9.7
MySQL
30 routes
/api/userあり
sanctum/csrf-cookieあり
```

---

### 2.12 PHPUnit

Laravel 11切替直後:

```text
126 tests
1371 assertions
PASS
```

既存機能ロジックの大きな回帰なし。

---

### 2.13 PHPStan 18 errors

Laravel 10 baseline:

```text
No errors
```

Laravel 11:

```text
18 errors
exit code 1
```

原因:

```text
Eloquent relation PHPDoc generic
```

対象:

```text
Category
Item
Review
ReviewComment
User
```

12 relation methods。

修正:

```php
@return HasMany<Item>
↓
@return HasMany<Item, $this>
```

```php
@return BelongsTo<Category, Item>
↓
@return BelongsTo<Category, $this>
```

内訳:

```text
HasMany 6 × 2 errors = 12
BelongsTo 6 × 1 error = 6
合計18
```

メソッド本体は変更せずPHPDocだけ修正。

再実行:

```text
67/67
[OK] No errors
```

教訓:

> 静的解析エラーを即「アプリが壊れた」と判断しない。frameworkの型定義変更を確認する。

---

### 2.14 Sanctum最小Featureテスト

追加:

```text
tests/Feature/SanctumApiUserTest.php
```

2ケース:

```text
guest GET /api/user → 401

createToken
→ Bearer token
→ GET /api/user
→ 200
→ user id一致
```

実経路:

```text
HasApiTokens
personal_access_tokens
Bearer解析
auth:sanctum
/api/user
```

結果:

```text
単体 2 tests / 3 assertions PASS
全体 128 tests / 1374 assertions PASS
```

---

### 2.15 最終自動回帰

```text
PHPUnit: 128 tests / 1374 assertions PASS
PHPStan / Larastan: 67/67 No errors
Pint: 110 files PASS
Vite: 6.4.3 / 56 modules PASS
git diff --check: no output
```

---

### 2.16 目視回帰

確認済み:

#### ゲスト

- 作品一覧
- ページネーション
- 作品詳細
- レビュー
- 返信
- 星評価

#### 認証

- 会員登録
- validation
- ログイン
- ログアウト
- 認証済みnavigation

#### パスワードリセット

- メール送信
- Mailpit受信
- メール本文
- reset link
- password再設定

#### Profile

- nickname
- profile
- avatar差し替え
- header avatar
- password update
- validation
- success message

#### Review

- 投稿
- 評価
- 平均評価
- 評価件数
- 重複投稿防止

#### Reply

- 本人reviewへの自己返信
- 他人reviewへの返信
- avatar / nickname

#### My Reviews

- 一覧
- 削除modal
- 削除
- empty state

#### Account deletion

- 退会modal
- password確認
- account削除
- guestへ戻る
- success message

退会後匿名化は手動未確認。既存Feature testで確認済み。

---

### 2.17 Chrome Enterキーの切り分け

パスワードリセット画面:

```text
通常Chrome:
focus済みsubmit button
Space → 成功
Enter → 送信されない

シークレットChrome:
Enter → 成功
```

Blade:

```html
<button type="submit">
```

判断:

```text
通常Chrome profile / extension側の影響候補
Laravel 11回帰ではない
```

特定extensionを原因と断定しない。

教訓:

> browserだけで変な挙動があったら、コードを直す前にシークレット・別browser・extensionを切り分ける。

---

### 2.18 Profile validation時の位置

成功:

```text
/profile#update-password
→ password form位置
```

validation error:

```text
/profile
→ form位置へ自動復帰しない
```

Laravel 11回帰ではなく既存仕様。

改善余地はあるが今回へ混ぜない。

---

### 2.19 Laravel 11を中継baselineにする理由

Laravel 11はIssue #122時点でEOL。

目的は公開ではなく、

```text
Laravel 10→11の変更だけ切り分ける
↓
develop baseline
↓
Laravel 11→12
```

Laravel 11段階:

- mainへ同期しない
- XServerへデプロイしない
- 通常機能開発を挟まない
- advisoryを記録
- develop確認後すぐ12へ進む

---

### 2.20 developマージ後baseline再確認

PR #123を`develop`へマージし、Merge commit `f639eb8`を取り込んだ`develop`上でbaselineを再確認した。

```text
PR: #123 chore: Laravel 10から11へ段階的にアップグレードする
Base: develop
Merge commit: f639eb8
GitHub Actions: 2 checks passed
```

マージ後、作業ブランチ`chore/122-upgrade-laravel-11`はリモート・ローカルとも削除済み。

#### 実行環境

`./vendor/bin/sail artisan about`で確認:

```text
Laravel: 11.55.1
PHP: 8.2.30
Composer: 2.9.7
Database: MySQL
```

#### 自動確認

| 確認 | 結果 |
|---|---|
| `composer validate --strict` | PASS（`./composer.json is valid`） |
| PHPUnit | 128 tests / 1374 assertions PASS |
| PHPStan / Larastan | 67/67、No errors |
| Pint | 110 files PASS |
| `npm ci` | success（121 packages added / 122 packages audited） |
| Vite build | Vite 6.4.3 / 56 modules transformed / PASS |
| `git status --short` | no output（clean） |
| `git diff --check` | no output |

`npm ci`では既知の`1 high severity vulnerability`が表示された。これはLaravel 10→11で新たに修正する対象ではなく、別npm課題として扱う。`npm audit fix`は実行していない。

#### Composer audit

```bash
./vendor/bin/sail composer audit
```

結果:

```text
3 security vulnerability advisories
1 package
laravel/framework
```

実質的な脆弱性は、2.8で記録済みの次の2種類:

```text
GHSA-crmm-hgp2-wgrp
Temporary Signed URL Path Confusion
medium

GHSA-5vg9-5847-vvmq
CRLF injection in default email rule
high
```

`GHSA-5vg9-5847-vvmq`は別advisory sourceからも表示されるため、Composerの表示上は合計3 advisoriesとなる。develop baseline再確認で、HISTORYへ未記録の新しいSecurity Advisoryは増えていない。

判断は維持する:

```text
audit結果を隠さない
Laravel 11をmainへ出さない
Laravel 11をXServerへ出さない
Laravel 12で解消確認する
```

以上により、Laravel 11.55.1をLaravel 12へ進むための短期中継baselineとして確定した。

---

## 3. Laravel 11 → 12への引継ぎ

### 前段完了

- PR #123を`develop`へマージ済み（Merge commit `f639eb8`）
- GitHub Actions 2 checks passed
- `develop`上でbaseline再確認済み
- Laravel 11.55.1を短期中継baselineとして確定

### 引継ぎ事項

1. Laravel 12公式Upgrade Guideを最新確認
2. 残存Dependabot Alert No.6 / No.7の解消version確認
3. Security Blocking解除を通常運用へ持ち越さない
4. composer.json / composer.lockを再調査
5. PHPをどの段階で更新するか再判断
6. Sanctum削除は必要なら別cleanup
7. Carbonの実解決version再確認
8. 128 tests / 1374 assertionsを次baselineとして使用

---

## 4. Laravel 10 → 11 完了確認

```text
PR #123 develop merge: 完了
GitHub Actions CI: 2 checks passed
develop baseline再確認: PASS
Laravel 11.55.1: 短期中継baselineとして確定
```

Laravel 11は`main`へ同期せず、XServerへデプロイせず、通常機能開発を挟まずにLaravel 12へ進む。

---

## 5. Laravel 11.55.1 → 12.66.0

### 5.1 記録範囲

```text
親Issue: #69
子Issue: #126
起点: develop / 8ec1465
記録時点: 作業ブランチ上（PR・CI・developマージ前）
```

Issue #126では、Laravel 11の短期中継baselineからLaravel 12へ1メジャーだけ更新した。
PHPは`8.2.30`のまま維持し、Laravel 10 / 11形式のapplication structureも維持した。

この時点では、以下は実施していない。

- `main`への同期
- XServerへのデプロイ
- PHP 8.4への更新
- Laravel 13への更新
- PR作成、GitHub Actions、`develop`へのマージ
- マージ後の`develop` baseline再確認

### 5.2 変更前baseline

主要version:

| 項目 | 変更前 |
|---|---:|
| Laravel | 11.55.1 |
| PHP | 8.2.30 |
| Composer | 2.9.7 |
| PHPUnit | 10.5.63 |
| Larastan | 2.11.2 |
| Collision | 8.5.0 |
| Carbon | 2.73.0 |
| BreezeJP | 1.8.3 |
| Laravel IDE Helper | 3.1.0 |
| Sanctum | 4.3.3 |
| Breeze | 2.4.2 |
| Pint | 1.30.4 |
| Sail | 1.58.0 |
| Vite | 6.4.3 |

検証結果:

| 項目 | 結果 |
|---|---|
| PHPUnit | 128 tests / 1374 assertions PASS |
| PHPUnit deprecation | なし |
| PHPStan / Larastan | 67/67、No errors（level 4） |
| Pint | 110 files PASS |
| Vite build | 56 modules transformed、PASS |
| Composer audit | 既知の3 advisories（1 package） |
| npm audit | 既知のhigh 1件 |

### 5.3 `composer.json`の直接依存変更

意図的に変更した直接依存制約は4件だけである。

| パッケージ | 変更前 | 変更後 | 判断 |
|---|---:|---:|---|
| `laravel/framework` | `^11.0` | `^12.61.1` | Laravel 12化と既知脆弱性の修正版を下限にする |
| `askdkc/breezejp` | `^1.8` | `^2.2` | Laravel 12互換版へ更新する |
| `larastan/larastan` | `^2.0` | `^3.1` | Laravel 12 / PHPStan 2対応版へ更新する |
| `phpunit/phpunit` | `^10.1` | `^11.0` | Laravel 12公式Upgrade Guideに合わせる |

Laravel IDE HelperとCollisionは既存の直接依存制約を維持し、ComposerソルバーでLaravel 12互換版へ更新した。
Carbon 3、Laravel Prompts 0.3系、PHPStan 2系、PHPUnit 11関連パッケージは推移依存として解決し、個別固定しなかった。

### 5.4 Composer解決結果

dry-runを確認した後、同じ対象に`--no-install --no-scripts`を付けてlockfileを更新し、内容確認後にvendorを同期した。

```text
3 installs
30 updates
4 removals
```

確認できた主要な実version:

| パッケージ | 実version |
|---|---:|
| `laravel/framework` | 12.66.0 |
| `larastan/larastan` | 3.10.0 |
| `phpstan/phpstan` | 2.2.8 |
| `phpunit/phpunit` | 11.5.56 |

LaravelはIssueの下限`12.61.1`以上へ解決され、PHPは`8.2.30`のまま維持された。
package discoveryとLaravel bootstrapも成功した。

### 5.5 Composerセキュリティ確認

```text
security advisories: 0
abandoned packages: 0
exit code: 0
```

- Security Blockingは有効なまま使用した
- `--no-security-blocking`を使用していない
- advisory ignoreやaudit回避設定を追加していない
- Laravel `12.66.0`は署名付きURL脆弱性の修正版`12.61.1`以上である
- Laravel `12.66.0`はメールアドレスCRLF注入脆弱性の修正版`12.60.0`以上である

Dependabot Alerts No.6 / No.7は既定ブランチ`main`を基準に評価されるため、作業ブランチ時点でのCloseを本Issueの受け入れ条件にはしない。

### 5.6 Laravel 12化で必要になった最小修正

初回のPHPStan / Larastan 3解析では9件の指摘が発生した。

| 修正 | 件数 | 対象・判断 |
|---|---:|---|
| Eloquentモデルの属性配列PHPDoc | 6 | `Category`、`Item`、`Review`、`ReviewComment`、`User`の`$fillable`と`User::$hidden`を`array<int, string>`から`list<string>`へ調整 |
| 不要なnullsafe演算子 | 2 | `tests/Feature/ProfileTest.php`で`DOMNodeList::item(0)`がnullでないと確定済みの箇所から`?->`を除去 |
| 雛形テストの削除 | 1 | 常に真となる`assertTrue(true)`だけを持つ`tests/Unit/ExampleTest.php`を削除 |

PHPStanのbaseline、`ignoreErrors`、stub、level変更、設定変更は使用していない。
修正後は`66/66`、`No errors`となった。解析対象が`67`から`66`へ減ったのは、`tests/Unit/ExampleTest.php`の削除と一致する。

`tests/Unit/ExampleTest.php`は常に真となる`assertTrue(true)`だけのLaravel標準雛形であり、有効な回帰を検証しないため削除した。一方、`phpunit.xml`のUnit testsuiteと将来追加するUnitテストの自動検出を維持し、fresh cloneでも`tests/Unit`が存在するよう、空ファイル`tests/Unit/.gitkeep`を追加した。`.gitkeep`はPHPUnit、PHPStan、Pintの対象にならないため、実測件数は127 tests、PHPStan 66 files、Pint 109 filesのままである。現在のUnitテストは0件である。

アプリケーションの実行ロジックやLaravel 12新規skeletonへの構造変更は行っていない。

### 5.7 自動回帰結果

実行コマンドは、環境依存のaliasを前提にせず、リポジトリ内のSailを明示した。

```bash
./vendor/bin/sail composer validate --strict

./vendor/bin/sail php ./vendor/bin/phpunit \
  --display-deprecations \
  --display-phpunit-deprecations

./vendor/bin/sail php ./vendor/bin/phpstan analyse
./vendor/bin/sail php ./vendor/bin/pint --test
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
```

| 項目 | 結果 |
|---|---|
| Composer validate | PASS（`./composer.json is valid`） |
| Laravel | 12.66.0 |
| PHP | 8.2.30 |
| PHPUnit | 11.5.56、127 tests / 1373 assertions PASS |
| PHPUnit deprecation | なし |
| PHPStan / Larastan | 66/66、No errors（level 4） |
| Pint | 109 files PASS |
| `npm ci` | 121 packages added、122 packages audited、成功 |
| Vite build | Vite 6.4.3、56 modules transformed、成功 |
| `artisan about` | 成功 |
| `artisan route:list` | 成功、30 routes |
| ルート名重複 | 0件 |
| `git diff --check` | 問題なし |

`npm audit`のhigh 1件は変更前から存在する別課題であり、本Issueでは増加も修正も行っていない。

### 5.8 手動回帰結果

#### ゲスト

- 作品一覧を表示できる
- 作品一覧のページネーションを操作できる（全13件、1ページ目1〜10件、2ページ目11〜13件）
- 作品詳細を表示できる
- 星評価、平均評価、評価件数を表示できる
- レビューと返信を表示できる
- 未認証時にレビュー・返信のログイン案内が表示される

#### 認証・プロフィール

- 新規登録後、自動ログインして作品一覧へ遷移できる
- ログイン / ログアウトできる
- アカウント画面でニックネームと自己紹介を更新できる
- アバターの新規登録、差し替え、表示を確認した
- パスワード変更後、旧パスワードでログインできず、新パスワードでログインできる
- Mailpitでパスワードリセットメールを受信できる
- パスワード再設定後、旧パスワードでログインできず、新パスワードでログインできる

独立したアバター削除機能はIssue #126の対象外であり、実施済みとは扱わない。

#### レビュー・返信・評価キャッシュ

- レビューを投稿できる
- 返信を投稿し、親レビュー内に表示できる
- レビュー投稿後、平均評価と評価件数が`5.0 / 1件`から`3.0 / 2件`へ更新された
- 本人レビュー一覧からレビューを削除できる
- 親レビュー削除時に、その配下の返信も表示から消えた
- 削除後、平均評価と評価件数が`5.0 / 1件`へ戻った
- 退会済みユーザーの既存レビューは「匿名」として残った

#### 本人レビュー一覧

- 全11件を確認した
- 1ページ目に1〜10件を表示できる
- 2ページ目に11件目を表示できる
- ページ番号と前後移動を操作できる
- 作品詳細への導線を表示できる

#### アカウント削除

- 確認モーダルを表示できる
- 現在のパスワードを入力してアカウントを削除できる
- 削除後にログアウトし、作品一覧へ遷移できる
- 投稿済みレビューは削除せず、「匿名」として保持された

#### 表示幅

- デスクトップ幅とモバイル幅で主要画面、ナビゲーション、主要フォーム、ページネーションを表示できた
- モバイル幅でレスポンシブヘッダーを表示できた

### 5.9 自動テストで確認した回帰

127件の全テスト成功により、次を含む既存Featureテストが通過した。現在のUnitテストは0件である。

- Sanctum Bearerトークン認証
- 未認証・権限不足時の拒否動作
- 不正画像形式、GIF拒否、サイズ超過、2 MB境界値
- 画像保存、差し替え、削除、共有パス保護
- 会員退会後の匿名表示
- レビュー投稿・削除後の評価平均と評価件数更新

### 5.10 記録時点の残作業

- Codex / Claude Codeで全差分レビューを実施する
- `develop`向けPRを作成し、本文に`Refs #126`を記載する
- GitHub Actionsの全ジョブ成功を確認する
- merge commitで`develop`へマージする
- マージ後の`develop`上でLaravel 12 baselineを再確認する
- Issue #126配下に記録専用docs子Issueを作成し、確定baselineとPHP 8.4への引継ぎを追記する

このため、5.1〜5.10は**作業ブランチ上で確認済みの実測記録**であり、`develop`上の確定baselineは5.11に記録する。

### 5.11 PR #127マージ後の`develop`確定baseline

Issue #128で、PR #127のマージ結果と、その後の`develop`上の状態を確定記録した。

#### PR・GitHub Actions

```text
PR: #127 chore: Laravel 11から12へ段階的にアップグレードする
Base: develop
Merge commit: 6b5e4fc
GitHub Actions: 2 checks passed
0 cancelled / 0 failing / 0 skipped / 0 pending
```

成功したChecks:

```text
CI/python-quality-checks (pull_request)
CI/quality-checks (pull_request)
```

PRマージ後、作業ブランチ`chore/126-upgrade-laravel-12`はリモート・ローカルとも削除済みである。

#### PR #127へ含めたコミットの品質ゲート

次の値は、PR #127へ含めたコミットについて、マージ前のローカル確認およびPRのGitHub Actionsで確認した結果である。マージ後の`develop`で品質ゲート一式を再実行した結果ではない。

| 確認 | 結果 |
|---|---|
| `composer validate --strict` | PASS（`./composer.json is valid`） |
| `composer audit --locked` | advisories 0 / abandoned packages 0 |
| PHPUnit | 127 tests / 1373 assertions PASS |
| PHPStan / Larastan | 66/66、No errors |
| Pint | 109 files PASS |
| `npm ci` | PASS |
| Vite build | Vite 6.4.3 / 56 modules transformed / PASS |
| ルート | 30 routes / route name重複0 |

Issue #126で指定した手動回帰確認および自動テストによる確認も完了した。新規登録、ログイン・ログアウト、パスワード変更・リセット、Mailpitへのメール送信、プロフィールとアバター、作品一覧・詳細・ページネーション、レビューと返信コメント、本人レビュー一覧、評価集計、画像バリデーション、退会後の匿名表示、Sanctumトークン認証、未認証・権限不足時の拒否、デスクトップ幅・モバイル幅での主要画面表示を確認している。詳細は5.8および5.9の記録を正本とする。

#### マージ後の`develop`で確認した状態

PR #127のMerge commitを取り込んだ`develop`上では、`./vendor/bin/sail artisan about`により次を確認した。

```text
Laravel: 12.66.0
PHP: 8.2.30
Composer: 2.9.7
Database: MySQL
Environment: local
Maintenance Mode: OFF
Timezone: Asia/Tokyo
Locale: ja
public/storage: LINKED
```

Git状態は次のとおりであった。

```text
develop HEAD: 6b5e4fc
origin/develop: 6b5e4fc
git status --short: no output
```

以上により、次をPHP更新へ進む前の`develop`確定baselineとする。

```text
Laravel 12.66.0
PHP 8.2.30
Composer 2.9.7
MySQL
```

この記録時点では、Laravel 12の`main`への同期およびXServerへのデプロイは実施していない。

### 5.12 PHP 8.4とLaravel 13への引継ぎ

PHP 8.4への実更新はIssue #128では行わず、別Issueで扱う。後続Issueでは、PHP 8.4の公式要件、Laravel 12と利用パッケージの互換性、ローカル・CI・XServerの実行環境を確認する。

PHP 8.4更新後は、5.11で確定したLaravel 12.66.0 / PHP 8.2.30 baselineと、品質ゲートおよび手動回帰結果を比較する。

Laravel 12 → 13はPHP 8.4更新と分離する。PHP 8.4更新後の`develop` baselineを確定してから、Laravel 12 → 13を別Issueとして開始する。Issue #128ではPHP 8.4およびLaravel 13の依存変更を行わない。

---

## 6. PHP 8.2.30 → 8.4.24

### 6.1 記録範囲

```text
親Issue: #69
子Issue: #130
branch: chore/130-upgrade-php-84
起点: develop / 810a059
記録時点: 作業ブランチ上（PR・CI・developマージ前）
```

Issue #130では、PR #127で`develop`へマージ済みのLaravel 12.66.0を維持し、ローカルSailとGitHub ActionsのPHP対象を8.2から8.4へ変更した。

この記録時点で、Issue #130の変更は`main`およびXServer本番環境へ未反映である。

### 6.2 更新前baseline

Issue #128で確定した、PHP更新前の`develop` baselineは次のとおり。

```text
Laravel: 12.66.0
PHP: 8.2.30
Composer: 2.9.7
Database: MySQL
```

詳細は5.11の確定記録を正本とする。

### 6.3 PHP実行環境とルート要件の変更

| 対象 | 変更前 | 変更後 |
|---|---:|---:|
| `composer.json` PHP要件 | `^8.2` | `^8.4` |
| `composer.lock` platform PHP | `^8.2` | `^8.4` |
| Sail runtime | `runtimes/8.2` | `runtimes/8.4` |
| Sail image | `sail-8.2/app` | `sail-8.4/app` |
| GitHub Actions PHP指定 | `8.2` | `8.4` |

`composer.lock`の差分はcontent hashとplatform PHPの変更に限定されている。packageのname、version、source、distに変更はなく、`plugin-api-version`は`2.9.0`を維持している。

### 6.4 PHP 8.4の丸め変更に対する回帰テスト

`ItemRatingService`は、1から5の整数評価の平均を`round(..., 1)`で小数第1位へ丸めて`items.rating`へ保存する。

PHP 8.4の丸め処理変更に対する最小限の回帰確認として、評価17件の`3`と3件の`2`から平均`2.85`を作り、`2.9`と評価件数20件が保存されることを検証するFeatureテストを追加した。追加後の全PHPUnit 128件がPHP 8.4.24上で成功した。

### 6.5 作業ブランチ上で実測済みのversion

| 項目 | 実測値 |
|---|---:|
| PHP | 8.4.24 |
| Composer | 2.10.2（ローカルSailコンテナ内。実行PHP 8.4.24、PHP path `/usr/bin/php8.4`） |
| Laravel | 12.66.0 |
| PHPUnit | 11.5.56 |
| Vite | 6.4.3 |
| Node.js | 24.19.0 |
| npm | 12.0.2 |

`composer.lock`の`plugin-api-version`は`2.9.0`を維持している。

### 6.6 XServer本番環境との区別

XServerで過去に実測したPHPは次のとおり。これらはIssue #130のローカルSail更新結果ではない。

| 対象 | XServer実測値 |
|---|---:|
| Web実行PHP | 8.4.20 |
| SSH上の通常`php`（`~/bin/php`） | 8.3.30 |
| `/usr/bin/php8.4` | 8.4.20 |

ローカルSailコンテナ内の`/usr/bin/php8.4`はPHP 8.4.24、XServer上の`/usr/bin/php8.4`はPHP 8.4.20であり、同じpath表記でも別の実行環境である。

Issue #130でXServerのPHP設定変更、Composer実行経路の確定、本番デプロイは行っていない。

### 6.7 関連ドキュメント

作業ブランチ上のPHP 8.4.24 baseline候補と、`develop`・`main`・本番環境へ未反映である状態を関連ドキュメントへ反映した。

また、`docs/LARAVEL_UPGRADE_GUIDE.md`にPHPバージョン更新の標準手順を追加した。標準手順とIssue #130の実測履歴を分け、後続のPHP更新で再利用する手順はガイド、今回の値と結果は本履歴を正本とする。

### 6.8 ComposerとPHP platform確認

| 項目 | 結果 |
|---|---|
| `composer validate --strict` | PASS（`./composer.json is valid`） |
| `composer audit --locked` | PASS（`No security vulnerability advisories found.`） |
| `composer check-platform-reqs --lock` | 全項目`success` |
| PHP platform | PHP 8.4.24および必要なPHP拡張がすべて適合 |

2026-08-19に作業ブランチ上のローカルSail環境で`./vendor/bin/sail composer install`を実行した。lock fileから依存関係を確認した結果は`Nothing to install, update or remove`で、optimized autoloadの生成、`Illuminate\Foundation\ComposerScripts::postAutoloadDump`、`php artisan package:discover`が成功した。discovery対象の`askdkc/breezejp`、`barryvdh/laravel-ide-helper`、`laravel/breeze`、`laravel/sail`、`laravel/sanctum`、`laravel/tinker`、`nesbot/carbon`、`nunomaduro/collision`、`nunomaduro/termwind`、`spatie/laravel-ignition`はすべて`DONE`となった。package versionの変更および`composer.lock`の追加変更はなかった。

### 6.9 自動回帰結果

| 項目 | 結果 |
|---|---|
| PHPUnit | 11.5.56 / Runtime PHP 8.4.24 / 128 tests / 1375 assertions / OK |
| PHPUnit deprecation | `--display-deprecations`および`--display-phpunit-deprecations`で表示なし |
| PHPStan / Larastan | 66/66、No errors |
| Pint | 109 files、PASS |
| `npm ci` | 121 packages added / 122 packages audited / 完了 |
| Vite build | Vite 6.4.3 / 56 modules transformed / 805ms / 成功 |
| route | 30件 / route name重複0件 |

`jq`は未導入のため、route数とroute name重複は`route:list --json`の出力をSail内のPHPで解析して確認した。

### 6.10 npmの既知警告

`npm ci`では、更新前から継続する`1 high severity vulnerability`を再検出した。この1件はIssue #126の実施履歴5.2および5.7で既知の別npm課題として記録済みである。Issue #130で`npm audit fix`は実行していない。

また、npm 12.0.2による`esbuild@0.25.12`のinstall scriptブロック警告を再確認した。install scriptの承認は行っていない。`esbuild@0.25.12`は`package-lock.json`の現在値であり、Viteとesbuildの既知Dependabot alertsはIssue #113で対応済みである。一方、このinstall scriptブロック警告自体の専用管理Issueまたはdocs記録は、この記録時点では確認できなかった。専用の調査Issueは現時点で作成せず、Laravel 14を`main`へマージした後に作成要否を検討する。

package更新、`npm audit fix`、install script承認は、いずれもPHP 8.4更新に必要な変更とは確認されていないため、Issue #130へ混在させていない。

### 6.11 手動回帰結果

2026-08-19に人間が、Issue #130の作業ブランチ上のPHP 8.4.24ローカルSail環境で画面表示と操作結果を確認した。これはPR CIまたは`develop`マージ後の確認ではない。

#### ゲスト

- 作品一覧13件を表示した
- 1ページ目に1〜10件、2ページ目に11〜13件を表示し、ページネーションを操作できた
- 作品詳細、平均評価、評価件数、レビュー、返信を表示できた
- 未認証時のレビュー・返信ログイン案内を表示できた

#### 認証・プロフィール

- 新規登録後に自動ログインし、作品一覧へ遷移できた
- ログインとログアウトを実行できた
- ニックネームと自己紹介を更新できた
- アバターを新規登録・差し替えし、アカウント画面とヘッダーへの反映を確認した
- パスワードを変更し、旧パスワードによるログインが拒否されることを確認した
- Mailpitでパスワードリセットメールを受信し、再設定後の新パスワードでログインできた

#### レビュー・返信・評価キャッシュ

- `Cupiditate exercitationem ut.`へ5星レビューを投稿できた
- 投稿前の`2.0 / 2件`から投稿後の`3.0 / 3件`へ平均評価と評価件数が更新された
- `PHP 8.4返信テスト`を親レビュー内へ表示できた
- 本人レビュー一覧からレビューを削除し、配下の返信も表示から消失した
- 削除後、平均評価と評価件数が`2.0 / 2件`へ復元した

#### 本人レビュー一覧

- 全11件を確認した
- 1ページ目に1〜10件、2ページ目に11件目を表示できた
- ページ番号、前後移動、作品詳細への導線を操作・表示できた

#### アカウント削除

- 確認モーダルを表示し、現在のパスワード入力後にアカウントを削除できた
- 削除後に自動ログアウトし、作品一覧へ遷移できた
- `Quidem perspiciatis omnis minus eos.`の5星レビューは削除されず、退会後の投稿者が「匿名」と表示された
- レビュー本文と評価が保持され、平均評価と評価件数は`4.0 / 4件`を維持した

#### 表示幅

- デスクトップ幅とモバイル幅で主要画面を表示できた
- モバイル幅でレスポンシブヘッダーとメニューを開閉できた
- 作品一覧、作品詳細、本人レビュー一覧、アカウント、ログイン画面を確認した
- 横方向の重大な表示崩れはなかった

### 6.12 この記録時点で未確認の結果

次の項目は、この依頼で実測結果が提示されていないため、実行済みまたはPASSとは記録しない。

- GitHub Actions CI

今後、これらの結果を実測した場合は、作業ブランチ上、PR CI、マージ後の`develop`のどの時点で確認したかを区別して追記する。

---

## 7. PHP 8.4のdevelop baseline確定

### 7.1 Issue #132の目的

Issue #132では、Issue #130の作業ブランチ上で確認したPHP 8.4候補状態を歴史的記録として維持したまま、PR #131のマージ結果と、マージ後の`develop`上で実測したLaravel 12 + PHP 8.4の確定baselineを記録する。PHP・Laravel・Composer packageの追加更新やLaravel 12 → 13の実装は行わない。

本節は、第6章に記録したIssue #130の作業ブランチ上の実測に対する、PR #131マージ後の`develop`確定baselineであり、第6章で未確認としていたGitHub Actions CIの結果は7.2に記録する。

### 7.2 PR #131とGitHub Actions

```text
PR: #131 chore: Laravel 12環境のPHPを8.4へ更新する
Base: develop
Merge commit: 1e53ddd
GitHub Actions: 2 successful checks
```

成功したChecksは次のとおり。

- `CI/python-quality-checks (pull_request)`
- `CI/quality-checks (pull_request)`

作業ブランチ`chore/130-upgrade-php-84`は、PR #131のマージ後にリモート・ローカルとも削除済みである。

### 7.3 マージ後のdevelop確定baseline

PR #131のMerge commitを反映した`develop`上で、次を確認した。

| 項目 | 確定値 |
|---|---:|
| PHP | 8.4.24 |
| Laravel Framework | 12.66.0 |
| Composer | 2.10.2 |
| Composer実行PHP | 8.4.24（PHP path `/usr/bin/php8.4`、ローカルSailコンテナ内） |
| `develop` / `origin/develop` | `1e53ddd`で一致 |
| Git状態 | `git status --short`は無出力 |

確認時点のlocal branchは`develop`と`main`、remote-tracking branchは`origin/develop`と`origin/main`であった。

XServer上の`/usr/bin/php8.4`はPHP 8.4.20であり、ローカルSailコンテナ内の同名pathで実行したPHP 8.4.24とは別環境である。Issue #132ではXServer操作・デプロイを行っていない。

この記録時点で、Laravel 12 + PHP 8.4の確定baselineは`develop`のみに反映されており、`main`およびXServer本番環境へは未反映である。

### 7.4 マージ後のdevelop実測結果

| 確認 | 結果 |
|---|---|
| `composer check-platform-reqs --lock` | PHP 8.4.24と必要なPHP拡張を含む全項目`success` |
| `composer validate --strict` | PASS（`./composer.json is valid`） |
| `composer audit --locked` | PASS（`No security vulnerability advisories found.`） |
| PHPUnit | 11.5.56 / Runtime PHP 8.4.24 / 128 tests / 1375 assertions / PASS |
| PHPUnit deprecation | `--display-deprecations`および`--display-phpunit-deprecations`で表示なし |
| PHPStan / Larastan | 66/66 / No errors |
| Pint | 109 files / PASS |
| Vite build | Vite 6.4.3 / 56 modules transformed / 986ms / PASS |
| Routes | 30 routes / route name重複0 |

### 7.5 確認時点の区別

Issue #130の作業ブランチ上では、`composer install`、`npm ci`、主要機能の手動回帰、デスクトップ幅・モバイル幅の表示確認を実施した。これらはマージ後の`develop`で再実行した結果ではない。

PR #131では、GitHub ActionsのPHP 8.4環境で2 checksが成功した。7.4の結果は、PR CIとは別に、マージ後の`develop`上で実測した結果である。

### 7.6 次段階への引継ぎ

Laravel 12.66.0 + PHP 8.4.24 + Composer 2.10.2 + MySQLを、Laravel 12 → 13へ進む前の`develop`確定baselineとする。

Laravel 12 → 13はIssue #132へ混在させない。本Issueのbaseline記録を完了した後、親Issue #69配下にLaravel 12 → 13の子Issueを作成し、本節のbaselineを更新前比較対象として引き継ぐ。

---

## 8. Laravel 12.66.0 → 13.26.1

### 8.1 Issueと確認時点

親Issue #69から分離したIssue #134で、Laravel 12から13への1メジャー更新を実施した。

本章は`chore/134-upgrade-laravel-13`作業ブランチ上の候補結果である。Laravel 13はまだ`develop`、`main`、本番環境へ反映されていない。PR番号、GitHub Actionsの結果、マージ結果は未確定のため記録しない。

| 項目 | `develop`確定baseline | Issue #134作業ブランチ上の候補状態 |
|---|---:|---:|
| Laravel Framework | 12.66.0 | 13.26.1 |
| PHP | 8.4.24 | 8.4.24 |
| Composer | 2.10.2 | 2.10.2 |
| PHPUnit | 11.5.56 | 12.5.33 |

### 8.2 依存関係の変更

直接依存のconstraintを次のように変更した。

- `laravel/framework`: `^12.61.1` → `^13.0`
- `laravel/tinker`: `^2.8` → `^3.0`
- `phpunit/phpunit`: `^11.0` → `^12.0`

作業ブランチ上ではLaravel Framework 13.26.1、Laravel Tinker 3.0.2、PHPUnit 12.5.33へ解決された。Laravel 13移行に必要な依存解決に限定し、Composer Security Blockingを無効化する設定やadvisory ignoreは追加していない。

直接依存の実解決versionも確認し、Laravel 13またはPHP 8.4と静的に矛盾するpackageがないことを確認した。

| 関連package | 作業ブランチ上の実version |
|---|---:|
| `laravel/sanctum` | 4.3.3 |
| `askdkc/breezejp` | 2.6.3 |
| `laravel/breeze` | 2.4.2 |
| `spatie/laravel-ignition` | 2.12.0 |
| `larastan/larastan` | 3.10.0 |
| `barryvdh/laravel-ide-helper` | 3.7.0 |
| `laravel/sail` | 1.58.0 |

`laravel/boost`と`pestphp/pest`はこのリポジトリの依存関係にないため、Laravel 13移行を理由とする追加は行っていない。

### 8.3 Laravel 13対応

- `app/Http/Middleware/VerifyCsrfToken.php`の継承元を`PreventRequestForgery`へ変更した
- `config/sanctum.php`の`ValidateCsrfToken`直接参照を`PreventRequestForgery`へ変更した
- `config/cache.php`へ`serializable_classes=false`を追加した
- `config/session.php`へ`serialization=json`を追加した
- `CACHE_PREFIX`、`REDIS_PREFIX`、`SESSION_COOKIE`の既存fallbackは維持した
- Laravel 13公式skeletonの該当コメントを採用した
- Laravel 10形式のアプリケーション構造は全面移行せず維持した

cacheとsessionの設定は、`APP_KEY`漏えい時にPHP object deserializationのgadget chainを悪用される攻撃面を縮小する多層防御である。session形式の変更により既存sessionは引き継がれず、利用者は再ログインが必要になるが、DBデータは削除されない。

BreezeJP 2.6.3のパスワードリセット通知callbackが既存の`Reset Password Notification`翻訳を使用することを確認したため、`lang/ja.json`へLaravel 13本体の新しいsubject keyは追加していない。

### 8.4 Laravel 13公式Upgrade Guideの全項目判定

2026-08-19時点の[Laravel 13 Upgrade Guide](https://laravel.com/framework/docs/13.x/upgrade)を見出し単位で確認した。アプリ所有コードの基本検索範囲は、`app/`、`bootstrap/`、`config/`、`database/`、`resources/`、`routes/`、`tests/`のPHPファイルである。`vendor/`と生成物は非該当判定の検索対象に含めていない。

検索コマンドが無出力かつ終了コード`1`の場合は「一致なし」、終了コード`2`以上は「検索エラー」と区別した。次表の「非該当」は、該当API・class・設定・実装の一致がないこと、またはリポジトリの実行対象ではないことを確認した結果である。

`upsert` / `uniqueBy`は、次の検索を再実行した。

```bash
rg -n \
  --glob '*.php' \
  '\bupsert\s*\(|\buniqueBy\b' \
  app bootstrap config database resources routes tests
```

結果は無出力、終了コード`1`（一致なし）であった。これは`vendor/`を含むLaravel全体ではなく、上記の現行アプリ所有コードで非該当とする根拠である。

| 公式項目 | 判定 | 調査対象・検索語 | 根拠・結果 | 対応 |
|---|---|---|---|---|
| Updating Dependencies | 該当・対応済み | `composer.json`、`composer.lock`; `laravel/framework`、`laravel/tinker`、`phpunit/phpunit`、`laravel/boost`、`pestphp/pest` | root constraintとlock versionを確認。BoostとPestはroot依存にない | Framework 13、Tinker 3、PHPUnit 12へ更新し、未使用packageは追加しない |
| Updating the Laravel Installer | 非該当 | `composer.json`、`composer.lock`; `laravel/installer` | 一致なし、終了コード`1`。global toolはアプリのrepository依存ではない | 変更しない |
| Cache Prefixes and Session Cookie Names | 該当・方針決定済み | `config/cache.php`、`config/database.php`、`config/session.php`; `CACHE_PREFIX`、`REDIS_PREFIX`、`SESSION_COOKIE` | 3設定の既存fallbackを確認 | 識別子を維持する |
| Cache `Store` / `Repository` contracts: `touch` | 非該当 | 基本検索範囲; `implements Store`、`implements Repository`、`function touch` | custom実装の一致なし、終了コード`1` | 変更しない |
| Cache `serializable_classes` | 該当・対応済み | `config/cache.php`; `serializable_classes`、`serialize`、`unserialize` | top-levelのboolean `false`を確認。アプリ所有コードのserialization処理は一致なし、終了コード`1` | `false`を採用し、任意classの復元を許可しない |
| `Container::call` nullable defaults | 非該当 | 基本検索範囲; `Container::call`、`app(...)->call`、`resolve(...)->call` | 一致なし、終了コード`1` | 変更しない |
| `Dispatcher::dispatchAfterResponse` | 非該当 | 基本検索範囲; `dispatchAfterResponse`、`implements Dispatcher` | 一致なし、終了コード`1` | 変更しない |
| `ResponseFactory::eventStream` | 非該当 | 基本検索範囲; `eventStream`、custom `ResponseFactory` | 一致なし、終了コード`1` | 変更しない |
| `MustVerifyEmail::markEmailAsUnverified` | 非該当 | 基本検索範囲; `markEmailAsUnverified`、`implements MustVerifyEmail` | 一致なし、終了コード`1` | 変更しない |
| Database `upsert` / empty `uniqueBy` | 非該当 | 基本検索範囲のPHPファイル; `upsert()`、`uniqueBy` | 無出力、終了コード`1`。現行アプリ所有コードに使用箇所なし | 空の`uniqueBy`を渡すコードがないため変更しない |
| MySQL joined `DELETE` with `ORDER BY` / `LIMIT` | 非該当 | 基本検索範囲; `join`、`leftJoin`、`rightJoin`、`crossJoin`、`joinSub` | JOIN queryの一致なし、終了コード`1` | 変更しない |
| Model booting and nested instantiation | 非該当 | `app/Models/`、`tests/`; `function boot*`、`static::boot`、`parent::boot` | model boot実装の一致なし、終了コード`1` | 変更しない |
| Polymorphic pivot table name generation | 非該当 | 基本検索範囲; `MorphPivot`、`morphToMany`、`morphedByMany`、`morphPivot` | 一致なし、終了コード`1` | 変更しない |
| Collection model serialization | 非該当 | 基本検索範囲; `serialize`、`unserialize`、`SerializesModels`、`ShouldQueue` | 一致なし、終了コード`1` | 変更しない |
| HTTP client `Response::throw` / `throwIf` signatures | 非該当 | 基本検索範囲; custom `throw`、`throwIf`、`extends Response` | overrideの一致なし、終了コード`1` | 変更しない |
| Default Password Reset Subject | 該当・変更不要 | BreezeJP 2.6.3 `BreezejpServiceProvider`、framework `ResetPassword`、`lang/ja.json`; `toMailUsing`、subject key | BreezeJP callbackが`Reset Password Notification`を使用し、既存日本語keyも存在。Mailpitの日本語subjectは手動回帰で確認 | 新keyを追加せず、既存keyを維持する |
| Queued Notifications and Missing Models | 非該当 | 基本検索範囲; `extends Notification`、`ShouldQueue`、`SerializesModels` | custom queued notificationの一致なし、終了コード`1` | 変更しない |
| `JobAttempted::$exception` | 非該当 | 基本検索範囲; `JobAttempted`、`exceptionOccurred` | 一致なし、終了コード`1` | 変更しない |
| `QueueBusy::$connectionName` | 非該当 | 基本検索範囲; `QueueBusy`、`connectionName` | 一致なし、終了コード`1` | 変更しない |
| Queue contract method additions | 非該当 | 基本検索範囲; custom `Queue`、`Connector`、`Job` contract実装 | 一致なし、終了コード`1` | 変更しない |
| Domain route registration precedence | 非該当 | 基本検索範囲; `Route::domain`、`->domain()` | 一致なし、終了コード`1` | 変更しない |
| Session `serialization` | 該当・対応済み | `config/session.php`とsession利用箇所; `serialization`、sessionへの保存値 | top-levelの`json`を確認。既存session失効、認証、validation、old input、名前付きerror bagは手動回帰で確認 | JSONを採用し、既存session失効と再ログインを受け入れる |
| `withScheduling` registration timing | 非該当 | 基本検索範囲; `withScheduling` | 一致なし、終了コード`1`。従来のConsole Kernel構造 | 変更しない |
| Request Forgery Protection | 該当・対応済み | 基本検索範囲; `VerifyCsrfToken`、`ValidateCsrfToken`、`PreventRequestForgery` | Laravel側旧class直接参照は一致なし、終了コード`1`。新classはapp middlewareとSanctum設定の3箇所で確認。HTTP結果は手動回帰で確認 | app class名とKernel登録を維持し、framework直接参照を新classへ変更する |
| Manager `extend` callback binding | 非該当 | 基本検索範囲; `->extend()`、`::extend()` | custom driver callbackの一致なし、終了コード`1` | 変更しない |
| `Str` factories reset between tests | 非該当 | 基本検索範囲; `createUuidsUsing`、`createUlidsUsing`、`createRandomStringsUsing`、`freezeUuids`、`freezeUlids` | 一致なし、終了コード`1` | 変更しない |
| `Js::from` unescaped Unicode | 該当・変更不要 | `resources/views/`; `Js::from`、`Illuminate\Support\Js`、`@js`、`@json` | Bladeの`@js`を`resources/views/components/modal.blade.php`で6箇所、`resources/views/profile/partials/delete-user-form.blade.php`で1箇所、合計7箇所確認した。Laravel 13の`CompilesJs`では`@js`が`Js::from(...)->toHtml()`へコンパイルされる。現行の呼び出しで渡す値はboolean、ASCII識別子または`null`であり、エスケープ済みUnicodeに依存する出力比較はない。Laravel 13の`Js::REQUIRED_FLAGS`でも`JSON_HEX_*`は維持される | `JSON_UNESCAPED_UNICODE`追加による現行動作への影響はないため、コードは変更しない |
| PHP 8.5 polyfill / global helper conflicts | 非該当 | 基本検索範囲のPHPファイル; `array_first`、`array_last` | 独自helperの一致なし、終了コード`1`。実行PHPは8.4.24 | PHP 8.5更新を混在させず変更しない |
| Pagination Bootstrap view names | 非該当 | `app/`、`resources/views/`; `useBootstrapThree`、`pagination::default`、`pagination::simple-default`、`bootstrap-3`、`pagination::bootstrap-3`、`Paginator::`、`->links()` | Laravel 13で名称変更された旧view名とBootstrap 3設定への直接参照はない。paginatorの表示呼び出しは2箇所で、`resources/views/items/index.blade.php`は独自viewの`vendor.pagination.movie`を明示し、`resources/views/reviews/mine.blade.php`は既定の`->links()`を使用している。名称変更された旧view名には依存していない | 変更しない |

公式ガイド末尾の案内に従ってLaravel 13の`laravel/laravel` skeleton差分も確認し、`cache.serializable_classes`、`session.serialization`、両設定の説明コメントだけを選択的に採用した。Laravel 10形式のapplication structureは一括同期していない。

### 8.5 レビューで得た教訓

アプリ側CSRF middlewareの継承元変更後、`config/sanctum.php`にdeprecated aliasの直接参照が残っていることをレビューで検出した。今後はmiddleware classの改名時に、`app/`、`bootstrap/`、`config/`、`routes/`、`tests/`を横断して旧class名と新class名を検索する。この手順を`LARAVEL_UPGRADE_GUIDE.md`へ追加した。

`Js::from`はPHPコードから直接呼ばれていなかったが、Bladeの`@js`ディレクティブを通じて利用していた。framework APIの変更を調査するときは、class・method名だけでなく、Bladeディレクティブ、helper、Facadeなどの間接的な呼び出し形も確認する。GuideのUpgrade Guide確認手順へ、PHP呼び出し形とテンプレート構文の両方を検索するルールを追加した。

### 8.6 最終ローカル品質ゲート

| 確認 | 作業ブランチ上の結果 |
|---|---|
| `composer validate` | PASS |
| `composer audit --locked` | advisoryなし |
| `composer check-platform-reqs --lock` | 全項目`success` |
| PHPUnit | 12.5.33 / 128 tests / 1375 assertions / PASS |
| PHPUnit / Laravel deprecation | 表示なし |
| `SanctumApiUserTest` | 2 tests / 3 assertions / PASS |
| `ProfileTest` | 44 tests / 611 assertions / PASS |
| PHPStan / Larastan | 66/66 / No errors |
| Pint | 109 files / PASS |
| Vite build | Vite 6.4.3 / 56 modules transformed / PASS |
| Routes | 30 routes / route name重複0 |
| `git diff --check` | 問題なし |

CSRFは、不正tokenでHTTP 419、`Sec-Fetch-Site: same-origin`でHTTP 302、`Sec-Fetch-Site: cross-site`でHTTP 419となることを再確認した。

### 8.7 手動回帰

シークレットウィンドウで次を確認した。

- ログイン、失敗時validation、ログアウト、intended redirect
- パスワードリセットメール受信、パスワード再設定、再ログイン
- profileのvalidation、old input、名前付きerror bag
- アバター登録、差し替え、再読み込み後の保持
- レビュー投稿、返信投稿、入力エラー
- 平均評価3.0 / 2件から5星追加後3.7 / 3件への小数点丸め
- 作品一覧および本人レビュー一覧のpagination
- desktop幅・mobile幅の主要画面
- 退会時の誤パスワード拒否
- 正しいパスワードでの退会、ログアウト、profileアクセス拒否、旧認証情報でのログイン不可
- 退会後もレビューと返信を匿名表示で保持し、平均評価3.7 / 3件を維持

### 8.8 npmの引継ぎ

`npm ci`は成功した。一方、1件のhigh severity vulnerabilityと、`esbuild@0.25.12`のinstall scriptがブロックされた警告が残っている。

npm更新、`npm audit fix`、install script承認はIssue #134へ混在させない。Laravel 13更新完了および`main`反映後に、Dependabot alertsを別Issueで対応する。Issue番号は未確定のため記録しない。

### 8.9 未確定事項

GitHub ActionsはPR作成後に確認する。PR番号、CI結果、マージ結果、`develop`・`main`・本番環境への反映は、確定後に別途記録する。

---

## 9. Laravel 13のdevelop baseline確定

### 9.1 Issue #136の目的

Issue #136では、第8章に記録したIssue #134作業ブランチ上の候補結果を歴史的記録として維持したまま、PR #135のGitHub Actionsと、マージ後の`develop`上で再確認したLaravel 13 baselineを分離して確定記録する。

本Issueでは依存関係、PHP・JavaScriptコード、設定、テスト、DBを変更しない。

### 9.2 PR #135とGitHub Actions

| 項目 | 確定値 |
|---|---|
| PR | #135 |
| タイトル | `chore: Laravel 12から13へ段階的にアップグレードする` |
| Base | `develop` |
| Head | `chore/134-upgrade-laravel-13` |
| Merge commit | `40594726caf9cb7dd5cf98539c6da36875b6c33f` |
| mergedAt | 2026-08-19 16:42:24 UTC / 2026-08-20 01:42:24 JST |
| 実装commit | `d83b89b` |
| 文書commit | `9c0c1ea` |

PR #135では、次の2 checksが成功した。

| Check | 結果 | 所要時間 |
|---|---:|---:|
| `CI/python-quality-checks (pull_request)` | PASS | 8秒 |
| `CI/quality-checks (pull_request)` | PASS | 1分4秒 |

集計はsuccessful 2件で、cancelled、failing、skipped、pendingはいずれも0件であった。

マージ後、作業ブランチ`chore/134-upgrade-laravel-13`はローカル・リモートとも削除済みである。`git fetch --prune`後、同名のremote-tracking branchが残っていないことも確認した。

### 9.3 マージ後のdevelop確定baseline

PR #135のMerge commitを反映した`develop`上で、本Issueの文書編集を開始する前に次を確認した。

| 項目 | 確定値 |
|---|---:|
| PHP | 8.4.24 |
| Laravel Framework | 13.26.1 |
| Composer | 2.10.2 |
| Composer実行PHP | 8.4.24（ローカルSailコンテナ内） |
| PHPUnit | 12.5.33 |
| Database | MySQL |
| `develop` / `origin/develop` | `40594726caf9cb7dd5cf98539c6da36875b6c33f`で一致 |
| Git状態 | `git status --short`は無出力 |

ローカルSailコンテナ内のPHP 8.4.24と、XServer上の`/usr/bin/php8.4` PHP 8.4.20は別環境である。本IssueではXServerの設定変更、実platform requirements確認、デプロイを行っていない。

### 9.4 直接依存のlock version

| Package | Version |
|---|---:|
| `askdkc/breezejp` | 2.6.3 |
| `barryvdh/laravel-ide-helper` | 3.7.0 |
| `fakerphp/faker` | 1.24.1 |
| `guzzlehttp/guzzle` | 7.15.3 |
| `larastan/larastan` | 3.10.0 |
| `laravel/breeze` | 2.4.2 |
| `laravel/framework` | 13.26.1 |
| `laravel/pint` | 1.30.4 |
| `laravel/sail` | 1.58.0 |
| `laravel/sanctum` | 4.3.3 |
| `laravel/tinker` | 3.0.2 |
| `mockery/mockery` | 1.6.12 |
| `nunomaduro/collision` | 8.9.5 |
| `phpunit/phpunit` | 12.5.33 |
| `spatie/laravel-ignition` | 2.12.0 |

### 9.5 マージ後のdevelop品質確認

| 品質ゲート | 結果 |
|---|---|
| `composer check-platform-reqs --lock` | 全項目`success` |
| `composer validate --strict` | エラーなし |
| `composer audit --locked` | `No security vulnerability advisories found.` |
| PHPUnit | 12.5.33 / 128 tests / 1375 assertions / PASS |
| PHPUnit / framework deprecation | 表示なし |
| PHPStan / Larastan | 66/66 / No errors |
| Pint | 109 files / PASS |
| Vite | 6.4.3 / 56 modules transformed / 1.00s / PASS |
| Routes | 30件 / route name重複0 |
| `git diff --check` | 問題なし |
| `git status --short` | 無出力 |

### 9.6 確認時点の区別

- CSRF、session、認証、プロフィール、レビュー、退会、デスクトップ幅・モバイル幅の画面表示などの手動回帰は、Issue #134作業ブランチ上で確認した結果である
- GitHub Actions 2 checksは、PR #135のpull request eventで確認した結果である
- 9.3〜9.5のbaseline、lock version、品質ゲートは、PR #135マージ後の`develop`上で再確認した結果である
- Laravel 13.26.1は`develop`へ反映済みだが、`main`およびXServer本番環境へは未反映である
- `session.serialization=json`は現在の`develop` baselineであり、Laravel 12時点の既存session失効と再ログインは、将来の本番反映時に発生する既知影響である
- session失効は認証状態への影響であり、DBに保存された永続データを削除するものではない
