# MVP1 テスト対応状況

## 目的

この文書は、Issue #60で実施した監査に基づき、MVP1完了時点の要件・主要な受け入れ条件とFeatureテストの対応関係を記録するものです。自動テストに適さない確認や、MVP1では追加しないと判断した項目も区別します。

これはMVP1時点の監査記録であり、全テストメソッドを常に列挙する仕様書ではありません。MVP2以降の機能追加では関連Issue・テスト・文書を更新し、必要に応じて本表も更新します。

## 対象範囲

対象は、認証、アカウント管理、作品表示、レビュー投稿・削除、レビュー返信、本人レビュー一覧、会員退会後のデータ整合性、および関連するアクセシビリティ・共通表示です。

監査では、README、MVP1関連文書、Issue #60、現在のroutes・Controller・Form Request・Policy・Model・Service・Blade・DB制約と、`tests/Feature/`配下の実際のセットアップおよびassertionを照合しました。LaravelやBreezeの内部実装そのもの、ブラウザでなければ有効に確認できない操作、MVP2以降の機能は自動テスト追加の対象から除外しています。

## 判定区分

| 判定 | 意味 |
|---|---|
| テスト済み | 現在のFeatureテストで契約を確認している（既存保証とIssue #60での追加が混在する場合を含む） |
| 既存テストで保証済み | Issue #60開始前のテストで必要な保証が存在した |
| Issue #60で追加 | 監査で確認した不足をIssue #60のテスト差分で補った |
| 手動確認 | 実ブラウザ、支援技術または画面幅を伴う確認が適切 |
| 追加不要 | 既存保証との重複、Laravel標準責務、または実装詳細への過度な依存を避けるため追加しない |
| MVP2以降 | MVP1完了条件には含めず、後続フェーズで検討する |
| 別Issue | Issue #60のテスト追加とは分離して設計・実装方針を整理する |

## 機能別対応表

### 認証

| 機能・要件 | 主なテストファイル | 判定 | 補足 |
|---|---|---|---|
| 会員登録と登録画面 | `Auth/RegistrationTest.php` | 既存テストで保証済み | 登録成功、通常表示、入力エラーとARIA属性を確認 |
| ログイン・ログアウト | `Auth/AuthenticationTest.php` | 既存テストで保証済み | 正常ログイン、不正パスワード拒否、ログアウト後の認証状態・遷移・通知を確認 |
| パスワードリセット申請・再設定 | `Auth/PasswordResetTest.php` | 既存テストで保証済み | 通知、正常再設定、無効token、確認不一致、パスワード規則、未登録メールを確認 |
| パスワード確認 | `Auth/PasswordConfirmationTest.php` | 既存テストで保証済み | 正常確認、不正パスワード、入力エラーのARIA属性を確認 |
| 認証フォームのエラー表示 | `Auth/AuthenticationTest.php`、`Auth/RegistrationTest.php`、`Auth/PasswordResetTest.php`、`Auth/PasswordConfirmationTest.php` | 既存テストで保証済み | 入力とエラーメッセージの関連付け、通常時の不要なエラー状態の非出力を確認 |

### アカウント管理

| 機能・要件 | 主なテストファイル | 判定 | 補足 |
|---|---|---|---|
| アカウント画面表示 | `ProfileTest.php` | 既存テストで保証済み | 画面表示、主要フラグメント、通常時のARIA状態を確認 |
| ゲストの画面アクセス拒否 | `ProfileAccessTest.php` | Issue #60で追加 | `profile.edit`がログイン画面へリダイレクトされることを確認 |
| プロフィール情報更新 | `ProfileTest.php` | 既存テストで保証済み | 名前、メール、自己紹介、成功通知とURLフラグメントを確認 |
| 自己紹介1000文字境界 | `ProfileTest.php` | 既存テストで保証済み | 1000文字許可、1001文字拒否を確認 |
| ゲストの更新拒否 | `ProfileAccessTest.php` | Issue #60で追加 | PATCHがログイン画面へリダイレクトされ、DBが変化しないことを確認 |
| パスワード更新 | `Auth/PasswordUpdateTest.php`、`ProfileTest.php` | 既存テストで保証済み | 正常更新、現在のパスワード不一致、フォームごとのエラー分離を確認 |
| ユーザーアイコン登録・差し替え・表示 | `ProfileTest.php` | 既存テストで保証済み | JPEG・PNG・WebP、差し替え、未設定・欠損時表示を確認 |
| アイコン形式・2MB境界 | `ProfileTest.php` | 既存テストで保証済み | GIF・SVG・非画像・拡張子偽装拒否、2MB許可、上限超過拒否を確認 |
| アイコン保存・DB更新・旧画像削除失敗 | `ProfileTest.php` | 既存テストで保証済み | DBとStorageの結果、補償削除、更新済み状態の維持を確認 |
| Mass Assignment・他ユーザー情報の保護 | `ProfileTest.php` | 既存テストで保証済み | `role`・`avatar_path`混入拒否と他ユーザーのアイコン非変更を確認 |
| 退会成功・ログアウト | `ProfileTest.php` | 既存テストで保証済み | users物理削除、セッション無効化、CSRF token再生成、通知を確認 |
| 誤パスワード・未入力での退会拒否 | `ProfileTest.php` | テスト済み | 誤パスワードは既存保証。passwordキーなし、名前付きエラーバッグ、認証・users維持はIssue #60で追加 |
| ゲストの退会拒否 | `ProfileAccessTest.php` | Issue #60で追加 | DELETEがログイン画面へリダイレクトされ、ユーザーが残ることを確認 |
| 退会済みユーザーの再生成・再ログイン防止 | `ProfileTest.php` | 既存テストで保証済み | remember token設定時も再挿入されず、削除済みメールでログインできないことを確認 |

### 作品一覧・作品詳細

| 機能・要件 | 主なテストファイル | 判定 | 補足 |
|---|---|---|---|
| 作品一覧の基本表示 | `ItemIndexTest.php` | テスト済み | `/`の正常表示と通常時に空のstatus領域がないことは既存保証 |
| タイトル・カテゴリ・平均評価・評価件数 | `ItemIndexTest.php` | Issue #60で追加 | `/items`で対象作品のタイトル、カテゴリ、4.5、2件を確認 |
| 10件単位のページネーション | `ItemIndexTest.php` | Issue #60で追加 | 11件を10件・1件に分け、範囲・総件数・代表タイトルを確認。時刻を明示して順序を安定化 |
| 詳細の作品名・平均評価・評価件数 | `ItemShowTest.php` | Issue #60で追加 | 評価表示の契約に限定し、既存のレビュー・返信表示と重複させていない |
| 存在しない作品 | `ItemShowTest.php` | Issue #60で追加 | 作品詳細ルートが404を返すことを1ケース確認 |
| レビュー・返信・投稿者アイコンの表示 | `ReviewTest.php`、`ReviewCommentStoreTest.php`、`ProfileTest.php` | 既存テストで保証済み | 保存した本文、投稿者アイコン、退会後の匿名表示を確認 |

### レビュー投稿・削除

| 機能・要件 | 主なテストファイル | 判定 | 補足 |
|---|---|---|---|
| 認証ユーザーの正常投稿 | `ReviewTest.php` | 既存テストで保証済み | DB保存、詳細への遷移、通知を確認 |
| ゲスト投稿拒否 | `ReviewTest.php` | 既存テストで保証済み | ログイン画面への遷移とreviews未保存を確認 |
| rating・本文の必須、範囲外、1001文字拒否 | `ReviewTest.php` | 既存テストで保証済み | rating 0・6・小数、空入力、本文上限超過を確認 |
| rating下限1・本文上限1000文字の許可 | `ReviewTest.php` | Issue #60で追加 | 1と1000文字を同時に投稿し、DB保存値を確認 |
| 1ユーザー1作品1レビュー | `ReviewTest.php` | 既存テストで保証済み | Controller経由の重複投稿拒否と件数不変を確認 |
| `user_id`・`item_id`混入防止 | `ReviewTest.php` | Issue #60で追加 | 認証ユーザーとURL上の作品へだけ保存されることをDBで確認 |
| 投稿後の評価キャッシュ更新 | `ReviewTest.php` | テスト済み | 整数平均は既存保証。4と5の投稿による4.5・2件はIssue #60で追加 |
| 本人削除・他人削除拒否・ゲスト削除拒否 | `ReviewTest.php` | 既存テストで保証済み | Policy、auth middleware、DB結果を確認 |
| 削除後の評価再計算・最後のレビュー削除 | `ReviewTest.php` | 既存テストで保証済み | 残存レビューからの再計算、最後の削除後のnull・0を確認 |
| 評価更新失敗時の削除rollback | `ReviewTest.php` | Issue #60で追加 | Review作成後にServiceをMock化し、例外時にレビューと元の評価値が残ることを確認 |
| 削除時の返信cascade | `ReviewTest.php` | Issue #60で追加 | 認可済み削除ルートからreviewsとreview_comments双方の削除を確認 |
| バリデーションエラーのARIA属性 | `ReviewTest.php` | 既存テストで保証済み | rating・本文の入力とエラー要素の関連付け、返信フォームとの分離を確認 |

### レビュー返信

| 機能・要件 | 主なテストファイル | 判定 | 補足 |
|---|---|---|---|
| 認証ユーザーの正常投稿・表示 | `ReviewCommentStoreTest.php` | 既存テストで保証済み | 対象レビューへのDB保存、詳細への遷移、通知、本文表示を確認 |
| ゲスト投稿拒否 | `ReviewCommentStoreTest.php` | 既存テストで保証済み | ログイン画面への遷移と未保存を確認 |
| 識別子混入防止 | `ReviewCommentStoreTest.php` | 既存テストで保証済み | URL上のレビュー、認証ユーザー、`parent_id=null`が使われることを確認 |
| 本文必須・1000文字許可・1001文字拒否 | `ReviewCommentStoreTest.php` | 既存テストで保証済み | 名前付きエラーバッグとDB結果を含めて確認 |
| 入力値復元・フォーム識別子 | `ReviewCommentStoreTest.php` | 既存テストで保証済み | old inputと`form_review_id`を確認 |
| 存在しないレビューへの投稿 | `ReviewCommentStoreTest.php` | Issue #60で追加 | 認証済みPOSTが404となり、返信が保存されないことを確認 |
| エラー表示のARIA属性 | `ReviewCommentStoreTest.php` | 既存テストで保証済み | 複数フォームのうち送信元だけがエラー状態になることを確認 |

### 本人レビュー一覧

| 機能・要件 | 主なテストファイル | 判定 | 補足 |
|---|---|---|---|
| 本人レビューのみ表示 | `ReviewMineTest.php` | 既存テストで保証済み | 他ユーザーの作品・本文・件数が混ざらないことを確認 |
| ゲストアクセス拒否 | `ReviewMineTest.php` | 既存テストで保証済み | ログイン画面への遷移を確認 |
| 作品情報・本文・評価表示 | `ReviewMineTest.php` | テスト済み | 作品名・本文は既存保証。評価値5.0はIssue #60で既存テストへ追記 |
| 0件時表示 | `ReviewMineTest.php` | 既存テストで保証済み | 空状態の案内と作品一覧導線を確認 |
| ページネーション・新着順 | `ReviewMineTest.php` | 既存テストで保証済み | 11件のページ分割と日時順を確認 |
| 本人レビュー削除後の遷移・通知 | `ReviewMineTest.php` | 既存テストで保証済み | 本人一覧へのredirect、DB削除、status通知を確認 |
| 削除フォーム契約 | `ReviewMineTest.php` | 既存テストで保証済み | DELETEメソッド、削除先、戻り先を確認 |

### 会員退会後のデータ整合性

| 機能・要件 | 主なテストファイル | 判定 | 補足 |
|---|---|---|---|
| users物理削除 | `ProfileTest.php` | 既存テストで保証済み | 正しいパスワードで削除されることを確認 |
| レビュー・返信本文の残存とuser_idのnull化 | `ProfileTest.php` | 既存テストで保証済み | reviews、review_comments双方をDBで確認 |
| 退会後の匿名表示 | `ProfileTest.php` | 既存テストで保証済み | レビュー・返信の匿名名と共通No Imageを確認 |
| 評価キャッシュ不変 | `ProfileTest.php` | Issue #60で追加 | Review Factoryで計算した退会前後の`items.rating`・`rating_count`を比較 |
| ユーザーアイコン削除 | `ProfileTest.php` | 既存テストで保証済み | ユーザー固有画像の削除を確認 |
| アイコン欠損・削除失敗時の退会継続 | `ProfileTest.php` | 既存テストで保証済み | users削除とログアウトを維持することを確認 |
| 共有No Imageを削除しない | `ProfileTest.php` | 既存テストで保証済み | Storageのdeleteが呼ばれず、固定資産が残ることを確認 |

### アクセシビリティ・共通表示

| 機能・要件 | 主なテストファイル | 判定 | 補足 |
|---|---|---|---|
| 入力エラーのARIA属性 | `Auth/*Test.php`、`ProfileTest.php`、`ReviewTest.php`、`ReviewCommentStoreTest.php` | 既存テストで保証済み | `aria-invalid`、`aria-describedby`、エラー要素IDを対象入力ごとに確認 |
| 通常時の空status領域を出力しない | `AuthenticationTest.php`、`PasswordResetTest.php`、`ItemIndexTest.php`、`ProfileTest.php`、`ReviewMineTest.php` | 既存テストで保証済み | 通知がない通常表示で`role="status"`が存在しないことを確認 |
| フラッシュメッセージ | 認証・プロフィール・レビュー・返信・本人一覧の各Featureテスト | 既存テストで保証済み | redirect後のsessionと表示DOMの`role="status"`を主要経路で確認 |
| 未使用`/dashboard`の404 | `DashboardRouteTest.php` | 既存テストで保証済み | 認証ユーザーでも未使用ルートへアクセスできないことを確認 |
| CSRF・Bladeエスケープのフレームワーク内部 | （該当テストなし） | 追加不要 | `web` middlewareのCSRF保護を無効化する設定がないことと、主要なユーザー入力の表示でBladeの標準エスケープ出力を利用していることを確認。Laravel標準内部処理の再テストは追加しない |

## Issue #60で追加・強化したテスト

Issue #60では、次の16項目を追加または既存テストへ追記しました。

1. 作品一覧の作品情報・カテゴリ・平均評価・評価件数
2. 作品一覧の10件単位ページネーション
3. 作品詳細の平均評価・評価件数
4. ゲストのプロフィール画面アクセス拒否
5. ゲストの退会拒否とusers残存
6. rating 1・本文1000文字のレビュー投稿成功
7. レビュー投稿時の`user_id`・`item_id`混入防止
8. 複数レビューによる小数平均4.5・件数2
9. 評価更新失敗時のレビュー削除rollback
10. レビュー削除時の返信cascade削除
11. 存在しないレビューへの返信投稿の404・未保存
12. passwordキーなしの退会拒否、名前付きエラーバッグ、認証・users維持
13. 退会後の評価キャッシュ不変
14. 存在しない作品詳細の404
15. ゲストのプロフィール更新拒否とDB不変
16. 本人レビュー一覧の評価値表示

CodexおよびClaude Codeのレビューでは、Critical・High・Mediumの修正必須指摘はありませんでした。16項目はいずれもIssue #60の範囲内であり、本体コードは変更していません。削除側のrollbackテストは現在の削除順序におけるtransaction欠落を検出できるため、Mock内でDBを書き換えてから例外を投げる追加強化は採用しませんでした。Lowの改善提案はコミットを止める問題とは判定していません。

## 既存テストで保証済みだった項目

監査前から、次の主要契約はFeatureテストで保証されていました。

- 登録、ログイン、ログアウト、パスワードリセット、パスワード確認と認証エラー
- 認証フォーム、プロフィール、レビュー、返信のバリデーションエラーとARIA属性
- プロフィール表示・更新、自己紹介境界、パスワード更新
- ユーザーアイコンの形式・サイズ・差し替え・障害時整合性・Mass Assignment対策
- 正常退会、誤パスワード拒否、ログアウト、remember tokenによる再挿入防止
- レビュー正常投稿、異常値拒否、重複拒否、認証・認可、評価キャッシュ更新・再計算
- 返信正常投稿、識別子混入防止、認証、本文境界、入力復元、表示
- 本人レビューだけの表示、0件時表示、ページネーション、新着順、削除後遷移
- 退会後のレビュー・返信残存、user_idのnull化、匿名表示、画像削除時の整合性

## 今回追加しなかった項目

| 項目 | 判定 | 理由 |
|---|---|---|
| レビュー投稿側のtransaction rollback | 追加不要 | 削除側で同一の評価キャッシュ更新を含むtransaction契約を代表確認しており、MVP1の追加価値が限定的 |
| 認証済みユーザーのguest画面横断拒否 | 追加不要 | Breeze・guest middlewareの標準責務寄りで、MVP1の主要な回帰リスクではない |
| reviewsのDB UNIQUE制約を直接確認するテスト | 追加不要 | Controller経由の重複拒否を確認済みで、MySQL制約そのものの再テストになる |
| 認証フォームのautocomplete横断固定 | 追加不要 | 主要箇所は既存テスト・関連Issueで確認され、Issue #60の主要リスクではない |
| N+1のquery-count固定テスト | 追加不要 | クエリ数への固定は実装詳細依存が強く、MVP1のFeature契約ではない |
| 作品一覧の並び順専用テスト | 追加不要 | ページネーションテストで時刻と代表項目を確認しており、専用テストは重複になる |
| 本人レビュー一覧のHTML構造固定 | 追加不要 | 作品・本文・評価・削除導線の意味的な表示契約を確認済み |
| モーダルのJavaScript実動作、フォーカス移動、レスポンシブ・視覚表示 | 手動確認 | Featureテストでは実ブラウザの操作・描画を保証できない |
| メール認証 | MVP2以降 | MVP1では`MustVerifyEmail`を有効化しておらず、メール認証機能は後続フェーズで実装する |
| E2E・Playwright | MVP2以降 | MVP1では新しいテスト基盤を導入しない |
| `fillable`方針の整理 | 別Issue | 現時点で具体的な脆弱性は確認されておらず、設計・文書・実装方針の整理として分離する |

## 手動確認が必要な項目

次の項目はFeatureテストでは実動作を保証できないため、ブラウザと必要に応じて支援技術で確認します。

- 退会・レビュー削除モーダルの開閉
- Escapeキーによる閉鎖
- Tab・Shift+Tabによるフォーカス循環
- モーダルを閉じた後のフォーカス復帰
- 再表示時のエラー表示リセットとパスワード入力値リセット
- JavaScript Consoleのerror・warning
- PC・タブレット・モバイル幅でのレスポンシブ表示
- 星表示、評価分布、カード、フォーム、モーダルの視覚的な崩れ
- ページネーションリンクの実クリックと遷移

モーダルのアクセシビリティ契約とエラー状態リセットにはIssue #40・Issue #75の関連実装がありますが、JavaScript実動作は引き続き手動確認対象です。

## MVP2以降の対象

- E2E・Playwrightなどのブラウザ自動化基盤
- 管理者機能、TMDB API連携、お問い合わせ
- レビュー・返信の編集、返信削除、お気に入り
- MVP2以降の機能に応じた追加のセキュリティ・パフォーマンス検証

これらはMVP1のテスト完了条件には含めません。

## 品質確認結果

Issue #60のテスト差分を含む状態で、次の結果を確認しています。

| 確認 | 結果 |
|---|---|
| Laravel Pint | PASS（109 files） |
| PHPStan / Larastan | 初回はparallel workerがexit code 139で異常終了。同一コマンドの再実行で`[OK] No errors`を確認 |
| PHPUnit全体 | 125 passed / 1305 assertions |
| 対象限定Featureテスト | 94 passed / 952 assertions |
| Vite build | 成功（55 modules transformed） |
| `git diff --check` | 問題なし |
| GitHub Actions | PR作成後に確認予定 |

PHPStan初回の異常終了は、アプリケーションコードまたはIssue #60の差分に起因する静的解析エラーを示す具体的根拠がなく、再実行では解析エラーがないことを確認しています。

## 関連資料

- [README](../README.md)
- [要件定義](REQUIREMENTS.md)
- [機能一覧](FEATURES.md)
- [実装計画](IMPLEMENTATION_PLAN.md)
- [画面遷移](SCREEN_TRANSITIONS.md)
- [ルーティング設計](ROUTES.md)
- [DB設計](DATABASE.md)
- [セキュリティ方針](SECURITY.md)
- [開発フロー](DEVELOPMENT_FLOW.md)
- [コマンド集](COMMANDS.md)
- GitHub Issue #60「test: MVP1機能のテスト網羅状況を監査し不足テストを追加する」
