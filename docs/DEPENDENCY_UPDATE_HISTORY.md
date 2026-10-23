# 依存関係更新履歴

## 1. 目的・対象期間・根拠資料

2026-10-16〜18（JST）のIssue #192〜#207に含まれる8件の依存更新を、[Issue #209](https://github.com/honda-dev-jp/review-app-laravel/issues/209)で整理した実施履歴である。記録時点は2026-10-18、作業開始時のHEADは`2bd7270`（PR #208のMerge commit）。

当時の事実の正本は各Issue・PRの本文・実施記録であり、今回、過去の環境再構築、audit、build、テストの再実行は行っていない。現在の解決バージョンは、このHEADの`composer.lock`・`package-lock.json`と読み取り専用で照合した。公式資料は仕様確認に用い、過去の実測値の置き換えには用いていない。

[Laravelメジャーアップグレード実施履歴](LARAVEL_UPGRADE_HISTORY.md)はLaravel 13移行時までの当時の記録として維持する。本書はその後の個別依存更新とTailwind移行を扱う。汎用手順は[セキュリティ方針](SECURITY.md)・[コマンド集](COMMANDS.md)、GitHub運用は[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)を参照する。MVP2のスコープ・進捗の正本はGitHub Milestoneと所属Issue・PRであり、本書は進捗表を複製しない。

2026-10-22のIssue #219による追加対応は、第11節に追補として記録する。第1〜10節の数値・状況は、特記のない限り各節に示す当時の記録である。

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

## 11. 2026-10-22追補：Issue #219 follow-redirects脆弱性対応

この節は2026-10-22（JST）にローカルで実施した[Issue #219](https://github.com/honda-dev-jp/review-app-laravel/issues/219)の結果を記録する。第1〜10節の2026-10-16〜18の実績と分離し、PR未作成・CI未実施・main未反映の状態を完了扱いしない。

### 11.1 対象と一次情報

| 項目 | 内容 |
| --- | --- |
| 対象 | Dependabot Alert #65・#66（いずれもHigh） |
| パッケージ | `follow-redirects`（npm、transitive / development） |
| 依存経路 | rootの`axios@1.20.0`（devDependencies）→ `follow-redirects@^1.16.0` |
| 更新前・更新後 | `1.16.0` → `1.16.1` |
| 影響範囲・修正版 | 両Advisoryとも `>=1.16.0, <1.16.1` / `1.16.1` |
| 作業ブランチ | `security/219-fix-follow-redirects` |
| 作業worktree | `review-app-laravel-security-219`（元のTMDB worktreeとは別） |
| 作業開始HEAD | `6404d3a`（`origin/develop`から作成） |

- **Alert #65**：[GHSA-8r9p-f939-6h3c](https://github.com/advisories/GHSA-8r9p-f939-6h3c) / CVE-2026-111755。`sensitiveHeaders`の正規表現エスケープに起因する認証情報ヘッダー漏洩のおそれ。
- **Alert #66**：[GHSA-q3jh-wrc6-jgq8](https://github.com/advisories/GHSA-q3jh-wrc6-jgq8) / CVE-2026-111756。配列形式のヘッダーで、オリジンをまたぐリダイレクト時の認証情報除去を回避できる問題。

上記はGitHub Advisory Databaseの影響範囲・修正版の記載に基づく。アプリ内での悪用経路の成立は検証していない。

### 11.2 変更範囲と差分精査

- 既存制約`^1.16.0`の範囲内で、`npm update follow-redirects --package-lock-only --ignore-scripts`を実行した。
- npm 11.19.1での更新直後、lockfileにroot `name`とTailwind Oxide WASM関連の追加エントリが生じたが、今回の対象更新には含めず差分単位で取り消した。
- 確定した差分は`package-lock.json`のみ（**3行追加・3行削除**）。変更したのは`node_modules/follow-redirects`の`version`、`resolved`、`integrity`の3値のみ。
- `package.json`、`axios@1.20.0`の解決バージョン、rootの直接依存関係は変更していない。`git diff --check`はPASS。

### 11.3 ローカル検証結果

| 確認 | 実施結果 |
| --- | --- |
| `npm ci` | PASS（75 packages added、76 packages audited） |
| `npm ls follow-redirects` | `axios@1.20.0 → follow-redirects@1.16.1` |
| `npm run build` | PASS（Vite 6.4.3、59 modules transformed） |
| `npm run typecheck:e2e` | PASS |
| `git diff --check` | PASS |
| `npm audit` | `follow-redirects`対象Advisoryは報告なし。**Vite 6.4.3由来のHigh 1件が残存** |
| `npm audit --omit=dev` | 0 vulnerabilities。開発用依存を含む安全性の保証ではない |
| ローカルPlaywright E2E / PHPUnit | **未実施**。PR作成後のGitHub Actionsで結果を確認する方針 |

Viteについては`vite@6.4.5`の公開をnpmレジストリで確認したが、Issue #219には含めず、別Issue・別作業ブランチで扱う方針とした。`npm audit fix`や`npm audit fix --force`は実行していない。

### 11.4 worktree分離とCI確認方針

TMDB Issue #212の元worktreeには未コミットの5ファイルがあり、`git stash`・ブランチ切替・リセットを行わず、セキュリティ専用worktreeを別途作成した。確認時点でTMDBの未コミット5ファイルは維持され、Issue #219の差分は別worktreeの`package-lock.json`のみに限定された。

ローカルE2Eは`http://localhost:83`の`laravel.e2e` serviceを前提とし、`compose.yaml`で実行元のディレクトリをbind mountする。さらに`global.setup.ts`が`e2e:reset`を呼び、専用DB `e2e_testing`を初期化する。別worktree用の`.env`・`vendor/`およびDocker Compose・ポート・DBの分離を未整備のまま既存環境を流用すると、Issue #219のコードでの実行保証がなく、環境競合を招き得る。そのためこのworktreeからローカルE2Eを実行していない。

既存の[Playwrightブラウザテスト運用ガイド](PLAYWRIGHT_TESTING.md)に従い、PR作成後、独立したGitHub Actions runner上の`Playwright E2E`と`quality-checks`、`python-quality-checks`の結果を確認する。CI成功は現時点では未確認である。

### 11.5 未完了事項

1. 本追補とlockfileの差分をレビューし、必要なローカル確認を完了させる。
2. Issue #219の作業ブランチから`develop`へ通常PRを作成し、CI全ジョブの成功とレビューを確認する。
3. GitHub開発運用ガイドに従いMerge commit方式で`develop`へ統合し、適切な単位で`main`へ同期する。
4. `main`のlockfileに`follow-redirects@1.16.1`が反映された後、Dependabot Alert #65・#66の状態を再確認する。**未確認のAlert Closeや未実行のテストを完了済みと記録しない。**
5. Viteの残存HighについてはIssue #219の更新に混ぜず、別Issueとして影響範囲・修正版と検証方針を整理する。

参照資料：[Issue #219](https://github.com/honda-dev-jp/review-app-laravel/issues/219)、[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)、[Playwrightブラウザテスト運用ガイド](PLAYWRIGHT_TESTING.md)、[npm ci公式仕様](https://docs.npmjs.com/cli/v11/commands/npm-ci/)。

## 12. 2026-10-23追補：Issue #221 Vite 6.4.5への脆弱性対応

この節は2026-10-23（JST）に[Issue #221](https://github.com/honda-dev-jp/review-app-laravel/issues/221)の専用worktreeで実施した更新・ローカル検証の記録である。第1〜10節および第11節の当時の記録は書き換えない。通常PR、CI、`develop`への統合、`main`への同期、Dependabot再解析は本節の記録時点では未実施である。

### 12.1 対象・根拠となる一次情報

| 項目 | 内容 |
| --- | --- |
| 対象パッケージ | `vite`（rootの`devDependencies`にある直接依存） |
| 更新前・更新後 | `6.4.3` → `6.4.5` |
| rootのバージョン制約 | `^6.4.3`（変更なし。6.4.5は制約範囲内） |
| 更新前の監査結果 | Issue #219で、Vite 6.4.3由来のHigh 1件が残存 |
| 作業ブランチ | `security/221-fix-vite-high` |
| 作業worktree | `review-app-laravel-security-221`（TMDB Issue #212のworktreeとは分離） |
| 作業開始HEAD | `12e4455`（PR #220のMerge commit、`origin/develop`） |

Issue #221に記録したVite 6.4.3に関係するAdvisoryは以下の5件である。npm監査のHigh「1件」はViteパッケージ単位の報告であり、Advisoryが1種類のみという意味ではない。

- [GHSA-698v-4r3x-3vcv](https://github.com/advisories/GHSA-698v-4r3x-3vcv)
- [GHSA-r825-w8mm-5pxw](https://github.com/advisories/GHSA-r825-w8mm-5pxw)
- [GHSA-587h-7wmj-h5pm](https://github.com/advisories/GHSA-587h-7wmj-h5pm)
- [GHSA-9jrq-w75r-8gcw](https://github.com/advisories/GHSA-9jrq-w75r-8gcw)
- [GHSA-rq7h-c2jc-7f22](https://github.com/advisories/GHSA-rq7h-c2jc-7f22)

これらに対するVite 6系の修正は6.4.4以上だが、6.4.4には別の[GHSA-8g5g-mxqh-grgj](https://github.com/vitejs/vite/security/advisories/GHSA-8g5g-mxqh-grgj)があり、当該Advisoryで6.4.5が修正版と指定されている。そのため、[Vite公式リリース v6.4.5](https://github.com/vitejs/vite/releases/tag/v6.4.5)を更新対象とした。アプリ固有の悪用経路の成立までは検証していない。

### 12.2 変更範囲と差分精査

- `npm update vite --package-lock-only --dry-run --ignore-scripts`は正常終了。dry-runの表示のみを更新先バージョンの確証とはせず、実更新後にlockfileと依存ツリーを確認した。
- `npm update vite --package-lock-only --ignore-scripts`で更新後、`package-lock.json`はVite 6.4.5を解決した。
- npmによるlockfile自動更新時に、rootの`name`とTailwind Oxide WASM関連6エントリの追加が発生した。今回のVite更新には不要な差分として`git restore -p`で対象部分のみ取り消し、Viteの更新は残した。
- 最終的な変更は`package-lock.json`の`node_modules/vite`の`version`・`resolved`・`integrity`の3項目のみ（**3行追加・3行削除**）。`git diff --check`はPASS。
- `package.json`の`vite: ^6.4.3`、`@tailwindcss/vite@4.3.3`、`laravel-vite-plugin@1.3.0`のバージョンは変更していない。その他のパッケージ更新やrootへの新規直接依存追加はない。

### 12.3 ローカル検証結果

| 確認 | 実施結果 |
| --- | --- |
| `npm ci` | PASS（75 packages added、76 packages audited、0 vulnerabilities） |
| `npm ls vite` | root、`@tailwindcss/vite@4.3.3`、`laravel-vite-plugin@1.3.0`がすべて`vite@6.4.5`を共有（deduped） |
| `npm audit` | **found 0 vulnerabilities**（開発用依存を含むローカル監査結果） |
| `npm run build` | PASS（Vite 6.4.5、59 modules transformed、built in 3.09s） |
| `npm run typecheck:e2e` | PASS（`tsc -p e2e/tsconfig.json --noEmit`） |
| `git diff --check` | PASS |
| lockfile差分 | `package-lock.json`のVite関連3項目のみ、+3 / -3 |
| ローカルPlaywright E2E / PHPUnit | **未実施**。独立したGitHub Actions環境で確認する方針 |

`npm audit`の0件は当該worktreeの実行時点の監査結果であり、GitHubのDependabot AlertがCloseしたことや、アプリ全体の安全性の保証を意味しない。

### 12.4 worktree分離とCI確認方針

TMDB Issue #212の元worktreeには未コミットの5ファイルがあるため、ブランチ切替やstashを行わず、新しいworktreeにセキュリティ更新を隔離した。元worktreeの変更は本Issueの差分に含めていない。

ローカルPlaywright E2Eは`laravel.e2e`・ポート83・E2E専用DBの初期化を前提とする。別worktree側の`.env`・`vendor/`・Compose環境を独立構築していないため実行せず、PR作成後にGitHub Actionsの`quality-checks`、`Playwright E2E`、`python-quality-checks`の3ジョブを確認する。**本節の記録時点ではCI結果は未確認**である。

### 12.5 未完了事項

1. 本追補とlockfileの最終差分をレビューし、Issue #221の受け入れ条件に照合する。
2. 作業ブランチから`develop`へ通常PRを作成する。PR本文は`Refs #221`とし、`Closes`は使わない。
3. GitHub Actions全3ジョブの成功とレビューを確認したうえで、Merge commit方式で`develop`へ統合する。
4. PHP・Node.js・follow-redirectsのセキュリティ対応と合わせた`develop`→`main`同期PRについて、反映対象Issueと差分を再確認する。同期PRで対象IssueをCloseする。
5. `main`反映後、default branchの再解析とVite関連Dependabot Alertの状態を確認する。**未確認のAlert Closeや未実施のテストを完了済みと記録しない。**

参照資料：[Issue #221](https://github.com/honda-dev-jp/review-app-laravel/issues/221)、[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)、[Playwrightブラウザテスト運用ガイド](PLAYWRIGHT_TESTING.md)、[npm ci公式仕様](https://docs.npmjs.com/cli/v11/commands/npm-ci/)、[npm audit公式仕様](https://docs.npmjs.com/cli/v11/commands/npm-audit/)。

## 13. 2026-10-23追補：セキュリティ対応5件のmain同期・再解析確定記録

### 13.1 記録時点と歴史的記述の扱い

本節は2026-10-23（JST）の後続確認結果であり、第1〜12節に記録された各作業時点の実績・未完了事項を遡及して書き換えない。特に第11節（Issue #219）および第12節（Issue #221）の「PR未作成」「CI未確認」「main未反映」は**各節を執筆した時点の記録**である。その後の確定結果は本節を正として参照する。

本節はGitHub上のPR・Issue、`main`のlockfileとCI設定、GitHub Actionsのチェック、利用者によるローカルGit実行ログおよびDependabot画面の確認に基づく。本番環境へのデプロイを意味しない。

### 13.2 反映対象と通常PR

| Issue | 通常PR | 対応内容 | developへのMerge commit |
| --- | --- | --- | --- |
| [#213](https://github.com/honda-dev-jp/review-app-laravel/issues/213) | [#214](https://github.com/honda-dev-jp/review-app-laravel/pull/214) | PHP Critical脆弱性への対応（8.4.25 → 8.4.27）とCIのPHPバージョンガード | `988524f` |
| [#215](https://github.com/honda-dev-jp/review-app-laravel/issues/215) | [#216](https://github.com/honda-dev-jp/review-app-laravel/pull/216) | PHP対応後の実測・マージ実績の文書化 | `4409305` |
| [#217](https://github.com/honda-dev-jp/review-app-laravel/issues/217) | [#218](https://github.com/honda-dev-jp/review-app-laravel/pull/218) | GitHub ActionsのNode.jsを24.22.1へ更新 | `6404d3a` |
| [#219](https://github.com/honda-dev-jp/review-app-laravel/issues/219) | [#220](https://github.com/honda-dev-jp/review-app-laravel/pull/220) | `follow-redirects` 1.16.0 → 1.16.1 | `12e4455` |
| [#221](https://github.com/honda-dev-jp/review-app-laravel/issues/221) | [#222](https://github.com/honda-dev-jp/review-app-laravel/pull/222) | `vite` 6.4.3 → 6.4.5 | `10fd246` |

GitHubで5件の通常PRがいずれも`develop`にMergedであることを確認した。第11・12節で未実施としていたPR作成・マージ・CI確認は後続で実施済み。ただし、各セキュリティ用worktreeでローカルPHPUnit・Playwright E2Eを追加実行したものではなく、GitHub Actionsの回帰結果と区別する。

### 13.3 同期PR #223とCI・CodeQL

2026-10-23 01:28:48 JST（2026-10-22 16:28:48 UTC）、[同期PR #223](https://github.com/honda-dev-jp/review-app-laravel/pull/223)をBase `main`、Head `develop`、**Merge commit方式**でマージした。Merge commitは`f421e10f3d8416c1be428902c837afd5789f4985`。比較時点のBase `b05fac3`、Head `10fd246`から10コミット、6ファイル（`.github/workflows/ci.yml`、`docs/DEPENDENCY_UPDATE_HISTORY.md`、`docs/LARAVEL_UPGRADE_GUIDE.md`、`docs/LARAVEL_UPGRADE_HISTORY.md`、`docs/PLAYWRIGHT_TESTING.md`、`package-lock.json`）を反映した。TMDB Issue #212の未コミット文書・機能実装は含まない。

PR #223のpull requestイベントによる[CI run 39812912669](https://github.com/honda-dev-jp/review-app-laravel/actions/runs/39812912669)で`quality-checks`、`Playwright E2E`、`python-quality-checks`の3ジョブがいずれも`completed / success`。PR #223のhead（`refs/pull/223/head`）を対象としたCodeQLの[run 39812905645](https://github.com/honda-dev-jp/review-app-laravel/actions/runs/39812905645)でもJavaScript / TypeScript、Python、GitHub Actionsの3解析ジョブが成功し、当該PRのCode scanning resultsは新規アラートなしと表示された。**これは既知・未知の脆弱性がすべて不存在であるという証明ではない。**

PR #223本文のマージ前・マージ後チェックリスト計16項目はGitHubで全件チェック済み。関連Issue5件はいずれも`main`反映によりClosedとなった。

### 13.4 main・developの同期とローカル作業環境

プロジェクトの[GitHub開発運用ガイド](GITHUB_WORKFLOW.md)第11節に従い、TMDB作業とは別の専用worktree `review-app-laravel-sync-223`で、`main`を`git pull --ff-only origin main`により更新した。その後、ローカル`develop`で`git pull --ff-only origin develop`、`git merge --ff-only main`、`git push origin develop`を実行。**force push、reset、rebase、非fast-forward mergeは使用していない。**

最終確認の`git rev-list --left-right --count origin/develop...origin/main`は`0 0`。ローカル`main`・`develop`および`origin/main`・`origin/develop`の4参照が`f421e10`で一致した。同期用worktreeと個別セキュリティ用worktreeは削除済み。

TMDB専用の元worktree `review-app-laravel`は`feat/212-tmdb-search-poster-display`、HEAD `6404d3a`のまま維持。`docs/FEATURES.md`、`docs/ROUTES.md`、`docs/SCREEN_TRANSITIONS.md`、`docs/SECURITY.md`の変更4件、および未追跡の`docs/TMDB_SEARCH_DESIGN.md` 1件の存在を確認した。**本同期ではTMDB側の未コミット変更を追加・破棄・マージしていない。**

### 13.5 default branchの依存関係・Dependabot再解析

同期後の`main`をGitHubから読み取り確認した結果、`package-lock.json`の解決バージョンは`follow-redirects@1.16.1`と`vite@6.4.5`。`.github/workflows/ci.yml`ではNode.js `24.22.1`を指定し、PHP 8.4.27以上・8.5.0未満のバージョン判定ステップを2ジョブに設けている。これらはリポジトリに反映された依存関係・CIの設定値であり、XServer本番環境のPHPやNode.jsの稼働バージョンを意味しない。

2026-10-23（JST）に利用者がGitHub Dependabot alerts画面を確認したところ、**Open 0件／Closed 65件**、依存ファイルは再チェック済みと表示された。さらに、利用者がGitHub CLIを使用した最終確認ログでは、**Alert #65・#66はいずれも`fixed`、packageは`follow-redirects`**と確認された。Vite関連Alertの個別Closed理由までは本節で確認していない。`Closed 65件`をすべて修正済みと解釈しない。

Issue #221の専用worktreeでの`npm audit`は更新当時0 vulnerabilitiesだったが、GitHubのOpen 0件は**`main`反映後の別の確認結果**である。Dependabotはdefault branchの依存関係を解析するため、開発ブランチ上の`npm audit`やPRのCodeQL結果とは区別して記録する（[GitHub公式 Dependabot alerts](https://docs.github.com/en/code-security/concepts/supply-chain-security/dependabot-alerts)）。

### 13.6 この追補に含まれない作業

- 本番XServerのPHP更新・デプロイ・本番手動回帰は本同期PRの対象外。
- この追補作成時点で、同期後の専用クリーンローカル環境におけるPHPUnit・Playwright E2E全件の**再実行**は行っていない。PR #223のCI成功は、独立したGitHub Actions環境での回帰確認結果として別に記録する。
- 第8〜9節に記載した2026-10-18当時の未完了項目やAlert #63・#64、`braces`の個別履歴は当時の記録として保持し、本節だけで個別のClose理由・経路検証が済んだとは主張しない。
- 最新のGitHub画面でOpen 0件を確認したことは、将来の脆弱性が発生しないことを保証しない。
