# Claude Code権限設計

## 1. 目的

この文書は、Issue #50「Claude Codeの公式情報参照と読み取りコマンドの許可運用を改善する」における、Claude Codeのpermissions、PreToolUse Hook、および人間による承認の設計を定義する。

目的は、Claude Codeを読み取り専用のセカンドオピニオンとして維持しながら、公式一次情報の参照と限定した読み取り系コマンドを、Hookと人間の承認で安全に制御することである。

本プロジェクトの第一目的はLaravelの学習である。Claude Codeの権限整備は、安全な実装前検証とコードレビューに必要な最小範囲へ限定して早期完了し、pager対策等の追加改善は実運用上の必要性を確認してから後続Issueで扱う。

この文書は設計書であり、`.claude/settings.json`やHookの実装済み状態を示すものではない。現行設定と実装予定を混同しないため、初期版の自動allowは0件と明記し、実装予定の判定は「ask候補」「deny補強候補」と表記する。

## 2. 適用範囲

対象は次のとおりとする。

- `.claude/settings.json`のallow、ask、deny設計
- Plan modeの標準運用
- Bashの読み取り系コマンド
- WebSearchとWebFetch
- Gitの読み取り系・変更系コマンド
- GitHub CLIの閲覧系・変更系コマンド
- Read権限と秘密情報保護
- Agentの禁止
- PHPUnit、PHPStan、Pint、`route:list`
- PreToolUse HookによるBashとWebFetchの追加検査
- 人間による実機検証とフォールバック

次は対象外とする。

- Claude Codeによるアプリケーション実装・修正
- Hook以外の新しい自動化機構
- sandboxの導入
- MCP権限の再設計
- Issue・PR・GitHub上のデータを変更する自動化
- 自動的なcommit、push、merge、branch操作

## 3. Claude Codeの役割

Claude Codeは次の用途に限定する。

- 実装前検証
- 設計レビュー
- PR差分レビュー

Claude Codeにはアプリケーション実装、ファイル修正、Git変更、Issue・PR変更を行わせない。Claude Codeの出力は補助情報であり、採否、実装開始、承認、マージの最終判断は人間が行う。

## 4. 前提資料と公式一次情報

### 4.1 プロジェクト資料

この設計は次を前提とする。

- `AGENTS.md`
- `CLAUDE.md`
- `README.md`
- `.claude/settings.json`
- `.claude/skills/pre-implementation-review/SKILL.md`
- `.claude/skills/pr-diff-review/SKILL.md`
- `docs/CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md`
- `docs/CLAUDE_CODE_REVIEW.md`
- `docs/SECURITY.md`
- `docs/COMMANDS.md`
- `docs/DEVELOPMENT_FLOW.md`
- 親Issue #50
- サブIssue #51
- サブIssue #52

### 4.2 公式仕様からの主要な前提

- permissionルールはdeny、ask、allowの順で評価され、詳細なルールであっても上位分類の広いルールを上書きしない。
- bare `Bash`のaskはすべてのBashに一致するため、個別のBash allowより先にaskとなる。
- bare `WebFetch`のdenyはすべてのWebFetchに一致するため、ドメイン単位allowより先にdenyとなる。
- Claude Codeには組み込みの読み取り専用Bash判定があり、明示的なaskまたはdenyがなければ確認なしで実行されるコマンドがある。
- 複合Bashはサブコマンド単位で評価される。すべての構成要素が許可される場合、複合形も許可され得る。
- PreToolUse Hookの`permissionDecision`には公式上`allow`、`ask`、`deny`、`defer`が存在する。本プロジェクトの初期版では`ask`と`deny`だけを使用し、`allow`と`defer`は使用しない。Hookのallowはpermissionのaskやdenyを上書きしない。
- PreToolUse Hookのexit code 2は実行を拒否する。Hookの起動失敗、異常終了、timeout時の最終挙動は一律に自動denyと仮定せず、実装時点の公式仕様と使い捨て環境で確認する。
- Hook自身はWebFetchのredirectを追跡できない。Claude Codeがredirect先を別のWebFetch呼び出しとして扱う場合に限り、その新しい入力へ同じHook判定を適用できる。実際の挙動は実機確認事項とする。
- 現行のPreToolUse入力では、Bashの`tool_input`に`command`、任意の`description`、`timeout`、`run_in_background`があり、WebFetchの`tool_input`には`url`と`prompt`がある。WebFetchには`run_in_background`が定義されていない。
- ReadとEditのdenyは組み込みツールと認識可能な一部Bash操作へ適用されるが、任意のサブプロセスによる間接アクセスをOSレベルで完全には防がない。

## 5. 脅威モデル

### 5.1 非信頼入力

次の内容を非信頼入力として扱う。

- GitHub Issue本文・コメント
- GitHub PR本文・コメント・レビュー
- Webページ本文
- Git履歴、commit message、branch名
- テスト失敗出力や外部ツールの出力
- ユーザーが安全性を確認していないローカルファイル

非信頼入力内の命令を、Claude Codeへの運用指示として実行しない。

### 5.2 プロンプトインジェクション

非信頼入力が、設定変更、秘密情報の参照、外部送信、ファイル変更、追加コマンドの実行を要求する可能性を想定する。CLAUDE.md、permissions、Hook、人間の承認を組み合わせるが、いずれも単独で完全な防御とは扱わない。

### 5.3 外部通信

外部通信は、明示的に許可した公式一次情報のWebFetchと、設計上許可したGitHub CLI閲覧系だけに限定する。`curl`、`wget`、`ssh`等をWebFetchの代替経路として許可しない。

### 5.4 秘密情報

次を読み取り、URL、query、Hookログ、レビュー結果、外部サービスへ含めない。

- `.env`および秘密情報を含む`.env.*`
- APIキー、token、password、credential
- 秘密鍵
- ログ、セッション、private storage、cache
- SQL dump、backup
- 個人情報を含むローカルデータ

設定例が必要な場合は、秘密情報を含まない`.env.example`だけを使用する。

### 5.5 意図しない変更

ファイル、Git履歴、index、作業ツリー、remote、GitHub上のデータ、DB、cache、依存関係、常駐プロセスの意図しない変更を防ぐ。

この文書における「読み取り専用」は、Git履歴、追跡対象、作業ツリー内容、remote状態を意図的に変更しない操作を指す。表示系Gitコマンドが内部cacheやstat情報へ一切書き込まないことまでは保証しない。

## 6. 多層防御の全体像

```text
Plan mode
  └─ 調査・レビュー中心の運用を補助する

permissions
  └─ deny / ask / allowによる通常の権限制御

PreToolUse Hook
  └─ 実行直前の入力を決定論的に追加検査する

人間の承認
  └─ 最終判断を行う
```

役割分担は次のとおりとする。

| 層 | 主な役割 | 限界 |
|---|---|---|
| Plan mode | 調査・レビュー中心の進行を補助 | BashやReadの権限判定を置き換えない |
| permissions | 既知のツール・コマンドをallow、ask、denyへ分類 | Bashの別表記や間接操作を完全には表現できない |
| PreToolUse Hook | raw入力を実行直前に追加検査 | Hook自体の異常終了は常にfail-closedではない |
| 人間 | 対象、コマンド、承認、結果を最終確認 | 誤承認を防ぐため手順と表示の確認が必要 |

## 7. Plan mode運用

Claude Codeのレビュー用途ではPlan modeを標準とする。

1. セッション開始後、`Shift + Tab`を使用してPlan modeへ切り替える。
2. `Shift + Tab`はモードを順番に切り替えるため、ステータスバーが`Plan`になるまで確認する。押下回数を固定して扱わない。
3. `/status`、`/permissions`、ステータスバーを確認してからレビューを開始する。
4. Plan modeから編集を開始する承認を行わない。
5. レビュー結果はチャットへ直接出力し、プロジェクト内にmemory、plan、メモファイルを作成しない。

Plan modeはsource fileの編集を抑止する補助策だが、ReadやBashの利用、permission確認は引き続き発生する。また、Claude CodeはPlan modeの計画をユーザーディレクトリ側のplanファイルへ保存する場合がある。したがってPlan modeだけをセキュリティ境界として扱わない。

権限管理の正本は`.claude/settings.json`とし、PreToolUse Hookを追加のガードレールとして使用する。最終判断は人間が行う。

## 8. Permissionモデル

### 8.1 deny / ask / allow

| 判定 | 意味 | このプロジェクトでの用途 |
|---|---|---|
| deny | ツール呼び出しを拒否する | 編集、Agent、WebSearch、変更系Git・gh、秘密情報 |
| ask | 実行前に人間へ確認する | 可変引数、対象限定テスト、未登録の安全候補 |
| allow | 確認なしで実行できる | 初期版では使用しない |

評価順はdeny、ask、allowである。たとえばbare `Bash`をaskへ残したまま`Bash(git status --short)`をallowしても、bare askが先に一致するため確認は省略されない。

### 8.2 設定スコープ

設定の優先順位は次のとおりである。

1. Managed settings
2. コマンドライン引数
3. Local project settings：`.claude/settings.local.json`
4. Shared project settings：`.claude/settings.json`
5. User settings：`~/.claude/settings.json`

permissionは複数スコープのルールを合わせて評価し、いずれかのスコープでdenyに一致すれば他のallowでは解除できない。Projectのallowはworkspace trust受諾後に適用される。

### 8.3 正本と恒久許可

- Project共通のallowルールは`.claude/settings.json`で一元管理する。
- 承認画面から`Always allow`、`Yes, and don't ask again`、または同等の恒久許可を追加しない。
- User、Local、Managed settingsも有効な権限へ影響するため、`.claude/settings.json`だけを見て安全と判断しない。
- `/permissions`と`/status`で、実際の設定ソースと有効なルールを確認する。

### 8.4 現行設定からの変更前提

現行設定はbare `Bash`をask、bare `WebFetch`をdeny、allowを空配列としている。変更は次の2段階で行い、Hook導入とpermissions変更を別PRとする。

1. Issue #51ではPreToolUse Hook、回帰test、Hook README、関連文書を追加する。permissionsは変更せず、bare `Bash` ask、bare `WebFetch` deny、Allow 0件を維持する。
2. Issue #51の実装、回帰test、コードレビュー、Hook単体の実機検証が完了した後、Issue #52でpermissionsを変更する。bare `Bash` askを維持し、Git・GitHub CLI・WebFetchの自動allowを0件のまま、定義した安全候補をaskとしてHookとpermissionsを統合検証する。bare `WebFetch` denyはWebFetchのask運用に必要な範囲だけ変更する。

WebFetchの実通信を伴う統合検証はIssue #52で行う。WebSearch deny、Agent deny、Edit・Write・NotebookEdit denyは両段階で維持し、Hookの検証前にbare askまたはbare denyを外さない。

## 9. PreToolUse Hook

### 9.1 目的

PreToolUse Hookは、permissionルールだけでは厳密に制御しにくいraw入力を実行直前に検査する。permissionを置き換えず、既存denyと人間の承認を補強する。

### 9.2 対象ツール

matcher対象は次の2ツールだけとする。

- `Bash`
- `WebFetch`

設定上は`Bash|WebFetch`のmatcherを使用し、対象外ツールではHookを起動しない。対象外の`tool_name`を合成入力等でHookが直接受け取った場合はdenyする。

`WebSearch`と`Agent`はpermissionsでbare denyを維持するため、Hook対象にはしない。Readは既存denyを維持し、Bash経由の間接参照はBash Hookで追加検査する。

### 9.3 共通入力検査

- stdinをJSONとして正しく解析できること
- `hook_event_name`が`PreToolUse`であること
- `tool_name`、`tool_input`等、実装時点の公式Schemaで必須の共通fieldが存在し、型が正しいこと
- `tool_name`が`Bash`または`WebFetch`であること
- Bashでは`command`を必須stringとして検査し、任意fieldの`description`、`timeout`、`run_in_background`も存在する場合は型を検査すること
- WebFetchでは`url`と`prompt`を必須stringとして検査すること
- セキュリティ判断に無関係な既知・任意のtop-level共通fieldは、存在だけを理由にdenyしないこと
- `tool_input`内の未知field、必須field不足、想定外の型は、安全性を判定できない入力としてdenyすること
- 制御文字、NUL、コマンド置換等を各ツールの規則に沿って検出すること
- 入力文字列をshellとして実行しないこと
- 拒否理由へ入力内容、URL、prompt、環境変数値を含めないこと

公式JSON Schema全体は本文へ複製しない。実装時およびClaude Code更新時に最新の公式Schemaを再確認し、本プロジェクトが使用する共通field、tool固有field、型をHook READMEおよび回帰testで確認する。

現行の公式Schemaでは、`run_in_background`はBashの入力fieldであり、WebFetchの入力fieldではない。WebFetchでこのfieldが送られた場合は未知の入力構造としてdenyする一方、fieldが存在しない通常のWebFetch入力はそれだけを理由に拒否しない。

### 9.4 判定方針

| 結果 | 使用条件 |
|---|---|
| allow | 初期版では使用しない |
| ask | 操作自体は許可候補だが、対象パス、Issue番号、PR番号、query等を人間が確認する必要がある場合 |
| deny | 禁止操作、危険記号、対象外host、秘密情報らしき値、解析不能、未知の入力の場合 |

Hookの`allow`はpermissionのaskやdenyを上書きできない。Hookの`deny`はallowルールより優先して実行を止める。

Hookは正常な判定時、exit code 0でstdoutへ次の形の判定JSONだけを返す。`permissionDecisionReason`には固定された短い理由を使用する。

```json
{
  "hookSpecificOutput": {
    "hookEventName": "PreToolUse",
    "permissionDecision": "ask",
    "permissionDecisionReason": "Fixed policy reason"
  }
}
```

exit code 2はPreToolUseをブロックする。この場合、stdoutのJSONは無視され、stderrが拒否理由として扱われる。通常のpolicy判定と捕捉済みの内部例外は、構造化されたdeny JSONをexit code 0で返す。exit code 2は、判定JSONを返せないプロセスレベルのfail-safe経路として使用する。exit code 1やその他の異常終了をdenyの代用にしない。

### 9.5 登録方法

HookはProject settingsの`.claude/settings.json`へ登録する。現行の公式例で確認できる`command`文字列を使用し、同期実行、timeout 5秒とする。独立した`args` fieldも現行referenceには存在するが、本プロジェクトでは必須要件とせず、初期版は次の単純な登録形を採用する。

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash|WebFetch",
        "hooks": [
          {
            "type": "command",
            "command": "python3 \"$CLAUDE_PROJECT_DIR/.claude/hooks/pre_tool_use.py\"",
            "timeout": 5
          }
        ]
      }
    ]
  }
}
```

`$CLAUDE_PROJECT_DIR`を`command`文字列内で使用する形は公式例で確認でき、`timeout`の単位は秒である。権限判定Hookを`async`にしない。実装時点でも公式Schemaを再確認し、使い捨て環境で登録、変数展開、script起動、stdin JSON受信を確認する。

### 9.6 実装言語・配置・責務制限・回帰test

PreToolUse Hookは、Python 3.10以上の標準ライブラリだけで実装する。外部packageや追加のComposer・npm依存は導入しない。Claude Codeを起動するWSL hostで`command -v python3`と`python3 --version`を確認する。

実装方針は次のとおりとする。

- 実装言語：Python 3.10以上
- 依存関係：Python標準ライブラリのみ
- 配置先：`.claude/hooks/`
- 設定先：`.claude/settings.json`
- 回帰test配置先：`.claude/hooks/tests/test_pre_tool_use.py`
- 回帰test実行command：`python3 -m unittest discover -s .claude/hooks/tests -p "test_*.py"`
- 回帰testの実行主体：人間
- 回帰testの実行時期：Hook実装時、PR作成前、Claude Code更新後、GitHub CLI更新後
- 配布方法：Hook、settings、test、関連文書をrepositoryへcommitし、Project共通設定として共有する
- test実行時は実token、実`.env`、本番repository、本番URLを使用せず、合成dummy入力と使い捨て環境を使用する
- stdinのJSONだけを基本の判定材料とする。`GH_REPO`・`GH_HOST`は`gh` commandを分類するときだけHook実行環境での存在有無を確認し、stdoutには判定JSONだけを出力する
- URL、prompt、入力JSON、環境変数値を保存せず、ログファイルを作成しない

Hook内では次を行わない。

- repository内ファイル、transcript、`.env`の読み取り
- HTTP通信とredirect追跡
- subprocess、Git、GitHub CLIの実行
- 高度なshell parserと高度な秘密情報検出

想定構成は次のとおりである。

```text
.claude/
├── settings.json
└── hooks/
    ├── pre_tool_use.py
    ├── README.md
    └── tests/
        └── test_pre_tool_use.py
```

PythonによるHook実装は小さな権限判定器に限定し、アプリケーション本体の実装言語やLaravelの処理系へ組み込まない。HookはClaude Codeのpermissionsとは別にユーザー権限で実行されるため、上記の責務制限を回帰testとコードレビューで確認する。

### 9.7 失敗時の扱い

malformed JSON、必須field不足、型不正、未知の`tool_name`、`tool_input`内の未知field、Bashの`run_in_background: true`、捕捉済みの予期しない内部例外はaskへ逃がさずdenyする。内部例外時は入力内容を含めず、固定された短い理由のdeny JSONだけを返す。exit code 2を使用する経路でも、stderrには入力内容を含めず固定理由だけを出力する。

ただし、Hookプロセス自体の起動失敗、異常終了、timeout等はすべて自動denyされると仮定しない。現行公式仕様では、exit code 2以外の異常終了はPreToolUseで通常非ブロッキングであり、実装時点の公式仕様と使い捨て環境で挙動を確認する。この経路をHookだけでfail-closedにすることはできないため、次を維持する。

- 既知の変更系・秘密情報・外部通信denyをpermissionsにも残す。
- GitとGitHub CLIの自動allowは初期版では0件とし、定義した閲覧形も人間が確認するaskにする。
- WebFetchの自動allowも初期版では0件とし、公式hostの安全候補も人間が確認するaskにする。
- Hook error通知を確認したら、そのセッションではBashとWebFetchを承認・実行しない。
- bare `Bash` askとbare `WebFetch` denyへ戻すフォールバックを用意する。
- Hookは同期実行とし、非同期Hookを権限判定に使用しない。

## 10. Bash設計

### 10.1 基本方針

一般Bashもclosed worldの小さなallowlist方式で扱い、自動allowは0件とする。判定順序は次のとおりである。

1. 明示的なdeny条件に一致したらdenyする。
2. 本文で定義したcanonicalなask形に完全一致したらaskにする。
3. それ以外はdenyする。

「人間が確認できる」等の抽象条件だけでaskへ送らない。wrapper、alias、追加option、option順変更、複数path、glob、複合化、環境変数prefixへ許可形を拡張しない。askは安全判定ではなく、人間がその回のコマンド全体を最終確認するための状態である。

### 10.2 一般Bashのcanonicalなask形

| 分類 | canonicalなask形 | 主な制約 |
|---|---|---|
| 現在位置 | `pwd` | 引数なし |
| 一覧 | `ls`、`ls -la`、`ls <path>`、`ls -la <path>` | repository内の単一相対path。秘密情報path、絶対path、`..`、未知optionは禁止 |
| 先頭・末尾表示 | `head -n <1〜200> <path>`、`tail -n <1〜200> <path>` | 行数必須、単一相対path |
| 検索 | `grep <単純な1語> <path>`、`grep -n <単純な1語> <path>` | 単一相対path、追加option・空白を含む検索語は禁止 |
| file検索 | `find <path> -type f`、`find <path> -name "<単純pattern>"`、`find <path> -type f -name "<単純pattern>"` | repository内path。`-delete`、`-exec`、`-execdir`、`-ok`、`-okdir`と未知式は禁止 |
| 件数 | `wc -l <path>`、`wc -w <path>`、`wc -c <path>` | 単一相対path。globと複数pathは禁止 |
| 範囲表示 | `sed -n '<start>,<end>p' <path>` | 正の整数、`end >= start`、最大200行。`-i`、`e`、`w`、未知scriptは禁止 |
| 文字列表示 | `echo <単純なliteral>` | `-e`、`-n`、展開、秘密情報らしき文字列は禁止 |
| command確認 | `command -v python3`、`command -v git`、`command -v gh`、`command -v php` | 完全一致 |
| Hook test | `python3 -m unittest discover -s .claude/hooks/tests -p "test_*.py"` | 完全一致。任意scriptや`python3 -c`は禁止 |
| version | `php -v`、`python3 --version`、`git --version`、`gh --version`、`composer --version`、`npm --version`、`node --version`、`php artisan --version`、`php -m`、`vendor/bin/phpunit --version`、`vendor/bin/phpstan --version`、`./vendor/bin/sail php ./vendor/bin/pint --version` | 各完全一致 |
| 依存確認 | `composer show`、`composer show <単一package>`、`npm list`、`npm list <単一package>` | option追加と変更系commandは禁止 |
| Laravel情報 | `php artisan about`、`php artisan route:list`、`./vendor/bin/sail artisan route:list` | 各完全一致 |

検索語、package名、patternはshell展開やoption解釈を起こさない単純な1 tokenに限定する。pathは§15の共通path規則で検証する。

品質確認のcanonical形は§17で定義する。対象限定PHPUnit、対象限定PHPStan、`--test`付き対象限定Pintも自動allowせずaskにする。

### 10.3 一般Bashのdeny

次を明示denyする。

- `cat`、`awk`、`sort`、`uniq`、`tree`、`basename`、`dirname`、`realpath`、`printf`、`test`、`[`、`stat`、`du`、`which`
- `python`、登録形以外の`python3`、`python3 -c`、`php -r`、`node -e`
- `curl`、`wget`、`ssh`、`scp`、`rsync`
- 通常Pint、`--test`なしPint、変更系Artisan、migration、seed、cache変更、queue実行
- Composerの`install`、`update`、`require`、`remove`、npmの`install`、`update`、`uninstall`、Vite build
- `touch`、`mkdir`、`rmdir`、`rm`、`mv`、`cp`、`dd`、`truncate`、`tee`、`sed -i`
- file作成・変更・削除、外部通信、秘密情報参照、未登録または安全に分類できない一般Bash

### 10.4 Bash Hookの処理順

1. 入力JSONと`tool_input.command`の存在・型を検証する。
2. `run_in_background: true`、NUL、想定外の制御文字、コマンド置換を検出したらdenyする。
3. redirect、pipe、複合演算子、改行、環境変数prefixを検出したらdenyする。
4. `git`なら§13、`gh`なら§14のclosed world規則でaskまたはdenyを決定する。`GH_REPO`・`GH_HOST`は`gh`判定時だけ検査する。
5. 一般Bashの明示denyに一致したらdenyする。
6. §10.2または§17のcanonical形に完全一致したらaskにする。
7. それ以外はdenyする。

### 10.5 文字列の扱い

完全一致判定前に、shellとして意味を変える正規化を行わない。余分な空白、quote、escape、option順序、aliasは別コマンドとして扱う。Unicodeの類似文字や制御文字も許可形へ正規化せずdenyする。

## 11. 複合コマンド設計

permissionsは複合コマンドをサブコマンドへ分解して評価する場合があるため、Bash Hookでraw command全体を追加検査する。初期版はcanonicalな単一commandだけをask候補とし、複合形はdenyする。

| 記号・形式 | 判定 | 理由 |
|---|---|---|
| <code>&#124;</code>、<code>&#124;&amp;</code> | deny | pipe先を安全に分類しない |
| <code>&#124;&#124;</code>、`&&`、`;`、`&` | deny | 複数command・backgroundをcanonical形に含めない |
| `>`、`>>` | deny | ファイル作成、上書き、追記につながるoutput redirect |
| `<`、`<<` | deny | 高度なparserを実装せず、入力元とheredocを初期版では安全に分類しない |
| バッククォート | deny | コマンド置換 |
| `$()` | deny | コマンド置換 |
| 改行 | deny | 複数commandへ展開され得る |
| 環境変数プレフィックス | deny | canonical形と一致しない |

ask候補を複合化した場合も同じask形として扱わない。想定外の制御文字もdenyする。

quote内外の演算子を区別する必要があるが、高度なshell parserは実装しない。Python標準ライブラリによる限定的な解析と保守的な判定を使用し、単純な正規表現や`shlex`だけで完全なBash解析を保証しない。安全に分類できない入力はdenyする。

## 12. WebSearch / WebFetch設計

### 12.1 WebSearch

`WebSearch`はbare denyを維持する。検索結果に一般サイトや非公式情報が混入するため、このプロジェクトのClaude Codeレビューでは使用しない。

### 12.2 WebFetch

WebFetchは公式一次情報の確認だけに使用する。

Hookは公式Schemaの`url`と`prompt`を分けて検査する。高度な秘密情報検出、HTTP通信、redirect追跡は行わない。

判定順は次のとおりとする。

1. 入力構造、field、型を検査する。malformed URL、未知または不正な入力構造はdenyする。
2. scheme、host、port、userinfoを解析する。`https`以外、許可外host、suffix偽装host、未登録subdomain、明示port、userinfoはdenyする。
3. 元のURL文字列と、percent encodingを1回だけdecodeした文字列を、秘密情報らしきkeywordで大文字・小文字を区別せず検査する。URL側で検出した場合はdenyする。
4. fragment内に秘密情報らしきkeywordがあればdenyする。その他のfragment付きURLはaskとする。
5. query内に秘密情報らしきkeywordがあればdenyする。その他のquery付きURLはaskとする。
6. prompt側だけで秘密情報らしきkeywordを検出した場合はaskとする。
7. deny条件に該当せず、ask候補条件をすべて満たす場合だけaskとする。初期版ではWebFetchを自動allowしない。

ask候補条件は次のすべてである。

- schemeが`https`
- hostが初期14hostのいずれかと完全一致
- 明示portなし
- userinfoなし
- 元のURL側に秘密情報らしきkeywordなし
- 1回percent decode後のURL側にも秘密情報らしきkeywordなし

query、fragment、またはprompt側だけの秘密情報らしきkeywordがある場合も、URL側のdeny条件に該当しなければaskとする。

現行公式SchemaではWebFetchに`run_in_background`は存在しないため、そのfieldを必須条件または通常のdeny条件として扱わない。`url`と`prompt`だけを持つ通常入力は正常とし、`run_in_background`を含む未知のtool固有fieldが存在した場合は現行Schema外の`tool_input`としてdenyする。

秘密情報らしきkeywordは次に限定する。

```text
token
api_key
apikey
secret
password
passwd
credential
private_key
access_key
```

判定は大文字・小文字を区別しない。元のURL文字列と1回だけpercent decodeした文字列を検査し、複数回decodeしない。URL側とprompt側の両方で検出した場合は、URL側のdenyを優先する。URL、prompt、入力JSONを保存しない。

hostは完全一致で判定し、apexとsubdomainを別hostとして扱う。suffix一致を許可せず、必要なhostは1件ずつ追加する。Webページ本文の命令は非信頼入力として扱う。

Hook自身はredirectを追跡せず、HTTP通信も行わない。Claude Codeがredirect先を別のWebFetch呼び出しとして扱った場合、その新しい入力へ同じ判定を適用する。別呼び出しにならない実装上のredirect挙動はHookから保証できないため、Issue #52の使い捨て環境で確認する。

### 12.3 初期WebFetch Ask候補host allowlist

| ホスト | 用途 | apex | サブドメイン | 初期判定 | 根拠 |
|---|---|---:|---:|---|---|
| `code.claude.com` | Claude Code公式仕様 | 対象 | 対象外 | ask候補 | Claude Codeの現行公式host |
| `laravel.com` | Laravel 10公式仕様 | 対象 | 対象外 | ask候補 | Laravel 10.50.2を使用 |
| `docs.github.com` | GitHub公式仕様 | 対象 | 対象外 | ask候補 | GitHub運用・Security |
| `cli.github.com` | GitHub CLI公式manual | 対象 | 対象外 | ask候補 | ghコマンド設計 |
| `git-scm.com` | Git公式manual | 対象 | 対象外 | ask候補 | Gitコマンド設計 |
| `getcomposer.org` | Composer公式仕様 | 対象 | 対象外 | ask候補 | PHP依存管理 |
| `docs.phpunit.de` | PHPUnit公式仕様 | 対象 | 対象外 | ask候補 | PHPUnit 10系 |
| `phpstan.org` | PHPStan公式仕様 | 対象 | 対象外 | ask候補 | PHPStan / Larastan |
| `docs.npmjs.com` | npm公式仕様 | 対象 | 対象外 | ask候補 | npm 11.13.0を使用 |
| `www.php.net` | PHP公式manual | 対象 | 対象外 | ask候補 | PHP 8.2.30を使用 |
| `v3.tailwindcss.com` | Tailwind CSS 3公式docs | 対象 | 対象外 | ask候補 | Tailwind CSS 3.4.19を使用 |
| `vite.dev` | Vite公式docs | 対象 | 対象外 | ask候補 | Vite 5.4.21を使用 |
| `nodejs.org` | Node.js公式docs | 対象 | 対象外 | ask候補 | Node.js 24.15.0を使用 |
| `www.xserver.ne.jp` | XServer公式情報 | 対象 | 対象外 | ask候補 | 本番環境としてXServerを使用 |

初期導入では、上表の14hostをWebFetchのask候補を限定するallowlistとして使用する。自動allowは行わない。各hostは、プロジェクトで使用する技術または運用サービスの公式一次情報であることを、プロジェクト資料または実行環境の実測値で確認済みである。

`*.example.com`のような包括的サブドメインallowは初期導入しない。必要なhostを1件ずつ追加する。

### 12.4 Ask候補host allowlistへhostを追加する条件

初期導入後にhostを追加する場合は、次の条件をすべて満たすこと。

1. プロジェクトで実際に使用する技術、依存関係、開発ツール、GitHub運用、または本番運用サービスに直接関係すること。
2. 追加対象が公式一次情報を提供するhostであること。
3. プロジェクト資料、lock file、package managerの一覧、または実行環境のversion出力で、対象技術とversionを確認できること。
4. apexとsubdomainを区別し、必要なhostだけを完全一致で追加すること。
5. 追加理由、確認したversion、公式URL、確認日を設計書または関連Issueへ記録すること。
6. permissionとWebFetch Hookの回帰testを実施してから有効化すること。

2026年7月31日時点で実測確認した主なversionは次のとおりである。

| 技術 | version | 対応する公式host |
|---|---:|---|
| PHP | 8.2.30 | `www.php.net` |
| Laravel | 10.50.2 | `laravel.com` |
| Node.js | 24.15.0 | `nodejs.org` |
| npm | 11.13.0 | `docs.npmjs.com` |
| Tailwind CSS | 3.4.19 | `v3.tailwindcss.com` |
| Vite | 5.4.21 | `vite.dev` |
| XServer | 本番環境として使用予定 | `www.xserver.ne.jp` |

## 13. Git設計

### 13.1 allow / ask / deny候補

| コマンド | allow / ask / deny | 完全一致の要否 | 主な危険な派生形 | 根拠 |
|---|---|---:|---|---|
| `git status --short` | ask候補 | 必須 | pathspec、別option、複合形、pager・設定由来の処理 | 作業状態の短縮表示 |
| `git branch --show-current` | ask候補 | 必須 | `git branch <name>`はbranch作成、pager・設定由来の処理 | 現在branch名の表示 |
| `git branch -a` | ask候補 | 必須 | 作成、削除、move、copy、pager・設定由来の処理 | local/remote branch一覧 |
| `git diff` | ask候補 | 必須 | revision・option・path追加、複合形 | ユーザーが全差分レビューを明示した場合 |
| `git diff --check` | ask候補 | 必須 | 外部diff driver、textconv、追加option | 外部helper等の影響を人間が確認する |
| `git diff -- <path>` | ask候補 | 必須 | path不足、repository外、追加option、複合形 | 指定pathのunstaged差分 |
| `git diff --cached -- <path>` | ask候補 | 必須 | path不足、revision・option変更、repository外、複合形 | 指定pathのstaged差分 |
| `git diff HEAD -- <path>` | ask候補 | 必須 | path不足、revision・option変更、repository外、複合形 | 指定pathのHEADとの差分 |
| `git log --oneline -n <1〜50>` | ask候補 | 必須 | 件数省略、0、負数、51以上、追加option、format変更 | 限定した履歴確認 |
| `git grep <単純な1語> -- <path>` | ask候補 | 必須 | 空白・regex option・複数path・秘密情報path | 限定した追跡対象検索 |
| その他の`git` | deny候補 | - | 変更・通信・設定変更・未登録閲覧 | closed world |

初期版のGit自動allowは0件とし、上表の10形はすべてaskとする。Gitはpagerや設定由来で外部プロセスを起動する可能性があり、今回pager対策まで実装するとIssue群の完了が遅れるためである。pager対策と自動allow化は後続Issueで検討する。

pathを取るGit commandの`<path>`は、ユーザーが明示したrepository内の単一相対pathだけを受理する。path不足、絶対path、`..`、glob、Git pathspec magic、追加option、revision変更、複合化はdenyする。`git grep`の検索語は空白のない単純な1語に限定し、regex optionを許可しない。`git log`は1〜50の件数を必須とし、追加optionとformat変更を許可しない。`git show`は初期版へ追加しない。

`git diff --check`は、`diff.external`、外部diff driver、textconv等の影響を考慮し、自動allowにせずaskとする。

### 13.2 deny

Gitはclosed worldで扱う。上記10形のcanonicalなask形以外は、読み取り形を含めてすべてdenyする。

現行の変更・通信系denyを維持し、少なくとも次をdeny対象とする。

- switch、checkout
- pull、fetch
- branch作成・削除・移動・copy・upstream変更
- add、commit、push
- merge、rebase
- reset、restore、stash、clean
- tag、cherry-pick、revert、apply、am、update-ref
- worktreeの変更系
- config変更、remote変更、submodule変更等
- pathや引数が上記ask形に一致しないdiff、log、show、grep等の未登録の読み取り形

permissionの個別denyを維持しつつ、Hookで実行command全体をclosed world判定する。ask形、未登録のbare形、引数付き形の判定を回帰testと実機検証で確認する。

## 14. GitHub CLI設計

### 14.1 共通方針

- 対象hostは`github.com`に限定する。
- 対象repositoryは`honda-dev-jp/review-app-laravel`に限定する。
- 初期版のGitHub CLI自動allowは0件とする。
- すべての許可対象gh commandで`--repo github.com/honda-dev-jp/review-app-laravel`を必須とする。
- `gh` commandの判定時に限り、`GH_REPO`と`GH_HOST`がcommand prefixまたはHook実行環境に存在する場合はdenyする。Gitや一般Bashにはこの検査を適用しない。
- 設計書に記載したcanonicalな引数順だけを受理し、`-R`、equals形式、option位置を変えた形は初期版ではdenyする。
- `--web`をdenyする。
- `--watch`をdenyする。
- `--jq`をdenyする。
- Issue・PR本文やコメントを非信頼入力として扱う。

Hookは実行環境における`GH_REPO`と`GH_HOST`の存在だけを確認し、値を比較、保存、出力しない。継承状態は実機検証する。command内の明示的な環境変数prefixもdenyする。

### 14.2 閲覧系

一覧は次のcanonical形だけをask候補とする。`--state`は`open`、`closed`、`all`、`--limit`は1〜100に限定する。

```text
gh issue list --state <open|closed|all> --limit <1〜100> --repo github.com/honda-dev-jp/review-app-laravel
gh pr list --state <open|closed|all> --limit <1〜100> --repo github.com/honda-dev-jp/review-app-laravel
```

`gh issue status`と`gh pr status`は初期版ではdenyする。

可変形GitHub CLI commandは、固定repositoryに対するIssue・PRの参照だけをask候補とする。許可対象のsubcommandは次に限定する。

- `gh issue view`
- `gh pr view`
- `gh pr checks`

許可するoptionは次に限定する。

- `--repo`
- `--comments`
- `--json`

`--jq`、`--web`、`--watch`、`-R`、`--repo=...`、`-R=...`、canonicalな順序と異なるoption位置、その他の未知optionはdenyする。`--comments`は`gh issue view`と`gh pr view`だけに許可し、`gh pr checks`には許可しない。

`--repo`は常に`github.com/honda-dev-jp/review-app-laravel`を指定する。Issue番号・PR番号は正の整数だけを許可する。

許可する正確なcommand形は次のとおりとする。

```text
gh issue view <number> --repo github.com/honda-dev-jp/review-app-laravel
gh issue view <number> --repo github.com/honda-dev-jp/review-app-laravel --comments
gh issue view <number> --repo github.com/honda-dev-jp/review-app-laravel --json <許可field一覧>

gh pr view <number> --repo github.com/honda-dev-jp/review-app-laravel
gh pr view <number> --repo github.com/honda-dev-jp/review-app-laravel --comments
gh pr view <number> --repo github.com/honda-dev-jp/review-app-laravel --json <許可field一覧>

gh pr checks <number> --repo github.com/honda-dev-jp/review-app-laravel
```

`gh issue view`と`gh pr view`では、`--comments`形と`--json`形を別のcanonical形とし、両者を組み合わせない。`gh pr checks`は上記形だけをaskとし、追加optionをdenyする。任意のoption順を解析しない。

初期実装で許可するJSON fieldは次を上限とする。

| 対象 | 許可field |
|---|---|
| Issue | `number`、`title`、`state`、`body`、`comments`、`labels`、`url` |
| PR | `number`、`title`、`state`、`body`、`comments`、`files`、`commits`、`reviews`、`reviewDecision`、`baseRefName`、`headRefName`、`mergeable`、`statusCheckRollup`、`url` |

`--json`のfieldはcomma区切り等、GitHub CLI公式が受理する形を実装時に確認し、上表のfieldだけを許可する。未知fieldはdenyする。

可変閲覧形をaskにする場合も、Hookはrepository、host、Issue・PR番号、許可option、JSON field、禁止記号を先に検査する。安全条件を満たさない入力は承認画面へ送らずdenyする。

GitHub CLIはclosed worldで扱う。上記2つのlist形と、厳密に定義した可変閲覧形をaskとし、それ以外の`gh` commandは読み取り形を含めてすべてdenyする。Issue・PRの作成、編集、コメント投稿、Close、Reopen、Mergeその他の変更系commandもdenyする。

GitHub CLIにも`GH_PAGER`、`PAGER`等の設定由来で外部プロセスが起動する可能性があるため、自動allowしない。pager対策と自動allow化は後続改善Issueで検討する。

### 14.3 変更系・危険系deny

次をdeny対象とする。

- Issue：create、edit、close、reopen、comment、delete、transfer、develop、pin、unpin、lock、unlock
- PR：create、edit、close、reopen、merge、comment、review、ready、checkout、update-branch、revert、lock、unlock
- repo：clone、create、edit、sync、set-default、delete、rename、archive、fork、および出力fileを作るread-file
- release：create、edit、delete、upload
- workflow：run、enable、disable
- run：download、rerun、cancel
- secret、variable：set、delete
- label：create、edit、delete
- key：add、delete
- auth：login、logout、refresh、setup-git、token表示
- config：set
- extension install
- alias set、特に`--shell`
- gist create
- browse
- codespace / cs
- cache delete
- `gh api`

## 15. Read権限と秘密情報保護

現行のRead denyを維持する。

| 対象 | 判定 | 備考 |
|---|---|---|
| `.env` | deny | 内容を表示しない |
| `.env`および秘密情報を含む`.env.*` | deny | `.env.example`はdeny対象へ含めず、必要時のみ確認して参照する |
| `bootstrap/cache/**` | deny | 生成cache |
| `storage/logs/**` | deny | 個人情報・秘密情報の可能性 |
| private storage | deny | 非公開データ |
| sessions / cache / views | deny | session・生成物 |
| SQL dump / backup | deny | 実データ・credentialの可能性 |
| 秘密鍵・token・credential | deny | 秘密情報 |
| API key・password | deny | 秘密情報 |
| 個人情報を含む可能性があるファイル | deny | 不要な個人情報参照を避ける |
| 本番設定ファイル | deny | 本番秘密情報・接続情報の可能性 |

Read/Edit denyは、任意のinterpreterや独自scriptがfileを開く経路を完全には制御しない。そのためBash Hookでもpathを追加検査するが、完全な秘密情報検出を保証しない。

pathを取るcanonical commandには次の共通規則を適用する。

- repository rootを基準とする正規化済みの単一相対pathだけを受理し、絶対path、`..`、NUL、glob、複数pathをdenyする。
- `.env`と`.env.*`をdenyする。ただしpath全体が秘密情報を含まない`.env.example`に一致する場合だけ例外候補とする。
- `storage/logs/`、`storage/framework/`、`storage/app/private/`、path componentとしての`sessions`、`cache`、`backup`、`backups`をdenyする。
- suffixが`.pem`、`.key`、`.p12`、`.pfx`、`.sql`、`.sqlite`のfileをdenyする。
- file名またはpath componentが`id_rsa`、`id_ed25519`、`credential`、`credentials`、`token`、`password`、`secret`、`private_key`、`access_key`に一致する場合をdenyする。大小文字は区別しない。

部分文字列だけの一律拒否は誤検知を増やすため、初期版はcomponent・file名・suffixと既知directory prefixを明示比較する。symlink解決や未知の間接経路はpermissionsと人間確認も併用する。

symlinkについては、Claude Codeがlink自身と解決先をpermission判定する現行仕様を前提とするが、実機検証も行う。

## 16. Agent設計

現行のbare `Agent` denyを維持し、サブエージェントを使用しない。

現行公式ツール名は`Agent`である。旧名`Task`は互換aliasとして扱われる仕様があるが、未認識ルールを推測で増やさず、実機の`/permissions`とツール一覧でbare `Agent` denyが有効であることを確認する。

## 17. 品質確認コマンド

品質確認は人間が対象と正確なコマンドを明示した場合だけaskとする。Claude Code側で対象を推測しない。

### 17.1 PHPUnit

対象限定の次の形をask候補とする。

```text
./vendor/bin/sail artisan test <明示されたテストファイル>
```

Hookは対象pathがプロジェクト内のテストであり、`..`、glob、複合記号、追加の変更系optionを含まないことを検査する。全テストの独自判断による実行は許可しない。

### 17.2 PHPStan

対象限定の次の形をask候補とする。

```text
./vendor/bin/sail php ./vendor/bin/phpstan analyse <明示された対象パス>
```

対象pathとoptionを人間が確認する。設定生成や出力file作成を伴う形は拒否する。

### 17.3 Pint

次の`--test`付き対象限定形をask候補とする。

```text
./vendor/bin/sail php ./vendor/bin/pint <明示された対象パス> --test
```

通常Pint、`--test`欠落、対象path欠落、書き込みoptionを含む形はdenyする。レビュー専用運用の禁止対象を承認画面へ送らず、Hookとpermissionsの統合検証でもdenyになることを確認する。

### 17.4 route:list

次の固定形をask候補とする。

```text
./vendor/bin/sail artisan route:list
```

アプリケーションをbootするため自動allowせず、その都度人間が承認する。追加optionや別Artisanコマンドは別入力として判定する。

## 18. allow / ask / denyの候補一覧

| 対象 | 具体例 | 判定 | 理由 | 備考 |
|---|---|---|---|---|
| 編集ツール | `Edit`、`Write`、`NotebookEdit` | deny維持 | レビュー専用 | bare deny |
| Agent | `Agent` | deny維持 | サブエージェント不要 | bare deny |
| WebSearch | `WebSearch` | deny維持 | 非公式情報混入を避ける | bare deny |
| WebFetch | 初期公式host | ask候補 | 一次情報参照 | 自動allow 0件、Hook通過後も人間確認 |
| WebFetch | query付きAsk候補host | ask候補 | query内容を確認 | 秘密情報らしき値はdeny |
| WebFetch | fragment付きAsk候補host | ask候補 | fragment内容を確認 | 秘密情報keywordはdeny |
| WebFetch | promptだけに秘密情報keyword | ask候補 | 公式用語の調査を一律拒否しない | URL側にもあればdeny |
| WebFetch | 許可外host | deny候補 | 外部通信の限定 | Hookでhost検証 |
| Git固定形 | status、branch表示の3件 | ask候補 | pager・設定由来の外部processを人間が確認 | canonicalな完全一致形のみ |
| Git固定形 | `git diff --check` | ask候補 | 外部helper等の影響 | 自動allowしない |
| Git path限定差分 | `git diff -- <path>`等の3形 | ask候補 | PR差分レビューに必要 | repository内path必須 |
| Git追加閲覧形 | `git diff`、限定`git log`、限定`git grep` | ask候補 | レビュー運用に必要 | 範囲・件数・pathを限定 |
| 未登録Git | show、変更・通信系等 | deny候補 | closed world | 読み取り形も含む |
| gh一覧形 | state・limit・repo固定のissue/pr list | ask候補 | pager・設定由来の外部processを人間が確認 | statusはdeny |
| gh可変形 | issue/pr view、checks | ask候補 | 番号・optionが可変 | repo/hostをHook検査 |
| 未登録gh | 変更系・認証系・未登録閲覧系 | deny候補 | closed world | Hookもdeny |
| Bash複合形 | pipe、論理演算子、separator、改行 | deny候補 | canonicalな単一形だけをaskにする | 高度なparserは実装しない |
| Bash redirect | `>`、`>>`、`<`、`<<` | deny候補 | file変更や安全に分類できない入力を防ぐ | 高度なparserは後続改善 |
| Bash command substitution | バッククォート、`$()` | deny候補 | 隠れたcommand実行 | Hookでraw入力検査 |
| 一般Bash | §10.2のcanonical形 | ask候補 | 人間が内容を確認 | 自動allowしない |
| 未登録一般Bash | §10.2・§17以外 | deny候補 | closed world | 抽象条件でaskにしない |
| 品質確認 | 対象限定テスト等 | ask候補 | 実行範囲を人間確認 | 自動allowしない |
| Read秘密情報 | `.env`、logs、keys等 | deny維持 | 秘密情報保護 | 間接経路は完全防御でない |

## 19. Hookの検査ルール一覧

| 対象ツール | 検査項目 | 非拒否条件 | 拒否条件 | Ask条件 | 検証方法 |
|---|---|---|---|---|---|
| 共通 | JSON・Schema | 必須共通fieldと型が正しく、既知の任意top-level fieldだけを含む | malformed、必須field不足、型不正、未知tool、未知の`tool_input` field | なし | 合成JSONとversion更新時のschema回帰test |
| 共通 | Hook内部例外 | 正常判定 | 固定理由のdeny JSON | なし | 例外を合成してstdoutを試験 |
| Bash | `run_in_background` | falseまたは省略 | true | なし | booleanと型不正を試験 |
| Bash | 定義済みGit・gh | 自動allowなし | 未登録Git・gh、deny対象 | canonicalな定義済み形 | 末尾空白・quote・option差を試験 |
| Bash | 複合演算子 | なし | <code>&#124;</code>、<code>&#124;&#124;</code>、<code>&#124;&amp;</code>、`&&`、`;`、`&`、改行 | なし | 各記号とquote内外を試験 |
| Bash | redirect | なし | `>`、`>>`、`<`、`<<`、安全に分類できないredirect | なし | 空白有無とfile descriptor形を試験 |
| Bash | command substitution | なし | バッククォート、`$()` | なし | nested形を試験 |
| Bash | 改行・制御文字 | 1行、NULなし | 改行、NUL、想定外の制御文字 | なし | CRLF・LF・制御文字を試験 |
| Bash | 環境変数prefix | 付与なし | prefixあり | なし | quoted valueを含めて試験 |
| Bash / gh | `GH_REPO`・`GH_HOST` | gh判定時にcommandとHook環境の双方で存在しない | gh判定時にいずれかに存在 | なし | Git・一般Bashには適用しないことも試験 |
| Bash / gh | repository・host | 固定対象と一致 | 不一致、`--repo`不足、`-R`、equals形、非canonicalなoption順 | 定義済みcanonical閲覧形 | option位置変更がdenyになることを試験 |
| Bash / gh | option・JSON field | 許可optionとfieldだけ | `--jq`、`--web`、`--watch`、未知option・field | 定義済み可変閲覧形 | option・fieldの組合せを試験 |
| Bash / Git | subcommand | 自動allowなし | 定義済み10形以外のGit | §13.1のcanonical形 | 件数、検索語、path、option差を試験 |
| Bash | 一般command | 自動allowなし | 明示deny、未登録、非canonical入力 | §10.2と§17のcanonical形 | 各境界値と1 token差を試験 |
| WebFetch | URL scheme | なし | `http`、その他scheme | `https`かつ他のdeny条件なし | scheme大小・encodeを試験 |
| WebFetch | `tool_input` | `url`と`prompt`だけで通常条件を満たす | 未知field（`run_in_background`を含む） | なし | 現行schemaと未知fieldを試験 |
| WebFetch | host | なし | 許可外、suffix偽装、未登録subdomain | 初期14host完全一致 | apex・subdomain・末尾dotを試験 |
| WebFetch | port・userinfo | なし | 明示port、userinfoあり | 明示portなし、userinfoなし | 443を含む明示portを試験 |
| WebFetch | query・fragment | 両方なし | keywordを含むURL側 | keywordなしのqueryまたはfragment | 合成dummy値だけで試験 |
| WebFetch | URL keyword | なし | 元URLまたは1回decode後にkeywordあり | 両方にkeywordなし | 大小文字・percent encodeを試験 |
| WebFetch | prompt keyword | なし | URL側にもkeywordあり | keywordなし、またはprompt側だけにkeywordあり | 合成promptで試験 |
| WebFetch | redirect | 新しい呼び出しが通常条件を満たす | 新しい呼び出しがdeny条件 | 新しい呼び出しがask条件 | Issue #52の隔離環境で試験 |

## 20. 実機検証計画

危険な検証は本プロジェクト、本番環境、本番GitHub repositoryでは行わない。隔離した使い捨てdirectory、使い捨てGit repository、専用GitHub test repositoryを使用する。実データ、実token、`.env`を検証材料にしない。

### 20.1 起動・モード・設定ソース

1. Claude Codeを完全に再起動する。
2. workspace trust画面でProject allowを確認する。
3. `/status`でcwd、version、Setting sources、設定errorを確認する。
4. `Shift + Tab`でPlan modeへ切り替え、表示が`Plan`であることを確認する。
5. `/permissions`でAllow、Ask、Denyと保存元を確認する。
6. `/hooks`でmatcher、command、timeout、登録元を確認する。
7. Project、User、Local、Managed settingsを確認し、想定外のHookがないことを確認する。
8. `.claude/settings.local.json`へ意図しない恒久allowがないことを確認する。
9. `Always allow`を使用しない。

### 20.2 BashとGit

- Git・ghの自動allowが0件であること
- 固定Git 3件がcanonicalな形でaskになること
- 引数なし`git diff`がaskになること
- `git diff --check`がaskになること
- `git diff -- <path>`、`git diff --cached -- <path>`、`git diff HEAD -- <path>`がrepository内の明示pathに限りaskになること
- path不足、絶対path、`..`によるrepository外path、glob、Git pathspec magic、option追加、revision変更、複合化、非canonicalな引数順がdenyになること
- `git log --oneline -n <1〜50>`と単純な`git grep`がaskになり、境界外件数、空白を含む検索語、追加option、複数pathがdenyになること
- 未登録Gitが読み取り形を含めてdenyになること
- §10.2と§17の一般Bash canonical形がaskになり、未登録一般Bashがdenyになること
- `head`、`tail`、`sed -n`の200行境界、`find`の危険option、秘密情報pathを確認すること
- Hook回帰testの完全一致commandがaskになり、任意Python実行がdenyになること
- 環境変数prefix付きcommandがdenyになること
- `|`、`||`、`|&`、`&&`、`;`、`&`がdenyになること
- `>`、`>>`、`<`、`<<`がdenyになること
- バッククォート、`$()`がdenyになること
- 改行がdenyになること
- 想定外の制御文字がdenyになること
- 複合コマンド内の未登録Git・ghと明示deny対象がdenyになること
- Git変更系のbare形と引数付き形がclosed worldによりdenyになること
- 代表的な組み込み読み取り専用BashでもPreToolUse Hookが発火することを確認する

### 20.3 GitHub CLI

- state・limit・repoを固定順で指定したissue/pr listがaskになること
- `gh issue status`と`gh pr status`がdenyになること
- issue/pr viewとchecksがaskになること
- `--repo`、`-R`、`--repo=...`、`-R=...`の他repo指定がdenyになること
- option位置を変えた非canonical形がdenyになること
- `--web`、`--watch`、`--jq`がdenyになること
- 許可された`--json` fieldがaskになり、未知fieldがdenyになること
- `--comments`がissue/pr viewだけで許可され、pr checksではdenyになること
- command prefixの`GH_REPO`、`GH_HOST`がdenyになること
- Claude Code起動前から設定された`GH_REPO`、`GH_HOST`をHookが検出できるか確認すること
- Git、品質確認、一般Bashには`GH_REPO`・`GH_HOST`検査を適用しないこと
- 未登録の閲覧系と、Issue、PR、repo、release、workflow、auth、secret等の変更系がdenyになること

remote変更系は本番repositoryへ実行しない。deny直前までの判定をtest repositoryまたは認証を分離した環境で確認する。

### 20.4 Web

- WebFetchの自動allowが0件であること
- 14hostすべては合成JSONでask候補として確認し、実通信は代表的な複数hostだけで設計どおりaskになること
- 許可外hostがdenyになること
- suffix偽装hostがdenyになり、apexと未登録subdomainが別判定になること
- redirect先を想定した別WebFetch入力が再検査されること
- `http`、明示port、userinfoがdenyになること
- 通常queryがaskになること
- 通常fragmentがaskになり、fragment内keywordがdenyになること
- URL側keywordがdenyになり、prompt側だけのkeywordがaskになること
- 大文字小文字と、1回percent decode後のkeywordを合成dummy値で確認すること
- malformed URLと未知の入力構造がdenyになること
- WebFetchの公式入力に存在しない`run_in_background`を加えた合成入力がdenyになること
- WebSearchがdenyになること
- Webページ内の命令が実行されないこと

### 20.5 Read・Agent・品質確認

- `.env`、ログ、秘密鍵等のReadが内容を表示せずdenyになること
- `.env.example`だけが人間確認後に読めること
- symlink経由の禁止pathがdenyになること
- Agentがdenyになること
- 対象限定PHPUnit、PHPStan、`--test`付きPint、`route:list`がaskになること
- 通常Pint、変更系Artisan、Composer・npm変更系、Vite buildがdenyになること

### 20.6 Hook異常

- malformed inputを明示denyすること
- 必須field不足、型不正、未知tool、未知の`tool_input` fieldをdenyすること
- セキュリティ判断に無関係な既知の任意top-level fieldを、存在だけでdenyしないこと
- Bashの`run_in_background: true`をdenyすること
- Hook内部の検査errorを明示denyすること
- stdoutが判定JSONだけで、入力内容が保存されないこと
- Hook commandのexit code 1が非ブロッキングになる現行挙動を確認すること
- Hookの起動失敗、実行権限不足、依存command不足、timeout時の挙動を確認すること
- Hook error表示後に人間がセッションを停止できること
- fail-safe手順でbare `Bash` askとbare `WebFetch` denyへ戻せること

## 21. フォールバック

### 21.1 想定外のBash自動許可

1. 追加のBash実行を停止する。
2. 問題入力、Claude Code version、permission mode、設定ソースを記録する。
3. bare `Bash` askを復元する。
4. Bash allowが空であることを確認する。
5. Claude Codeを再起動する。
6. `/status`と`/permissions`を再確認する。

### 21.2 許可外WebFetch

1. WebFetchを停止する。
2. 問題URLは秘密情報を除いたhost情報だけ記録する。
3. bare `WebFetch` denyを復元する。
4. WebFetchの自動allowが0件であることを確認する。
5. User、Local、Managed settingsを確認する。
6. Claude Codeを再起動して再検証する。

### 21.3 Hook error

Hook error、起動失敗、異常終了、timeoutが表示された場合、そのセッションでは追加のBashまたはWebFetchを承認・実行しない。Hook自体がfail-openになり得るため、単に再試行しない。

1. Claude Codeを終了する。
2. `.claude/settings.json`の差分を確認する。
3. Issue #52適用後の場合だけ、Issue #52のpermissions変更を戻す。
4. bare `Bash` askを復元する。
5. bare `WebFetch` denyを復元する。
6. 必要に応じてHook登録を停止する。
7. Claude Codeを再起動する。
8. `/hooks`、`/permissions`、`/status`を確認する。
9. Hook単体testを実行する。
10. `gh`判定の問題なら、人間が`GH_REPO`と`GH_HOST`を必要に応じて解除し、Claude Codeを再起動する。
11. 問題入力、version、設定ソースを秘密情報を含まない形で記録し、原因を修正してから再適用する。

### 21.4 Hook回避入力

1. 該当入力をdenyへ戻し、Git・gh・WebFetchの自動allowが0件であることを確認する。
2. 回避入力を秘密情報を含まない最小再現へ変換する。
3. Hookとpermissionの両方へ回帰testを追加する。
4. 実機検証が完了するまで当該入力をaskまたはdenyの安全側で運用する。

### 21.5 Claude Codeが起動しない場合

1. 既知正常なsettingsへ人間が戻す。
2. JSON構文、schema、Hook path、実行権限を確認する。
3. Hook登録を一時的に外して起動可否を切り分ける。
4. 1変更ずつ再適用する。

### 21.6 PR単位のrevert

- permission、Hook、関連docsの変更をアプリケーション実装と分離する。
- 問題時は人間が当該PRのmerge commitをrevertする。
- `git reset --hard`等の破壊的操作で戻さない。
- revert後はClaude Codeを再起動し、`/status`と`/permissions`を確認する。

## 22. 既知の制約

- permissions、Hook、Plan modeはいずれも単独の完全なセキュリティ境界ではない。
- PreToolUse Hookの一般的な異常終了やtimeoutはfail-openになり得る。
- Bash構文にはquote、escape、wrapper、platform差があり、単純な正規表現だけでは完全に解析できない。
- 高度なshell parserを実装しないため、安全に分類できない入力はdenyする。Hookは一般的なBash互換性を提供するものではない。
- Claude Codeの組み込み読み取り専用判定は設定で一覧を変更できない。
- WebFetchには組み込みの事前承認domainがある。明示的なHook検査を通ることを実機で確認する必要がある。
- bare `WebFetch` denyを維持するIssue #51では、実WebFetch tool callを使った統合確認を行えない。合成JSONによるHook単体testと登録確認に限定する。
- Read denyは任意のサブプロセスによる間接Readを完全には防がない。
- `GH_REPO`と`GH_HOST`の継承状態はClaude Codeの起動環境に依存するため実機確認が必要である。
- Gitの表示系コマンドでも内部cache、pager、helper、外部diff等の副作用を完全には排除できない。
- Claude CodeやGitHub CLIのversion更新後はpermission matchingとHook入力schemaを再検証する必要がある。

## 23. 段階的なIssue・PR方針

permission変更とPreToolUse Hook導入は、サブIssue #51と#52、および対応する2つのPRへ分割する。

### 23.1 第1段階：Issue #51 / Hook・test・文書PR

第1PRでは次を追加する。

- Python 3.10以上の標準ライブラリだけで実装したPreToolUse Hook
- Hookの回帰test
- `.claude/hooks/README.md`
- Hookの利用方法と異常時対応を説明する関連文書
- 必要な関連ドキュメント更新
- `.claude/settings.json`へのHook登録
- `CLAUDE.md`、両運用手順、両Skillに記載されたcommandのcanonical化

第1PRではpermissions配列を変更せず、現行のbare `Bash` ask、bare `WebFetch` deny、Allow 0件を維持し、自動allowを有効化しない。ただしHook登録時点からBash・WebFetchの実効判定は変わるため、「既存運用は変わらない」とは扱わない。合成JSONによるHook単体のask、deny、異常系、回帰test、stdout、非保存要件を先に確認し、同じ変更単位で運用文書とSkillを同期する。

bare `WebFetch` denyはツールをClaudeのcontextから除外するため、第1段階では実WebFetch通信を伴う統合検証を行わない。`/hooks`でWebFetch matcherの登録を確認し、WebFetch判定自体は合成JSONで試験する。Bash matcherの実機確認でも、bare `Bash` askによる人間確認を維持する。

WSL hostで`command -v python3`と`python3 --version`を確認し、公式Schema、`$CLAUDE_PROJECT_DIR`展開、Hook起動、stdin JSON受信、Ask・Deny入力での発火、起動失敗・timeout時の挙動を使い捨て環境で確認する。さらに`/pre-implementation-review`と`/pr-diff-review`を各1回実行し、必要commandがaskとなり、不要なdenyで停止しないことを確認する。denyされた場合はHookを先に緩めず、文書・Skillとcanonical形の不一致を確認する。

### 23.2 第2段階：Issue #52 / permissions変更・統合検証PR

第2PRでは、第1PRのHookと回帰testが正常であることを確認した後、次を適用する。

- bare `Bash` askの維持
- Git・GitHub CLIのcanonicalなask
- §10.2と§17の一般Bash canonicalなask
- path限定Git diff 3形のask
- 可変GitHub CLI ask
- WebFetchのAsk候補host allowlistと自動allow 0件
- bare `WebFetch` denyのAsk運用に必要な範囲での変更
- Hookとpermissionsを組み合わせた統合検証
- 代表的な複数hostだけを対象とする実WebFetch通信の使い捨て環境での確認
- 実機検証結果に基づく関連文書更新

第2PRをmergeする前に、`/status`、`/permissions`、`/hooks`と設計書の実機検証計画を使用して、期待どおりのallow、ask、denyになることを確認する。

問題発生時に片方だけrevertできるよう、Hook導入とpermissions変更を同一PRへまとめない。

## 24. 関連ドキュメントの更新対象

Issue #50の後続作業では、実装結果に合わせて次を更新する。

| ファイル | 更新内容 |
|---|---|
| `CLAUDE.md` | Plan mode、WebFetch、Git・ghのask、Hook、Always allow禁止 |
| `.claude/skills/pre-implementation-review/SKILL.md` | 公式WebFetchと新しいBash判定 |
| `.claude/skills/pr-diff-review/SKILL.md` | Git・ghのask、path限定Git diffとHook前提 |
| `docs/CLAUDE_CODE_PRE_IMPLEMENTATION_REVIEW.md` | 起動、Plan mode、承認、実機確認 |
| `docs/CLAUDE_CODE_REVIEW.md` | PR閲覧、Git・ghのask、path限定Git diff |
| `docs/SECURITY.md` | 多層防御と既知の制約 |
| `README.md` | この設計書への導線 |
| `docs/COMMANDS.md` | 人間用コマンドとClaude用権限の区別 |
| `docs/DEVELOPMENT_FLOW.md` | 権限設計書への参照 |

後続更新では、現行のbare `Bash` askを維持し、bare `WebFetch` denyだけを第2段階のAsk運用に必要な範囲で変更する。Git・gh・WebFetchの初期自動allowは0件のまま、canonicalなGit・ghとWebFetchのAsk候補host allowlistをHook前提の運用へ反映する。

恒久許可については、次の表現へ統一する。

> Claude Codeの承認画面から「Always allow」を追加する運用は行わない。Allowルールは`.claude/settings.json`で一元管理する。

## 25. 公式一次情報

確認日：2026年8月1日

### Anthropic

- [Configure permissions](https://code.claude.com/docs/en/permissions)
- [Claude Code settings](https://code.claude.com/docs/en/configuration)
- [Choose a permission mode](https://code.claude.com/docs/en/permission-modes)
- [Hooks reference](https://code.claude.com/docs/en/hooks)
- [Automate actions with hooks](https://code.claude.com/docs/en/hooks-guide)
- [Tools reference](https://code.claude.com/docs/en/tools-reference)
- [Security](https://code.claude.com/docs/en/security)
- [Create custom subagents](https://code.claude.com/docs/en/sub-agents)
- [Claude Code changelog](https://code.claude.com/docs/en/changelog)

### GitHub

- [GitHub CLI manual](https://cli.github.com/manual/)
- [GitHub CLI environment](https://cli.github.com/manual/gh_help_environment)
- [gh issue](https://cli.github.com/manual/gh_issue)
- [gh issue list](https://cli.github.com/manual/gh_issue_list)
- [gh issue status](https://cli.github.com/manual/gh_issue_status)
- [gh issue view](https://cli.github.com/manual/gh_issue_view)
- [gh pr](https://cli.github.com/manual/gh_pr)
- [gh pr list](https://cli.github.com/manual/gh_pr_list)
- [gh pr status](https://cli.github.com/manual/gh_pr_status)
- [gh pr view](https://cli.github.com/manual/gh_pr_view)
- [gh pr checks](https://cli.github.com/manual/gh_pr_checks)
- [GitHub CLI reference](https://cli.github.com/manual/gh_help_reference)

### Git

- [git-status](https://git-scm.com/docs/git-status)
- [git-branch](https://git-scm.com/docs/git-branch)
- [git-diff](https://git-scm.com/docs/git-diff)
- [git-log](https://git-scm.com/docs/git-log)
- [git-grep](https://git-scm.com/docs/git-grep)
- [git-pull](https://git-scm.com/docs/git-pull)
- [git-fetch](https://git-scm.com/docs/git-fetch)
- [git-reset](https://git-scm.com/docs/git-reset)
- [git-stash](https://git-scm.com/docs/git-stash)
- [Git reference](https://git-scm.com/docs)

## 26. 更新履歴

| 日付 | 内容 |
|---|---|
| 2026-07-31 | Issue #50のpermissions、Plan mode、PreToolUse Hook、実機検証、フォールバック設計を新規作成 |
| 2026-07-31 | 初期WebFetch host候補を14hostで確定し、host追加条件と確認済みversionを追記 |
| 2026-07-31 | 可変gh閲覧形、Python 3標準ライブラリによるHook実装、Hook異常時の停止手順、`GH_REPO`・`GH_HOST`検査、query付きURLのask、2段階PR方針を追記 |
| 2026-08-01 | Issue #50・#51・#52の確定内容を反映。Git・ghのclosed world、`git diff --check`のask、`--jq`禁止、複合commandのask/deny、WebFetchのURL・prompt・percent encoding・fragment判定、Hook登録・責務制限、2段階検証を更新 |
| 2026-08-01 | Git・ghの初期自動allowを0件へ変更し、固定7形とpath限定Git diffをaskへ整理。redirect deny、WebFetchの`run_in_background` field前提削除、初期実装の簡素化を反映 |
| 2026-08-01 | 最新Issue #50・#51・#52へ同期。bare `Bash` askの維持、WebFetch自動allow 0件、変更系一般Bashのdeny、代表hostだけの実WebFetch検証を反映し、関連文書への導線を整理 |
| 2026-08-01 | 一般Bashの小規模closed world、Git/gh canonical形、Hookのcommand文字列、Python 3.10以上、`.claude/hooks/tests/`、既存Skill回帰確認へ最終同期 |
| 2026-08-01 | gh viewの`--repo`先行、回帰testの人間実行と4つの実行時期、Issue #51段階を考慮したフォールバックへ同期 |

## 27. 決定事項と実装前提

Issue #50・#51・#52の設計事項は次のとおり確定した。

1. Issue #51でHook、回帰test、Hook README、関連文書を実装し、permissionsは変更しない。Issue #52でpermissionsを変更して統合検証する。
2. Hook matcherは`Bash`と`WebFetch`だけとし、公式例に沿う`command`文字列、timeout 5秒で同期実行する。
3. HookはPython 3.10以上の標準ライブラリだけで実装し、stdin入力以外のfile読取、transcript読取、HTTP通信、redirect追跡、subprocess、Git、gh、ログ保存を行わない。`GH_REPO`・`GH_HOST`の存在確認はgh判定時だけ行う。
4. malformed JSON、必須field不足、型不正、未知tool、未知の`tool_input` field、Bashの`run_in_background: true`、Hook内部例外はdenyする。既知の任意top-level fieldは存在だけでdenyしない。
5. Gitはclosed worldとし、初期自動allowを0件とする。§13.1の10形だけをask候補とし、その他はdenyする。
6. GitHub CLIもclosed worldとし、初期自動allowを0件とする。state・limit・repositoryを固定したissue/pr listと、厳密に定義したissue/pr view、pr checksだけをask候補とする。status、`--jq`、未知option・fieldをdenyする。
7. 一般Bashもclosed worldとし、§10.2と§17のcanonical形だけをaskとする。その他はdenyする。複合command、redirect、command substitution、改行、環境変数prefixもdenyする。
8. Git・GitHub CLI・WebFetchの初期自動allowは0件とする。初期WebFetch Ask候補host allowlistは本文記載の14hostすべてとし、hostを完全一致で判定する。query、fragment、promptだけのkeywordは定義どおりaskとし、URL側keyword、許可外host、明示port、userinfo、malformed URLをdenyする。
9. URLの秘密情報らしきkeywordは元文字列と1回percent decode後を検査し、複数回decodeしない。Hook自身はredirectを追跡しない。
10. Hook error、起動失敗、異常終了、timeoutが表示された場合は追加のBashとWebFetchを承認せず、安全側へ戻してから原因を調査する。
11. 初期実装ではcanonicalな引数順だけを扱い、高度なshell parser、pager対策、URL keywordの誤検知改善、optionalなGitHub CLI field・option、`git show`は後続改善とする。Laravel学習を優先し、Claude Code整備を早期に完了する。

Issue #51では現行のbare `Bash` ask、bare `WebFetch` deny、Allow 0件を維持する。Issue #52でもbare `Bash` askとGit・GitHub CLI・WebFetchの自動allow 0件を維持し、bare `WebFetch` denyだけをAsk運用に必要な範囲で変更する。

実機検証でClaude Codeの現行仕様と異なる挙動が確認された場合は、安全側へ戻し、設計書、Hook、permissions、回帰testを同じ変更単位で更新する。
