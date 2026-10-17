# 依存関係更新履歴

## 1. 目的・対象期間・根拠資料

2026-10-16〜18（JST）のIssue #192〜#207に含まれる8件の依存更新を、[Issue #209](https://github.com/honda-dev-jp/review-app-laravel/issues/209)で整理した実施履歴である。記録時点は2026-10-18、作業開始時のHEADは`2bd7270`（PR #208のMerge commit）。

当時の事実の正本は各Issue・PRの本文・実施記録であり、今回、過去の環境再構築、audit、build、テストの再実行は行っていない。現在の解決バージョンは、このHEADの`composer.lock`・`package-lock.json`と読み取り専用で照合した。公式資料は仕様確認に用い、過去の実測値の置き換えには用いていない。

[Laravelメジャーアップグレード実施履歴](LARAVEL_UPGRADE_HISTORY.md)はLaravel 13移行時までの当時の記録として維持する。本書はその後の個別依存更新とTailwind移行を扱う。汎用手順は[セキュリティ方針](SECURITY.md)・[コマンド集](COMMANDS.md)、GitHub運用は[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)を参照する。MVP2のスコープ・進捗の正本はGitHub Milestoneと所属Issue・PRであり、本書は進捗表を複製しない。

## 2. 更新前ベースライン

次節の更新前バージョンは各Issue着手時の記録であり、8件がすべて同時に存在した単一環境を示すものではない。

- Laravel 13移行時の確定baselineは13.26.1。その後Issue #195で13.30.0へ更新した。
- Issue #205着手時は`postcss@8.5.26 → source-map-js@1.2.1`、`npm audit`の報告数は8件。
- Tailwind移行前は`develop b805426`（PR #206統合後）。Tailwind CSS 3.4.19、Vite 6.4.3、Laravel Vite Plugin 1.3.0、Laravel 13.30.0を使用していた。
- 当時のビルド方式はTailwind v3 / PostCSSで、`tailwind.config.js`・`postcss.config.js`が存在した。CSS・画面・直近E2Eの記録は第6節に分離する。

## 3. 依存関係の更新一覧

更新後の8値は記録時点のlockfileとすべて一致する（Composerの`v`接頭辞は表では省略）。

| Issue | パッケージ | 更新前 | 更新後 | 対応PR | 依存区分 |
| --- | --- | --- | --- | --- | --- |
| [#192](https://github.com/honda-dev-jp/review-app-laravel/issues/192) | `axios` | 1.18.0 | 1.20.0 | [#196](https://github.com/honda-dev-jp/review-app-laravel/pull/196) | direct / development |
| [#193](https://github.com/honda-dev-jp/review-app-laravel/issues/193) | `league/commonmark` | 2.10.0 | 2.10.2 | [#197](https://github.com/honda-dev-jp/review-app-laravel/pull/197) | transitive / production |
| [#194](https://github.com/honda-dev-jp/review-app-laravel/issues/194) | `league/flysystem` | 3.33.0 | 3.35.3 | [#198](https://github.com/honda-dev-jp/review-app-laravel/pull/198) | transitive / production |
| [#195](https://github.com/honda-dev-jp/review-app-laravel/issues/195) | `laravel/framework` | 13.26.1 | 13.30.0 | [#199](https://github.com/honda-dev-jp/review-app-laravel/pull/199) | direct / production |
| [#200](https://github.com/honda-dev-jp/review-app-laravel/issues/200) | `symfony/http-foundation` | 7.4.16 | 7.4.21 | [#202](https://github.com/honda-dev-jp/review-app-laravel/pull/202) | transitive / production |
| [#201](https://github.com/honda-dev-jp/review-app-laravel/issues/201) | `symfony/yaml` | 7.4.12 | 7.4.18 | [#203](https://github.com/honda-dev-jp/review-app-laravel/pull/203) | transitive / development |
| [#205](https://github.com/honda-dev-jp/review-app-laravel/issues/205) | `source-map-js` | 1.2.1 | 1.2.2 | [#206](https://github.com/honda-dev-jp/review-app-laravel/pull/206) | transitive / development |
| [#207](https://github.com/honda-dev-jp/review-app-laravel/issues/207) | `tailwindcss` | 3.4.19 | 4.3.3 | [#208](https://github.com/honda-dev-jp/review-app-laravel/pull/208) | direct / development |

依存区分はroot manifestとComposer lockの`packages` / `packages-dev`、npm lockの`dev`および依存経路から確認した。移行前`b805426`と移行後`develop 2bd7270`でも区分を照合した。productionは本番依存、developmentは開発用依存の区分を示す。`package.json`のdevDependenciesにあるaxiosもブラウザーへ配信されるコードに含まれ得るため、developmentという分類はセキュリティ対策が不要という意味ではない。

## 4. 各Issueの対応内容とAdvisory

### Issue #192〜#195：Dependabot対象11件

当初の対象はaxios 7件、commonmark 2件、flysystem 1件、Laravel 1件の合計11 Alertである。これは対象4 Issueの件数であり、プロジェクト全体の脆弱性総数ではない。以下はIssueに記録された影響範囲・修正版を保持する。Advisoryリンクは公式一次情報である。

| Issue / Alert | Advisory / CVE | 当時の影響範囲 | 修正版 |
| --- | --- | --- | --- |
| #192 / #61 | [GHSA-j8rh-479h-cp32](https://github.com/advisories/GHSA-j8rh-479h-cp32) / CVE-2026-101904 | `>=1.0.0, <1.20.0` | 1.20.0 |
| #192 / #60 | [GHSA-4hqw-qxg8-jxx2](https://github.com/advisories/GHSA-4hqw-qxg8-jxx2) / CVE-2026-101900 | `>=1.12.0, <1.20.0` | 1.20.0 |
| #192 / #59 | [GHSA-3pq3-5fj3-cg6v](https://github.com/advisories/GHSA-3pq3-5fj3-cg6v) / CVE-2026-101898 | `>=1.13.0, <1.20.0` | 1.20.0 |
| #192 / #57 | [GHSA-44g4-m2mj-wpvx](https://github.com/advisories/GHSA-44g4-m2mj-wpvx) / CVE-2026-101899 | `>=1.15.0, <1.20.0` | 1.20.0 |
| #192 / #55 | [GHSA-m8m8-qj5v-23w3](https://github.com/advisories/GHSA-m8m8-qj5v-23w3) / CVE-2026-101905 | `>=1.15.2, <1.20.0` | 1.20.0 |
| #192 / #54 | [GHSA-x97p-jq2g-jp4f](https://github.com/advisories/GHSA-x97p-jq2g-jp4f) / CVE-2026-101909 | `>=1.15.1, <1.20.0` | 1.20.0 |
| #192 / #52 | [GHSA-r4gj-5m52-g5wh](https://github.com/advisories/GHSA-r4gj-5m52-g5wh) / CVE-2026-101907 | `>=1.17.0, <1.20.0` | 1.20.0 |
| #193 / #51 | [GHSA-97jj-33gv-5xf9](https://github.com/advisories/GHSA-97jj-33gv-5xf9) | `>=1.3.0, <=2.10.1` | 2.10.2 |
| #193 / #50 | [GHSA-3q6v-r5mr-hxv8](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8) | `>=2.0.0, <=2.10.1` | 2.10.2 |
| #194 / #49 | [GHSA-cxf4-7mrp-vvpr](https://github.com/advisories/GHSA-cxf4-7mrp-vvpr) / CVE-2026-102601 | `<=3.35.2` | 3.35.3 |
| #195 / #48 | [GHSA-jh5r-qr3c-85q8](https://github.com/advisories/GHSA-jh5r-qr3c-85q8) / CVE-2026-102279 | `>=13.0.0, <13.30.0` | 13.30.0 |

- **#192 / PR #196**：開発用直接依存。`package.json`のaxios制約を`^1.18.0 → ^1.20.0`へ変更し、lockfileを更新。axiosの子依存制約`form-data ^4.0.5 → ^4.0.6`も必要差分として記録され、実体は4.0.6。`npm ci`成功、対象7件はnpm監査で非検出。別package由来の既存報告は対象外だった。
- **#193 / PR #197**：`laravel/framework → league/commonmark ^2.8.1`経由。dry-runは対象1 packageのみ、`composer.lock`のみ更新し、対象2 advisoriesがComposer監査で非検出。その他の既存Advisoryは対象外だった。
- **#194 / PR #198**：`laravel/framework 13.26.1 → league/flysystem ^3.25.1`および`league/flysystem-local 3.31.0 → league/flysystem ^3.0.0`の依存経路を確認。dry-run・実更新とも対象1 packageのみ、`composer.lock`のみ更新。Composer監査は4 → 3 advisoriesで、Laravel / Symfony由来は残存した。
- **#195 / PR #199**：rootの直接production依存。`laravel/framework ^13.0`を維持し、dry-run・実更新とも対象1 packageのみ、`composer.lock`のみ更新。Composer監査は3 → 2 advisoriesでSymfony由来が残存。lock内の`rector/rector ^2.3 → 2.6.3`はFramework自身の開発用metadataであり、このアプリのRector更新ではない。

Issue #193〜#195にあるDependabotの`relationship: unknown`は当時の取得値として保持し、Composerで確認した上記の依存関係と区別する。これらのIssueにはdefault branch反映後のClose確認が未チェックで残る一方、PR #204には再解析後の最終確認記録がある（第8節）。チェック欄だけから最終状態を推定しない。

### Issue #200・#201：Composer監査による対応

- **#200 / PR #202**：`symfony/http-foundation`の[CVE-2026-108082](https://symfony.com/cve-2026-108082)。当時の7.4系影響範囲は`>=7.4.0, <7.4.19`。最初の修正版7.4.19ではwhitespace separatorの回帰があり、公式の追補修正を踏まえて7.4.21を採用した。依存元はLaravel Framework 13.30.0、fruitcake/php-cors、spatie/flare-client-php、spatie/ignition、symfony/http-kernel。詳細な制約はIssue / PRを参照する。dry-run・実更新は0 installs / 1 update / 0 removals、`composer.lock`のみ変更。監査は2 → 1 advisoriesとなり、残るYamlは別Issue #201の対象だった。
- **#201 / PR #203**：`symfony/yaml`の[CVE-2026-109413](https://symfony.com/cve-2026-109413)。当時の7.4系影響範囲は`>=7.4.0, <7.4.18`、修正版7.4.18を採用。依存元は`laravel/sail 1.58.0 → symfony/yaml ^6.0|^7.0|^8.0`。symfony/routing・symfony/translationのconflict条件も確認した記録がある。dry-run・実更新は0 installs / 1 update / 0 removals、`composer.lock`のみ変更。監査は1 → 0 advisories。

いずれもrootへの直接依存追加や無関係なpackage更新は行っていない。上記は当該Issue・PRの実績であり、今回の監査結果ではない。

### Issue #205：source-map-js

Alert #64、[GHSA-68fv-2mgg-jv7q](https://github.com/advisories/GHSA-68fv-2mgg-jv7q) / CVE-2026-93749が対象。影響範囲は`>=1.0.0, <1.2.2`、修正版は1.2.2。開発用の間接依存`postcss@8.5.26 → source-map-js`を、既存制約`^1.2.1`内で更新した。

PR #206ではdry-runで対象1 packageのみと確認し、`package-lock.json`のversion・resolved・integrityのみ変更（+3 / -3）。`package.json`への直接依存追加はない。`npm ci`成功後に1.2.2を確認し、対象Advisoryはnpm監査で非検出となった。全体の報告は8 → 7件（High 5 / Moderate 2）。別経路のbraces・postcss-selector-parserとそれらの依存元に関する報告は当時の対象外で、脆弱性0件にはなっていない。

### Issue #207：Tailwind CSS v4

Issue #207の記録と公式Advisory、移行前`b805426`・移行後`develop 2bd7270`の`package-lock.json`を照合した。

| 対象 | Advisory / CVE | 影響範囲 | Advisory上の修正版 | 移行前の依存経路 |
| --- | --- | --- | --- | --- |
| `postcss-selector-parser` / Alert #63 | [GHSA-rj75-hqrm-r3gf](https://github.com/advisories/GHSA-rj75-hqrm-r3gf) / CVE-2026-104844 | `<7.1.6` | 7.1.6 | `tailwindcss 3.4.19 → postcss-selector-parser 6.1.4` |
| `braces` | [GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) / CVE-2026-93687 | `<=3.0.3` | なし（2026-10-18確認） | `tailwindcss 3.4.19 → micromatch 4.0.8 → braces 3.0.3`、`tailwindcss 3.4.19 → chokidar 3.6.0 → braces 3.0.3` |

移行後のdevelopのlockfileでは両パッケージのエントリがなく、上記の脆弱な依存経路が除去されたことを確認した。bracesを修正版へ更新したという意味ではない。v4移行後のローカルnpm監査0件は当時の実績であり、今回のlockfile照合とは別の確認である。GitHub Alertの最終状態やmain上の残存有無は第9節の後続確認である。

## 5. Composer監査・npm監査の結果

| 当時の記録 | 監査 | 結果・残存課題 |
| --- | --- | --- |
| PR #196 | npm | axios対象7件は非検出。別packageの報告は対象外 |
| PR #197 | Composer | commonmark対象2件は非検出。その他は対象外 |
| PR #198 | Composer | 4 → 3 advisories、Laravel / Symfonyが残存 |
| PR #199 | Composer | 3 → 2 advisories、HttpFoundation / Yamlが残存 |
| PR #202 | Composer | 2 → 1 advisory、Yamlが残存 |
| PR #203・同期PR #204 | Composer | `No security vulnerability advisories found.` |
| PR #206 | npm | 8 → 7件、High 5 / Moderate 2が残存 |
| Issue #207・PR #208 | npm | 移行後ローカルで0 vulnerabilities |

監査対象・時点・集計単位が異なるため、Composer advisory数、npm報告数、Dependabot Alert数を合算しない。PR #204のComposer検出なしはnpm検出なしを意味しない。PR #208のローカルnpm検出0件も、mainへの反映やGitHub AlertのClose確認を意味しない。

## 6. Tailwind CSS v3→v4移行前後の検証結果

### 移行前：Issue #207に残る実測記録

CSS記録日時は**2026-10-17 23:12（Issue記載値）**。CSSは`/tmp/tailwind-v3-baseline-207-3HtEHz/assets/app-Co6QvB3P.css`で、manifestのCSS参照との一致を当時確認済み。manifestのJS参照は`app-BJA0v0Q5.js`である。

| 項目 | 移行前の実績 |
| --- | --- |
| Vite build | 成功、59 modules |
| CSS出力 | 約43.49KB |
| CSS gzip | 約7.82KB |
| ビルド時間 | 約4.18秒 |
| 代表画面 | Tailwind v3の10枚を撮影した記録あり |
| 直近の既存E2E | PR #206で12/12 PASS |
| 直近の既存CI | [Run 39032123582](https://github.com/honda-dev-jp/review-app-laravel/actions/runs/39032123582)：success、Playwright E2Eもsuccess |

`/tmp`のCSS・manifestが現在も存在することは確認していない。JS実体の保存および10枚の画像の永続保存先も未確認。再生成で過去の記録を置き換えていない。E2Eは移行直前のPR #206の既存CI実績を採用したもので、Issue #207開始直前に新規実行した結果ではない。

### 移行後：Issue #207・PR #208の実績

- Tailwind CSS 4.3.3と`@tailwindcss/vite`を導入し、旧Tailwind / PostCSS設定を整理した。
- CSS-first設定、ソース検出、Figtree、forms、Blade、Laravel標準・独自ページネーション、モーダルの重なり順をv4へ対応した。
- 既存のAPP_URL検証、HMR、別PCアクセス、E2E専用hot file設定を維持した。
- LIFEBOOK P6200でOxide・Lightning CSSのロード、build、dev再生成を確認した。
- PC・モバイル表示、代表画面の目視・スクリーンショット比較、モーダルのフォーカス制御、HMRを確認した。ダークモードは未実装で、200%拡大・forced-colorsの新規適合性検証は当時の対象外だった。

| 品質確認 | 移行後の記録 |
| --- | --- |
| Vite build | PASS |
| Playwright TypeScript型検査 | PASS |
| Playwright E2E | 12/12 PASS |
| PHPUnit | 162 tests / 1,567 assertions PASS |
| Pint | PASS |
| PHPStan / Larastan | PASS |
| `composer validate` | PASS |
| ローカル`npm audit` | 0 vulnerabilities |

移行後CSSのサイズ・gzip・所要時間の比較可能な数値は、本書で参照したIssue・PR本文にはないため、削減率や高速化率は算出しない。移行後もVite 6.4.3、Laravel Vite Plugin 1.3.0、Laravel 13.30.0であることは記録時点のlockfileで確認した。

## 7. GitHub Actions CIの実績

- PR #196・#197・#198・#199・#202・#203：各通常PRのCI成功は同期PR #204に記録済み。各PRのローカル品質確認も成功し、PHPUnit 162 tests / 1,567 assertions、Playwright E2E 12/12 PASS。Pint、PHPStan / Larastan、Vite build、Playwright型検査も成功している。Composer更新PRでは`composer validate`も成功した。
- 同期PR #204：[CI Run 38933028632](https://github.com/honda-dev-jp/review-app-laravel/actions/runs/38933028632)（PR head `3ca040d`）の3ジョブ成功を読み取り専用で確認した。マージ前チェックにもCI全成功を記録済み。本文の「作成後に確認する」という当初の予定と区別する。
- PR #206：CI全成功をマージ前チェックに記録済み。ローカルは`npm ci`、Vite build、Playwright型検査・E2E 12/12、Pint、PHPStan / Larastan、PHPUnit 162 tests / 1,567 assertions、`composer validate`がすべてPASS。
- PR #208：`quality-checks`、`Playwright E2E`、`python-quality-checks`がPASS。最終実装commit `bd914ec`の[CI Run 39067941869](https://github.com/honda-dev-jp/review-app-laravel/actions/runs/39067941869)と3ジョブは、今回の読み取り専用参照でもsuccessを確認した。

Tailwind v4移行に伴うPython CIの依存メタデータ対応・テスト修正はcommit `bd914ec`に含まれる。Python unittestの件数はIssue #207・PR #208本文およびコメントで一次実行記録を確認できなかったため記載しない。`python-quality-checks`のCI成功は上記の実績として保持する。Issue #209の文書変更に対するCIはPR作成後の後続確認となる。

## 8. develop・mainへの反映履歴

記録時点（2026-10-18）の状態であり、本番デプロイの実績を示さない。

| 反映単位 | 確定済みの履歴 |
| --- | --- |
| 先行6件：#192・#193・#194・#195・#200・#201 | 通常PRからdevelopへ統合後、[同期PR #204](https://github.com/honda-dev-jp/review-app-laravel/pull/204)でmainへ反映済み。2026-10-16 16:37:08 UTC、Merge commit `7ada7a1` |
| #205 / PR #206 | 2026-10-17 12:27:50 UTCにdevelopへ統合、Merge commit `b805426`。main反映待ち |
| #207 / PR #208 | 2026-10-17 20:47:08 UTCにdevelopへ統合、Merge commit `2bd7270`。main反映待ち |
| #209 | 文書編集段階。作業ブランチからdevelopへの通常PR統合後、#205・#207とまとめてmainへ同期する予定 |

PR #204はセキュリティ対応6件を先行反映する管理者判断を明記している。同PRのマージ後チェックには対象6 IssueのClose、developのfast-forward同期、Dependabot再解析後の状態確認が記録済み。Issue #192にもaxios対象7 AlertのClose確認がある。一方、Issue #193〜#195の古いチェック欄は未チェックのままである。PR #204の最終確認実績を保存しつつ、本書ではAlert #48〜#51の個別最終状態を新たに取得したとは主張しない。

ローカルGit履歴でもmainは`7ada7a1`、developと作業開始HEADは`2bd7270`で、上記のMerge commitを確認した。今回Git参照の更新やブランチ操作は行っていない。

## 9. main反映後に残る確認事項

以下は未完了の後続工程であり、手順の記録は確認完了を意味しない。

1. 人間による差分レビュー、Issue #209のdevelop向け通常PR・CI成功・Merge commit統合を経て、#205・#207・#209をdevelop→main同期PRで反映する。
2. mainのlockfileに対象修正が入ったことと、GitHubのdefault branch再解析を確認する。
3. Dependabot Alert **#63（postcss-selector-parser）・#64（source-map-js）**のopen/closed状態、対象Advisory、検出対象の依存経路を確認する。ローカルauditだけでClose済みと判断しない。
4. **braces / GHSA-vfj7-8cjw-p6xm**の残存有無を確認する。必要なら反映済みmainのlockfileを基準に、安全な検証環境で`npm ci`、`npm ls postcss-selector-parser braces source-map-js --all`、`npm audit`を行い、packageの存在と脆弱な経路の残存を区別する。これらは今回実行していない。
5. 確認日時、対象commit、監査結果、Alert状態と残存経路を関連Issueへ記録する。GitHubへの記録は人間が行う。

## 10. 関連Issue・PR・公式資料

対象8件のIssue・PRへのリンクは第3節、当時のAdvisoryは第4節、main同期は第8節を参照する。[Issue #209](https://github.com/honda-dev-jp/review-app-laravel/issues/209)が本書整備の受け入れ条件の正本である。

仕様確認に参照した公式資料（2026-10-18確認）：

- [GitHub Dependabot alerts](https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-alerts)：default branch解析とAlertの仕組み。
- [GitHub Advisory Database](https://github.com/advisories)：Advisory・影響範囲・修正版の一次情報。
- [Composer CLI / audit](https://getcomposer.org/doc/03-cli.md#audit)：Composer側の監査対象・オプション。現行資料の仕様から過去の監査数を推定しない。
- [npm audit](https://docs.npmjs.com/cli/commands/npm-audit/) / [npm CLI v11資料](https://docs.npmjs.com/cli/v11/commands/npm-audit/)：npm側の監査。過去の実行npm版は今回の参照記録では確定していないため、v11資料を当時の環境の証拠とはしない。
- [Tailwind CSS v4 Upgrade Guide](https://tailwindcss.com/docs/upgrade-guide)：Vite利用時に推奨される`@tailwindcss/vite`方式とv3→v4変更点。
- [Laravel 13 Documentation](https://laravel.com/docs/13.x)：対象Laravel majorの仕様。
- [Symfony HttpFoundation Advisory](https://symfony.com/cve-2026-108082) / [Symfony Yaml Advisory](https://symfony.com/cve-2026-109413)：修正版と追補修正の根拠。
