# Laravelメジャーアップグレード実施履歴

## 目次

- [1. このドキュメントの役割](#1-このドキュメントの役割)
- [2. Laravel 10.50.2 → 11.55.1](#2-laravel-10502--11551)
- [3. Laravel 11 → 12への引継ぎ](#3-laravel-11--12への引継ぎ)
- [4. Laravel 10 → 11 完了確認](#4-laravel-10--11-完了確認)
- [5. Laravel 11.55.1 → 12.66.0](#5-laravel-11551--12660)

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
