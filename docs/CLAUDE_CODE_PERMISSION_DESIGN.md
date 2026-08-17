# Claude Code権限設計

## 1. 目的

この文書は、Claude Codeのpermissions、PreToolUse Hook、専用Skill・helper、および人間による承認の設計を定義する。

目的は、Claude Codeを原則読み取り専用のセカンドオピニオンとして維持しながら、公式一次情報の参照、限定した読み取り系コマンド、およびIssue #88の`.ai-work/`限定保存だけを、Hook、専用helper、人間の承認で安全に制御することである。

本プロジェクトの第一目的はLaravelの学習である。Claude Codeの権限整備は、安全な実装前検証とコードレビューに必要な最小範囲へ限定して早期完了し、pager対策等の追加改善は実運用上の必要性を確認してから後続Issueで扱う。

この文書は技術設計の正本であり、現行設定・実装済み境界と後続候補を区別して記載する。現在有効なpermissionsとHook登録は`.claude/settings.json`、Hook実装は`.claude/hooks/pre_tool_use.py`、限定保存helperの最終validationは`.claude/skills/save-local-artifact/scripts/save_local_artifact.py`を確認する。自動allowは0件とし、未実装の判定だけを「候補」と表記する。

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
- `/save-local-artifact` Skillと専用helperによる`.ai-work/`への限定保存
- 人間による実機検証とフォールバック

次は対象外とする。

- Claude Codeによるアプリケーション実装・修正
- Issue #88の専用Skill・helper以外の新しい自動化機構
- sandboxの導入
- MCP権限の再設計
- Issue・PR・GitHub上のデータを変更する自動化
- 自動的なcommit、push、merge、branch操作

## 3. Claude Codeの役割

Claude Codeは次の用途に限定する。

- 実装前検証
- 設計レビュー
- PR差分レビュー

Claude Codeにはアプリケーション実装、任意のファイル修正、Git変更、Issue・PR変更を行わせない。唯一の限定write exceptionは、ユーザーが`/save-local-artifact`を明示起動した場合の`.ai-work/`への新規テキスト保存である。Claude Codeの出力は補助情報であり、採否、実装開始、保存、承認、マージの最終判断は人間が行う。

## 4. 前提資料と公式一次情報

### 4.1 プロジェクト資料

この設計は次を前提とする。

- `AGENTS.md`
- `CLAUDE.md`
- `README.md`
- `.claude/settings.json`
- `.claude/skills/pre-implementation-review/SKILL.md`
- `.claude/skills/pr-diff-review/SKILL.md`
- `.claude/skills/save-local-artifact/SKILL.md`
- `.claude/skills/save-local-artifact/scripts/save_local_artifact.py`
- `docs/AI_LOCAL_ARTIFACTS.md`
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
- bare `Bash`のaskはBashのpermission rule評価ではすべてのBashに一致し、個別のBash allowより先に評価される。一方、Claude Codeには全permission modeで確認なしに実行される組み込みread-only commandがある。Issue #52の実機確認では、bare `Bash` askが設定されていても`pwd`は確認画面なしで実行されたため、bare `Bash` askが組み込みread-only判定を常に上書きするとは仮定しない。Hookのdenyは引き続き拒否に使用するが、Hookがaskへ分類したread-only commandすべてに人間承認が残るとは扱わない。
- bare `WebFetch`はすべてのWebFetch呼び出しに一致する。denyへ置くとtool自体がClaudeのcontextから除外され、askへ置くとすべての呼び出しが毎回の確認対象になる。
- 複合Bashはサブコマンド単位で評価される。すべての構成要素が許可される場合、複合形も許可され得る。
- PreToolUse Hookの`permissionDecision`には公式上`allow`、`ask`、`deny`、`defer`が存在する。本プロジェクトの初期版では`ask`と`deny`だけを使用し、`allow`と`defer`は使用しない。PreToolUseはpermission promptより先に実行されるが、Hook decisionはpermission ruleを迂回しない。permissionsのdenyはHookのaskやallowより優先し、permission ruleのask評価へ到達した呼び出しはHookがaskまたはallowを返しても確認を表示する。ただし、組み込みread-only Bashがpermission promptへ到達せず実行された実測結果は本節のbare Bash Askに関する記述および§22のとおりである。exit code 2のblocking Hookはpermissionsのallowより優先する。
- PreToolUse Hookのexit code 2は実行を拒否する。Hookの起動失敗、異常終了、timeout時の最終挙動は一律に自動denyと仮定せず、実装時点の公式仕様と使い捨て環境で確認する。
- Hook自身はWebFetchのredirectを追跡できない。Claude Codeがredirect先を別のWebFetch呼び出しとして扱う場合に限り、その新しい入力へ同じHook判定を適用できる。実際の挙動は実機確認事項とする。
- 現行のPreToolUse入力では、Bashの`tool_input`に`command`、任意の`description`、`timeout`、`run_in_background`があり、WebFetchの`tool_input`には`url`と`prompt`がある。WebFetchには`run_in_background`が定義されていない。
- denyとaskでは`Bash(run_in_background:true)`のように、tool入力のtop-level scalar parameterを完全一致で制御できる。初期Hookのdenyに加えてpermissionsにも同ruleを置き、Hook障害時の防御を重ねる。
- permission ruleとHook matcherはUI上の表示名ではなくcanonical tool名へ一致する。未知のtool名をdenyまたはaskへ指定すると起動警告が出るため、推測でtool名を追加せず、Tools referenceと起動警告を照合する。
- ReadとEditのdenyは組み込みツールと認識可能な一部Bash操作へ適用されるが、任意のサブプロセスによる間接アクセスをOSレベルで完全には防がない。
- Project Skillは`.claude/skills/<name>/SKILL.md`に置く。`disable-model-invocation: true`はClaudeによる自動起動を無効化し、`allowed-tools`はSkill実行中のtoolを事前承認する。Issue #88では前者を設定し、後者を省略する。

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

専用helper
  └─ contentとfilesystemの最終境界をfail-closedで検査する

人間の承認
  └─ 最終判断を行う
```

役割分担は次のとおりとする。

| 層 | 主な役割 | 限界 |
|---|---|---|
| Plan mode | 調査・レビュー中心の進行を補助 | BashやReadの権限判定を置き換えない |
| permissions | 既知のツール・コマンドをallow、ask、denyへ分類 | Bashの別表記や間接操作を完全には表現できない |
| PreToolUse Hook | raw入力を実行直前に追加検査 | Hook自体の異常終了は常にfail-closedではない |
| 専用Skill | ユーザー向けの2段階workflowを案内 | Skillからの起動自体は技術的な安全境界ではない |
| 専用helper | 正規化、digest、root、directory、atomic publishを検査 | 保存内容に秘密情報がないことは自動判定しない |
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
| ask | 該当toolを使用するたびに人間へ確認する | bare `Bash`、bare `WebFetch`、canonicalな確認候補 |
| allow | 確認なしで実行できる | 初期版では使用しない |

評価順はdeny、ask、allowである。permission rule評価がaskへ到達する場合、たとえばbare `Bash`をaskへ残したまま`Bash(git status --short)`をallowしても、bare askが先に一致するため確認は省略されない。ただし、Claude Codeの組み込みread-only Bashはbare `Bash` askがあってもpermission promptへ到達せず、確認画面なしで実行される場合がある。詳細は§4.2および§22を参照する。

### 8.2 設定スコープ

設定の優先順位は次のとおりである。

1. Managed settings
2. コマンドライン引数
3. Local project settings：`.claude/settings.local.json`
4. Shared project settings：`.claude/settings.json`
5. User settings：`~/.claude/settings.json`

permissionは複数スコープのルールを合わせて評価し、いずれかのスコープでdenyに一致すれば他のallowでは解除できない。Projectのallowはworkspace trust受諾後に適用される。

Claude Code 2.1.211以降、`.claude/settings.local.json`はsubdirectoryから起動した場合もGit repository rootから読み込まれる。一方、同ファイルに書いた`/path` ruleの基準はrepository rootではなく元のcwdである。また、個人が作成したlocal settingsはworkspace trustの対象外となる場合があるため、ファイルの有無だけでなく`/status`と`/permissions`で実際の設定元と有効ruleを確認する。

### 8.3 正本と恒久許可

- Project共通のallowルールは`.claude/settings.json`で一元管理する。
- 承認画面から`Always allow`、`Yes, and don't ask again`、または同等の恒久許可を追加しない。
- User、Local、Managed settingsも有効な権限へ影響するため、`.claude/settings.json`だけを見て安全と判断しない。
- `/permissions`と`/status`で、実際の設定ソースと有効なルールを確認する。

### 8.4 現行設定と段階的な実装状態

Issue #51ではPreToolUse Hook、45件の回帰test、Hook README、関連文書を追加し、bare `Bash` ask、bare `WebFetch` deny、Allow 0件を維持した。

Issue #52ではbare `WebFetch`だけをdenyからaskへ移し、現行設定を次の状態とした。

- Allow：0件
- Ask：bare `Bash`、bare `WebFetch`、`Read(/.env.*)`、`Read(/**/.env.*)`
- Deny：bare `WebFetch`以外の既存ruleを維持し、WebSearch、Agent、Edit、Write、NotebookEditを引き続きdeny。公式仕様で直接表現できる`Bash(run_in_background:true)`と、既存のIssue変更系denyに欠けていたgh PR変更系denyを追加

Git・GitHub CLI・WebFetchの自動allowは0件を維持する。Issue #52の人間による実機確認では、設定ソースとHook登録、代表hostのWebFetch、未登録subdomainの拒否、bare `WebFetch` denyへのフォールバックとaskへの再適用まで確認済みである。確認済みの具体的な結果は§20に記録する。§20に列挙したその他の境界条件は、個別の確認結果が記録されるまで未確認として扱う。

Issue #88では`.claude/settings.json`を変更しない。Allow 0件、bare `Bash` Ask、Edit・Write・NotebookEdit Deny、`disableSkillShellExecution: true`、`Bash|WebFetch` matcherを維持したまま、Hookで専用helperの2形だけを一般`python3` Denyより前のAsk候補にできるためである。Skillに`allowed-tools`を設定せず、preflightとsaveのBashを毎回通常の承認対象とする。

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

PreToolUse Hookはpermission promptより先に実行されるが、Hook decisionはpermission ruleを迂回しない。permissionsのdenyはHookのaskやallowより優先し、permission ruleのask評価へ到達した呼び出しはHookがaskまたはallowを返しても確認を表示する。ただし、組み込みread-only Bashでは確認画面へ到達しない実測結果がある。exit code 2のblocking Hookはpermissionsのallowより優先する。本プロジェクトの初期Hookはexit code 2を使用せず、exit code 0の構造化JSONでdenyを返すため、将来permissionsへallowを追加する場合も、その構造化denyがallowを上書きすると仮定しない。現時点ではAllow 0件を維持する。

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

exit code 2はPreToolUseをブロックし、stderrがClaudeへ拒否理由として返される。初期実装ではこの経路を使用せず、通常のpolicy判定、入力不正、捕捉済みの内部例外を、固定理由を持つ構造化deny JSONとしてexit code 0で返す。起動失敗、捕捉不能な異常終了、timeoutはHook自身から制御できず、一律に自動denyされると仮定しない。

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

GitHub Actions CIではPython 3.12単独で回帰testを実行する。これは実行要件であるPython 3.10以上の全versionをCIで保証するものではない。

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

- repository内ファイル、transcript、`.env`、`.ai-work/`の読み取り
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

malformed JSON、必須field不足、型不正、未知の`tool_name`、`tool_input`内の未知field、Bashの`run_in_background: true`、捕捉済みの予期しない内部例外はaskへ逃がさずdenyする。内部例外時は入力内容を含めず、固定された短い理由のdeny JSONだけを返す。初期実装ではexit code 2の別経路を持たない。

ただし、Hookプロセス自体の起動失敗、異常終了、timeout等はすべて自動denyされると仮定しない。現行公式仕様では、exit code 2以外の異常終了はPreToolUseで通常非ブロッキングであり、実装時点の公式仕様と使い捨て環境で挙動を確認する。この経路をHookだけでfail-closedにすることはできないため、次を維持する。

- 既知の変更系・秘密情報・外部通信denyをpermissionsにも残す。
- GitとGitHub CLIの自動allowは初期版では0件とし、定義した閲覧形も人間が確認するaskにする。
- WebFetchの自動allowも初期版では0件とし、公式hostの安全候補も人間が確認するaskにする。
- Hook error通知を確認したら、そのセッションではBashとWebFetchを承認・実行しない。
- bare `Bash` askを維持し、問題時にbare `WebFetch`をaskからdenyへ戻すフォールバックを用意する。
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
5. 一般command判定では、Issue #88のsave-local-artifact専用canonical形、既存の専用Python helperの順に、一般`python3` Denyより前で評価する。
6. §10.2または§17の固定形に完全一致したらaskにする。
7. 一般Bashの明示denyと一般`python3`に一致したらdenyする。
8. 残りのpath付き一般commandをclosed worldで評価し、それ以外はdenyする。

### 10.5 文字列の扱い

完全一致判定前に、shellとして意味を変える正規化を行わない。余分な空白、quote、escape、option順序、aliasは別コマンドとして扱う。Unicodeの類似文字や制御文字も許可形へ正規化せずdenyする。

### 10.6 `.ai-work/`限定保存

#### Skillと人間確認

Project Skillは`.claude/skills/save-local-artifact/SKILL.md`に置き、nameを`save-local-artifact`、`disable-model-invocation: true`とする。副作用を持つworkflowをClaudeに自動起動させず、ユーザーの`/save-local-artifact`明示起動だけを運用上の入口にする。`allowed-tools`は設定しない。Skill起動でtool permissionを事前承認せず、preflightとsaveをそれぞれ通常のBashとしてHook検査と人間承認へ進めるためである。

Skill provenanceは安全境界ではない。Skill外から同じcanonical commandが提示されてもHookでAskとなり、helperが同じvalidationを行う。既存の`/pre-implementation-review`と`/pr-diff-review`は従来どおり結果をチャットへ返し、保存Skillを自動起動しない。

Skillは保存目的、category、filename、本文を確認してから、必ずpreflightを先に実行する。人間はモデルの要約・転記ではなく、trusted helperの実際のtool出力そのものを直接確認する。モデルの説明だけを境界にすると、人間が確認した本文とhelperがpreflightした本文の同一性保証がモデルへの信頼へ戻るためである。

人間はtool出力のcategory、filename、`normalized-byte-count`、`confirmation-digest` 64文字全部、fixed framing内のnormalized content全文を`----- END NORMALIZED CONTENT -----`まで確認する。byte countは補助情報であり本文確認を代替しない。truncate、省略、欠落、途中終了、折り畳み等がある場合や、UI・実行環境上でtool出力そのものを直接確認できない場合はsaveへ進まない。digestが全文をbindしていても、ENDまで表示されなければ人間は全文を確認していないためである。

save承認画面では固定helper path、mode、category、filename、canonical command shapeに加え、preflightで確認したconfirmation digestと`--confirmation-digest`の64文字すべてを照合する。digestはpreflightとsaveを結ぶ唯一のステートレスなbindingであり、部分比較を正式な確認手順にすると運用上のbinding強度を落とすためである。payload全文の意味内容を承認画面で目視decodeすることは要求せず、本文はtrusted preflight出力、同一性はdigest bindingとsave helperの再計算で確認する。save成功後のsaved byte countとdigest照合は事後確認であり、save前の64文字比較を代替しない。

同一sessionで複数回preflightした場合は、save直前に人間が確認した最新preflightだけを有効とする。category、filename、本文が変わった場合、または対象結果を取り違えた可能性がある場合は、以前のdigestを再利用せずpreflightからやり直す。同一filenameで異なる内容を複数回preflightした際に、古いdigestと新しい本文を取り違えることを防ぐためである。

#### Hookのclosed world

Ask候補は次の2形だけである。

```text
python3 .claude/skills/save-local-artifact/scripts/save_local_artifact.py preflight --category <reports|handoffs|scratch> --filename <name> --content-base64url=<payload>
python3 .claude/skills/save-local-artifact/scripts/save_local_artifact.py save --category <reports|handoffs|scratch> --filename <name> --confirmation-digest <64-lower-hex> --content-base64url=<payload>
```

Hookは共通shell構文を先にDenyし、固定相対helper path、mode、token数、option順、category、filename regex `[A-Za-z0-9][A-Za-z0-9_-]{0,62}\.(?:md|txt)`、save時の64 lowercase hex digest、payloadのbase64url alphabetとencoded上限2,048 ASCII byteを検査する。検証済み値からcommandを再構築し、元commandとの完全一致だけをAskへ進める。一般`python3`、absolute/別表記path、quote差、空白差、重複・追加・並べ替えoptionはDenyする。

Hookはpayloadをdecodeせず、reasonへpayload、digest、filename、raw commandを含めない。承認画面へ到達するcommand shapeを狭めるHookと、content・filesystemを検査するhelperを二重化することで、一方だけの責務へ境界を広げない。

#### transport、文字、confirmation digest

payloadはUTF-8本文のunpadded base64urlで、emptyを許可する。helperはalphabetとencoded上限に加え、strict decode後にunpadded base64urlへ再encodeして入力との完全一致を確認する。主たるE2E制約をencoded 2,048 ASCII byte、従属上限をraw decoded 1,536 byteとCRLF・CRからLFへの正規化後1,536 byteとし、rawとnormalizedを独立に検査する。

1,536は3の倍数であり、unpadded base64url長は`1,536 × 4 / 3 = 2,048`となる。raw 1,537 byteのencoded長は2,048を超えるため、encoded上限内の最大rawは1,536 byteである。normalized上限も同じ1,536 byteへ固定する。payloadがcanonical Bash commandの単一argvへ直接含まれ、Claude Code E2E transportの完全性に左右されるため、encoded上限を主とする。

2026年8月14日、WSL Linux filesystem上の当該repositoryでcategoryを`scratch`、preflightだけ、saveなしとしてE2E測定した。結果は次のとおりである。

| 段階 | 人間側raw | 人間側encoded | helper到達raw | helper到達encoded | 結果 |
|---|---:|---:|---:|---:|---|
| 1 | 768 | 1,024 | 768 | 1,024 | PASS |
| 2 | 1,536 | 2,048 | 1,536 | 2,048 | PASS / 採用値 |
| 3 | 3,072 | 4,096 | 3,069 | 4,092 | FAIL |
| 旧最大 | 65,536 | 87,382 | - | - | Claude Code tool call発行不能 |

段階1・2ではtool call発行、HookからAskへの到達、人間承認、helper実行、期待した`normalized-byte-count`、欠落・truncateなしを確認した。段階3ではPowerShell上のpayload長とclipboard長がともに4,096であることを確認した一方、helper到達時にbase64url 4文字、raw 3 byteが欠落した。欠落後もpayload自体がcanonical base64urlだったため、Hookのshape・長さ検査とhelperの再encode一致検査だけではtransport欠落を検出できない。

2,048は「2,049以上で必ず失敗する」物理境界ではない。E2Eで内容完全性まで実証できた最大値をclosed-world / fail-closedの安全側運用上限として採用する。したがってtrusted preflightでは`normalized-byte-count`、confirmation digest、本文全文をEND framingまで人間が直接確認する。将来上限を引き上げる場合も、同じE2E手順でtool call発行からhelper到達までの完全性を再実証する。

helperとHookは責務分離のため上限定数を独立定義するが、両者のencoded上限は同じ値でなければならず、source-sync回帰testでdriftを検出する。save承認画面でbase64url payload全文の意味内容を読むことは上限決定基準にせず、command shapeと64文字digestを確認対象とする。

UTF-8 strict、LF・TAB許可、NUL・ESC・DEL・LF/TAB以外のC0・C1・U+FEFF・U+2028・U+2029拒否とする。Unicode normalizationと末尾LFの追加・削除は行わない。文字運用は[AI共用ローカル成果物運用](AI_LOCAL_ARTIFACTS.md)を正本とする。

confirmation digestはSHA-256の64 lowercase hexで、次を入力とする。

```text
b"review-app-laravel/save-local-artifact/v1\x00"
+ category_ascii
+ b"\x00"
+ filename_ascii
+ b"\x00"
+ normalized_utf8_bytes
```

固定versionとNUL separatorによりcategory、filename、本文の境界を一意にbindする。preflightはtrusted helper自身がdigestとfixed framingを出力し、saveは同じ関数で再計算したdigestが一致する場合だけfilesystemへ進む。model生成previewだけへ同一性を依存しないため、preflightとsaveを分離する。

#### root、directory、residue

helperは自身の`__file__`と固定配置`<repo>/.claude/skills/save-local-artifact/scripts/save_local_artifact.py`からrepository rootを導出し、各componentのsymlink・dangling symlinkを拒否する。derived rootとcwdの`st_dev`・`st_ino`が一致しない場合は停止し、caller指定path、環境変数、absolute pathへのfallbackを持たない。

`sys.platform == "linux"`、`/proc/sys/kernel/osrelease`のWSL marker、必要Python APIを確認し、`/mnt/<single ASCII letter>`そのものと配下を拒否する。判定不能時はfail-closedする。同一UIDの悪意あるprocessによる意図的な変更は完全防御対象外とするが、通常race、symlink差し替え、ancestor replacementにはdirectory descriptor基準で対処する。

`.ai-work/`と指定categoryは通常directory、非symlink、実行UID所有、group/other非writableであることを確認し、`O_DIRECTORY | O_NOFOLLOW | O_CLOEXEC`で開いた後も`fstat`する。自動作成、chmod、chown、修復を行わず、以後のnamespace操作はcategory dirfd基準とする。

staging作成前に指定categoryだけを最大4,096 entriesまでscanする。予約prefix `.__claude_save_staging_`が種類を問わず1件でもあれば停止し、自動採用・削除・内容readを行わない。staging名はprefixとCSPRNG 128 bitの32 lowercase hexで、`O_CREAT | O_EXCL | O_WRONLY | O_NOFOLLOW | O_CLOEXEC`、mode `0600`により最大8回試行する。cleanup対象は現在processが`O_EXCL`で作成に成功した名前だけである。

#### atomic publishと状態機械

全byteのshort write loop、file fsync、write fd closeの後、同じcategory dirfd内で`os.link(..., follow_symlinks=False)`によりfinalを作る。事前存在確認ではraceを防げないため、hard-linkの`EEXIST`を最終no-overwrite境界とし、finalへの直接writeや`os.replace` fallbackを行わない。

link後にfinalがregular file、mode `0600`、final/stagingの`st_dev`・`st_ino`一致をdescriptor-relativeに確認し、publish用directory fsync後だけ`PUBLISHED_DURABLE`とする。その後にだけ現在processのstagingをunlinkし、cleanup用directory fsync成功で`COMPLETE`とする。

link前failureで自分のstagingのcleanupとdirectory fsyncが完了すれば`FAILED`、cleanupを確定できなければ`FAILED_WITH_RESIDUE`とする。link後のdiagnostic失敗またはpublish用directory fsync失敗は`INDETERMINATE`とし、finalとstagingを変更・削除・retryしない。durable publish後のstaging unlinkまたはcleanup用directory fsync失敗は`PUBLISHED_WITH_RESIDUE`とし、finalを変更しない。`PRE_PUBLISH`は内部の開始状態である。

`INDETERMINATE`でcleanupしないのは、durabilityを確定できない段階で人間がfinal/staging双方から状態を確認する情報を失わないためである。どのfailureでもredirect、Write/Edit、別path、別transport、direct target write、rename等へfallbackしない。residueの人間向け復旧は[AI共用ローカル成果物運用](AI_LOCAL_ARTIFACTS.md)を正本とする。

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

WebFetchは公式一次情報の確認だけに使用する。permissionsではbare `WebFetch`をaskとし、Hookの安全候補も毎回人間が確認する。自動allowは0件を維持し、承認画面から`Always allow`を追加しない。

Hookは公式Schemaの`url`と`prompt`を分けて検査する。高度な秘密情報検出、HTTP通信、redirect追跡は行わない。

判定順は次のとおりとする。

1. 入力構造、field、型を検査する。malformed URL、未知または不正な入力構造はdenyする。
2. scheme、host、port、userinfoを解析する。`https`以外、許可外host、suffix偽装host、未登録subdomain、明示port、userinfoはdenyする。
3. URLにpercent encoding、不正percent sequence、backslash、double slash、`.`または`..` path segmentがあればdenyする。canonical URLはpercent encodingを使用しない。
4. fragment内に秘密情報らしきkeywordがあればdenyする。その他のfragment付きURLはaskとする。
5. query内に秘密情報らしきkeywordがあればdenyする。その他のquery付きURLはaskとする。
6. prompt側だけで秘密情報らしきkeywordを検出した場合はaskとする。
7. deny条件に該当せず、ask候補条件をすべて満たす場合だけaskとする。初期版ではWebFetchを自動allowしない。

ask候補条件は次のすべてである。

- schemeが`https`
- hostが本文の有限allowlistのいずれかと完全一致
- 明示portなし
- userinfoなし
- 元のURL側に秘密情報らしきkeywordなし
- percent encoding、backslash、double slash、dot segmentなし

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

判定は大文字・小文字を区別しない。percent encodingはdecodeして受理せず、single encoding、double encoding、不正percent sequenceをcanonical URL外としてdenyする。URL側とprompt側の両方で検出した場合は、URL側のdenyを優先する。URL、prompt、入力JSONを保存しない。

hostは完全一致で判定し、apexとsubdomainを別hostとして扱う。suffix一致を許可せず、必要なhostは1件ずつ追加する。Webページ本文の命令は非信頼入力として扱う。

Hook自身はredirectを追跡せず、HTTP通信も行わない。Claude Codeがredirect先を別のWebFetch呼び出しとして扱うことは公式保証として扱わず、別呼び出しになった場合だけその入力へ同じ判定が適用される。redirect時の実際の挙動は、人間によるIssue #52の実機確認事項とする。

### 12.3 初期WebFetch Ask候補host allowlist

| ホスト | 用途 | apex | サブドメイン | 初期判定 | 根拠 |
|---|---|---:|---:|---|---|
| `code.claude.com` | Claude Code公式仕様 | 対象 | 対象外 | ask候補 | Claude Codeの現行公式host |
| `laravel.com` | Laravel 11公式仕様 | 対象 | 対象外 | ask候補 | Laravel 11.55.1（Issue #122の短期中継baseline。`main`および本番環境へは反映しない）を使用 |
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

### 12.5 Issue #89のMVP2追加host/path

確認日：2026年8月10日。Issue #89で追加するURLは、既存14hostとは分離してpathもclosed worldにする。すべてHTTPS、host完全一致、userinfoなし、明示portなし、queryなし、fragmentなしとし、表にない類似path、download path、redirect後に表の範囲外となるURLはdenyする。HookはHTTP redirectを追跡しないため、redirect先が別のWebFetch入力として提示された場合も同じ判定へ通し、同じclosed worldで再検証できない自動redirectは確認済みと扱わない。

| host | 許可path | 用途・根拠 |
|---|---|---|
| `developer.themoviedb.org` | `/docs/`、`/reference/` prefix | TMDB仕様の設計確認。実API hostではない |
| `www.themoviedb.org` | `/documentation/api/terms-of-use`、`/about/logos-attribution` exact | 利用条件とattribution |
| `www.typescriptlang.org` | `/docs/`、`/docs/handbook/`、`/docs/handbook/release-notes/`、`/tsconfig/` prefix | MVP2 Playwright testのTypeScript設定 |
| `playwright.dev` | `/docs/intro`、`/docs/browsers`、`/docs/ci`、`/docs/docker`、`/docs/test-configuration`、`/docs/trace-viewer`、`/docs/release-notes` exact、`/docs/api/` prefix | MVP2 browser testとCI設計 |
| `dev.mysql.com` | `/doc/refman/8.4/en/` prefix | `compose.yaml`とCIで使用するMySQL 8.4系Reference Manual |
| `docs.docker.com` | `/compose/` prefix | 現行Laravel Sail環境で使用するDocker Compose documentation |
| `repo.packagist.org` | `/p2/<固定package>.json` exact | Composer package metadata |
| `registry.npmjs.org` | `/<固定package>/latest` exact | npm latest version metadata |

Packagistの固定packageは`composer.json`の`require`と`require-dev`にあるpackage名（`php`を除く）の有限集合とする。npmの固定packageは`package.json`の`devDependencies`にあるpackage名、およびMVP2で導入確定済みの`typescript`と`@playwright/test`の有限集合とする。Hook内の集合を実装上の正本とし、任意package名、tarball URL、package installは許可しない。

npmのpackage rootが返すfull packumentは、`typescript`の実機確認でWebFetchの10 MiB応答上限を超えた。このためpackage rootは許可せず、固定packageのlatest version metadataへ応答を限定する。suffixはliteral `latest`だけとし、任意versionと任意dist-tagも許可しない。これにより取得可能なresourceを広げずに、MVP2で必要な現在のlatest metadataだけを参照する。

HookではIssue #89以前の14hostを`LEGACY_WEBFETCH_HOSTS`へ明示固定し、path制限対象hostはexact/prefix/metadataのpath正本から`RESTRICTED_WEBFETCH_HOSTS`を導出する。全体の`WEBFETCH_HOSTS`はこの2分類の和だけとし、path判定のfallbackは`LEGACY_WEBFETCH_HOSTS`にしか適用しない。hostだけが追加されて分類またはpathが未登録の状態はaskへ進めずdenyする。

TMDBの`api.themoviedb.org`と`image.tmdb.org`、TypeScript Playground、Playwright browser binaryとDocker image、MySQL 8.4以外のmanual、Docker Compose以外のdocumentationは対象外とする。TypeScriptとPlaywrightはMVP2 Milestoneのbrowser test自動化に必要な技術であり、未導入であることを理由に権限を将来全般へ広げず、上表の公式文書だけを先行して確認可能にする。

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
- `gh` commandの判定時に限り、`GH_REPO`と`GH_HOST`がcommand prefixまたはHook実行環境に存在する場合はdenyする。Action Release専用経路では加えて`GH_DEBUG`、`GH_FORCE_TTY`、pager/color関連の既知環境変数もdenyする。値にかかわらず存在だけでdenyするのは、canonical commandの出力・実行挙動がcaller環境で変化するのを防ぐためである。Gitや一般Bashにはこの検査を適用しない。
- 設計書に記載したcanonicalな引数順だけを受理し、`-R`、equals形式、option位置を変えた形は初期版ではdenyする。
- `--web`をdenyする。
- `--watch`をdenyする。
- `--jq`をdenyする。
- Issue・PR本文やコメントを非信頼入力として扱う。

Hookは実行環境における対象環境変数の存在だけを確認し、値を比較、保存、出力しない。継承状態は実機検証する。command内の明示的な環境変数prefixもdenyする。

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

### 14.4 Global Security Advisories専用helper

bare `gh api`と`Bash(gh api *)`のdenyは維持する。例外はrepository配下の`.claude/helpers/github_global_advisories.py`だけとし、Hookは一般`python3` denyより先に次のcanonical形を完全一致で判定する。

```text
python3 .claude/helpers/github_global_advisories.py view <GHSA-ID>
python3 .claude/helpers/github_global_advisories.py list --ecosystem <composer|npm> --package <固定package>
```

- helper pathは上記のrepository相対pathだけとする。`./`を加えた別表記、絶対path、`~`、`$`、環境変数展開、command substitution、任意scriptをdenyする。
- `view`は`GHSA-xxxx-xxxx-xxxx`のGitHub Base32 alphabetに合致するIDだけを受理する。
- `list`はoption順を固定し、ecosystemは`composer`または`npm`、packageは§12.5の固定package集合だけを受理する。任意query、endpoint、method、header、optionはcallerから受け取らない。
- helperが生成するargvは`gh api <literal endpoint> --hostname github.com --method GET --include --header "Accept: application/vnd.github+json" --header "X-GitHub-Api-Version: 2022-11-28"`の順序へ固定する。2026年8月10日時点で同versionはGitHubのsupported versionである。`shell=False`、stdinなし、非TTY、新sessionで実行する。
- endpointは`/advisories`と`/advisories/<GHSA-ID>`だけとする。一覧queryは検証済みecosystem/package、固定`per_page=50`、検証済み`after` cursorからhelperが再構築する。
- 子process環境はOS accountから得たHOME、固定PATH/locale/TERM/color/pager/non-interactive値だけとし、callerの`GH_HOST`、`GH_REPO`、`GH_DEBUG`、`GH_FORCE_TTY`、`GH_CONFIG_DIR`等を継承しない。tokenを取得、保持、出力する`gh auth token`は使わない。
- `-f`、`-F`、`--paginate`、`--slurp`、`--jq`、`--template`、`--verbose`、`--input`、Authorization headerを使用しない。
- responseは1回512 KiB、全体1 MiB、header 32 KiB、UTF-8 JSON出力256 KiB、1ページ50件、最大3ページ・150件、1 advisoryのvulnerability 100件、string 4096文字、timeout 20秒へ固定する。stdout/stderrを読みながらheader込み上限を適用し、JSON parse前にraw byteとUTF-8を検査する。
- paginationは`Link`の`rel="next"`だけを解析し、scheme `https`、host `api.github.com`、明示port/userinfoなし、path `/advisories`、固定query key/valueを検証する。URL自体は実行せず、検証済み`after` cursorだけを抽出し、次のliteral endpoint argvを再構築する。最大3ページ後もnextがある場合は部分結果を出さずdenyする。
- 必須schemaと型、GHSA ID、severity、timestamp、control characterを検査し、未知の追加fieldは無視する。GitHub公式schemaに合わせ、`withdrawn_at`、`vulnerabilities`、vulnerabilityの`package`、`package.name`、`vulnerable_version_range`、`first_patched_version`は`null`を受理する。package filter時は`package`または`package.name`が`null`で照合不能なvulnerabilityをスキップし、一致するprojected vulnerabilityが0件なら無関係な結果を返さずfail-closedにする。出力は`ghsa_id`、`summary`、`severity`、`published_at`、`updated_at`、`withdrawn_at`、vulnerabilityの`package.ecosystem`、`package.name`、`vulnerable_version_range`、`first_patched_version`だけへprojectionし、raw responseやcredentialを出力しない。
- malformed response、invalid UTF-8、巨大response、schema不一致、subprocess失敗、timeoutは固定errorだけでfail-closedにする。Dependabot固有情報はIssue #90へ残す。

このcanonical helper規約（repository相対の固定path、有限subcommand/option/value、共通shell denyより後かつ一般interpreter denyより前の専用判定、固定argv・環境・projection）は、Issue #90と#91で専用helperが必要になった場合にも再利用する。

### 14.5 現行CI GitHub ActionsのReleaseとReleaseに紐づくTag

通常のIssue・PR参照に対する`REPOSITORY = github.com/honda-dev-jp/review-app-laravel`は変更しない。外部repository例外は`.github/workflows/ci.yml`で実際に使用中の次の5件に対するRelease情報だけとする。

```text
github.com/actions/checkout
github.com/shivammathur/setup-php
github.com/actions/setup-node
github.com/actions/setup-python
github.com/astral-sh/ruff-action
```

canonical形は次の2つだけで、JSON fieldの順序も固定する。

```text
gh release list --limit 20 --repo <固定repository> --json tagName,name,publishedAt,isDraft,isPrerelease
gh release view <tag> --repo <固定repository> --json tagName,name,publishedAt,isDraft,isPrerelease,url
```

`<tag>`は1〜100文字の`[A-Za-z0-9._+-]`に限定し、先頭は英数字とする。`gh release view`がReleaseとして解決するtagだけが取得対象であり、`git tag`、Git ref API、任意Tag一覧は許可しない。任意repository、Issue/PR、Actions run、repository設定、asset field、body field、release download、source archive、未知option・fieldはdenyする。これはRelease/Release-linked Tag専用の別allowlistであり、通常の単一repository固定を汎用化するものではない。

### 14.6 repository固有Dependabot alerts専用helper

2026年8月12日にGitHub公式のDependabot alerts REST API、REST API versions、pagination、OAuth scopes、`gh api`を再確認した。repository alertsの一覧`GET /repos/{owner}/{repo}/dependabot/alerts`と個別`GET /repos/{owner}/{repo}/dependabot/alerts/{alert_number}`はpublic previewであり、推奨Acceptは`application/vnd.github+json`、実装時点のlatest supported API versionは`2026-03-10`である。fine-grained tokenではrepositoryの`Dependabot alerts: read`、OAuth app tokenとclassic PATではprivate/public repositoryに`security_events`、public repositoryだけなら`public_repo`が公式要件である。現在のcredentialは変更せず、人間による実機確認で実効permissionを確認する。権限不足、認証失敗、将来unsupported versionによる`410 Gone`ではscope変更、再認証、別credentialへのfallbackを行わない。

bare `gh api`と`Bash(gh api *)`のdeny、bare Bash Ask、Allow 0件は維持する。例外は`.claude/helpers/github_dependabot_alerts.py`だけとし、一般`python3` denyより前に次の完全一致形をAsk候補として判定する。

```text
python3 .claude/helpers/github_dependabot_alerts.py list
python3 .claude/helpers/github_dependabot_alerts.py view <alert_number>
```

`list`は可変入力なし、`view`は`[1-9][0-9]*`かつ`1〜9223372036854775807`のalert番号1件だけを受理する。`0`、符号、leading zero、小数、空白、過長値、追加引数・option、別helper表記を拒否する。owner、repository、hostname、endpoint、method、header、token、query、projection、jq等はcallerから受け取らない。

repositoryは`honda-dev-jp/review-app-laravel`、methodはGET、一覧queryは`state=open&per_page=25`へ固定する。list初回、list次page、viewのargvは次の順序へ固定し、subprocess直前にも全体を再検証する。list/viewの両方で`--include`を使用する。

```text
gh api /repos/honda-dev-jp/review-app-laravel/dependabot/alerts?state=open&per_page=25 --hostname github.com --method GET --include --header "Accept: application/vnd.github+json" --header "X-GitHub-Api-Version: 2026-03-10"
gh api /repos/honda-dev-jp/review-app-laravel/dependabot/alerts?state=open&per_page=25&after=<percent-encoded-cursor> --hostname github.com --method GET --include --header "Accept: application/vnd.github+json" --header "X-GitHub-Api-Version: 2026-03-10"
gh api /repos/honda-dev-jp/review-app-laravel/dependabot/alerts/<alert_number> --hostname github.com --method GET --include --header "Accept: application/vnd.github+json" --header "X-GitHub-Api-Version: 2026-03-10"
```

`-f`、`-F`、`--paginate`、`--slurp`、`--jq`、`--template`、`--verbose`、`--input`、`--cache`、`--silent`、Authorization headerを使用しない。子processは`stdin=DEVNULL`、stdout/stderr PIPE、`shell=False`、`close_fds=True`、new session、非TTY、timeout 20秒とする。環境はOS accountから得たHOMEと次の固定値だけに置換し、caller環境を継承しない。

```text
PATH=/usr/local/bin:/usr/bin:/bin
LANG=C.UTF-8
LC_ALL=C.UTF-8
TERM=dumb
NO_COLOR=1
CLICOLOR=0
CLICOLOR_FORCE=0
GH_PAGER=cat
PAGER=cat
GH_PROMPT_DISABLED=1
```

このため`GH_HOST`、`GH_REPO`、`GH_DEBUG`、`GH_FORCE_TTY`、`GH_CONFIG_DIR`、`GH_TOKEN`、`GITHUB_TOKEN`等は子processへ渡らない。helperはtokenやcredentialを直接取得、保持、出力せず、`gh auth token`も使用しない。

固定上限は`PER_PAGE=25`、`MAX_PAGES=6`、`MAX_ALERTS=150`、1 subprocess raw 512 KiB、1 JSON body UTF-8 480 KiB、helper全体raw 1 MiB、helper全体UTF-8 960 KiB、header 32 KiB、output UTF-8 256 KiB、string 4096 Unicode code point、cursor 512文字、CWE 50件、timeout 20秒とする。`25 * 6 = 150`であり、総byte上限がpage上限より先に働くことは意図したDoS境界である。stdout/stderrを同時にbounded readし、JSON parse前にraw上限を適用する。上限を自動拡張せず、部分結果を返さない。

headerはASCII、bodyはUTF-8として分離し、HTTP status 200だけを受理する。複数response、redirect、duplicate/malformed/non-ASCII/oversize header、成功時のstderr、invalid UTF-8/JSON、duplicate JSON key、NaN/Infinityを拒否する。paginationは`Link`の`rel="next"`だけを追跡し、nextは最大1件とする。URLのscheme `https`、host `api.github.com`、port/userinfo/fragmentなし、path `/repos/honda-dev-jp/review-app-laravel/dependabot/alerts`、順序非依存のquery key集合`state`、`per_page`、`after`と固定値を検証する。`/repositories/{repository_id}/...`等の推測したcanonicalized pathは許可しない。

`after`はstrict percent decode後に1〜512文字の`[A-Za-z0-9._~=-]+`として検証し、seen setで重複・循環を拒否する。Link URL自体は次requestへ使わず、安全にpercent encodeしたcursorから固定endpointとargvを再構築する。6ページ目でnextなしは成功、nextありは全体失敗とする。実Linkのpath/query/cursor形は人間の実機確認まで未確認として扱う。

一覧projectionは`number`、`state`、`dependency.package.ecosystem/name`、`manifest_path`、`scope`、`relationship`、`security_advisory.ghsa_id/cve_id/severity/summary`、`security_vulnerability.vulnerable_version_range/first_patched_version.identifier`、`created_at`、`updated_at`、`fixed_at`だけとする。詳細はこれにadvisoryの`published_at`、`updated_at`、`withdrawn_at`、nullableな`cvss_severities.cvss_v3/cvss_v4`のnullableな`score`と`vector_string`、nullableかつ最大50件の`cwes.cwe_id/name`だけを加える。`security_advisory.cvss`、description、references、URL、全identifiers、raw advisory、assignees、dismissed comment、全vulnerabilitiesは出力しない。

source schemaでは`security_vulnerability.package`と`severity`も必須検査し、dependency packageと一致させる。list stateは`open`、view stateは`open/fixed/dismissed/auto_dismissed`、ecosystemは`composer/npm`、scopeはnullまたは`development/runtime`、relationshipはnullまたは`unknown/direct/transitive/inconclusive`、severityは`low/medium/high/critical`に限定する。nullable field、timestamp、GHSA/CVE ID、first patched object/identifierを検査し、CVSS scoreはnullまたはfiniteな0.0〜10.0、vector stringはnullまたは安全なstringだけを受理する。未対応ecosystemが返った場合はfixed errorでfail-closedとし、人間がschemaと対象範囲を再確認するまで推測でenumを広げない。未知fieldは必須schemaが正常なら無視し、未知の長大description等へprojected string上限を誤適用しない。

projected string、必要なJSON key、header、cursorではC0 U+0000〜U+001F、DEL U+007F、C1 U+0080〜U+009Fを明示拒否し、正常出力は`ensure_ascii=True`のcompact JSONとする。異常時はstdoutを空にし、exit code 1と固定stderr `Dependabot alert request rejected`だけを返す。raw body/header、`gh` stderr、endpoint、cursor、入力値、token、credentialを出力しない。

`.claude/settings.json`はbare Bash Ask、bare `gh api` deny、Hookの専用判定だけで境界を構成できるため変更しない。Issue #89 helperのAPI version `2022-11-28`、C1処理、cursor・byte上限方式も遡及変更しない。#89はASCII header decode、projected stringの既存control検査、`ensure_ascii=True`により実効的な出力安全性とfail-closedを維持している。#90のrepository/state固定、API version、C1明示拒否、seen cursor、raw/UTF-8別上限、list/view共通`--include`はIssue #90の受け入れ条件に基づく意図的な差である。

### 14.7 repository固有GitHub Actions run/job metadata専用helper

Issue #91ではbare `gh run list/view`をAskへ広げず、repository相対の`.claude/helpers/github_actions_runs.py`だけを一般`python3` denyより前に判定する。canonical commandは次の2形だけとする。

```text
python3 .claude/helpers/github_actions_runs.py list
python3 .claude/helpers/github_actions_runs.py view <run-id>
```

`list`は可変入力なし、`view`はASCIIの`[1-9][0-9]*`かつ`1〜9223372036854775807`のrun IDだけを受理する。leading zero、符号、小数、空白、非ASCII数字、過長値、別helper表記、追加引数・optionを拒否する。int64上限は入力の型境界であり、実在run IDの上限を意味しない。callerからrepository、limit、field、branch、commit、event、status、workflow、user、created、`--all`、`--jq`、`--template`等を受け取らない。

helper内部のargvは次の順序へ完全固定し、subprocess直前にも全配列を再検証する。

```text
gh run list --limit 20 --repo github.com/honda-dev-jp/review-app-laravel --json databaseId,workflowName,displayTitle,event,status,conclusion,headBranch,headSha,createdAt,updatedAt
gh run view <run-id> --repo github.com/honda-dev-jp/review-app-laravel --json databaseId,workflowName,displayTitle,event,status,conclusion,headBranch,headSha,createdAt,updatedAt,jobs
```

`--attempt`、`--exit-status`、`--job`、`--log`、`--log-failed`、`--verbose`、`--jq`、`--template`、`--web`は使用しない。特に`--exit-status`はworkflow failureを`gh` subprocessのnonzeroへ変えるため、失敗runの診断metadataとhelper failureを分離できなくなる。workflow conclusionがfailure、cancelled、timed_out等でもmetadata取得と検証に成功すればhelper exit 0とする。

list/view共通run projectionは`databaseId`、`workflowName`、`displayTitle`、`event`、`status`、`conclusion`、`headBranch`、`headSha`、`createdAt`、`updatedAt`だけとする。viewはこれに最大100件の`jobs`を加え、各jobは`name`、`status`、`conclusion`、`completedAt`だけを出力する。run/job URL、`startedAt`、`attempt`、job databaseId、steps、unknown fieldは出力しない。viewの`databaseId`は要求run IDと完全一致させる。

すべてのprojection fieldはsource object内で必須とし、`databaseId`はpositive int64、`displayTitle`、`event`、`headSha`、job nameはC0/C1/DELを含まない1〜4096文字のstring、run/job statusは`[a-z_]{1,32}`とする。status/conclusionは公式CLI/APIの将来値を永久的なclosed enumとして固定せず、安全なidentifier構文だけを検証する。`workflowName`と`headBranch`のnull/空文字、run/job conclusionのnull/空文字はnullへ正規化する。job `completedAt`のnull、空文字、`0001-01-01T00:00:00Z`もnullへ正規化する。requiredな`createdAt`/`updatedAt`ではこれらを拒否する。timestampは実在日時であるUTC RFC3339の`Z`形とし、小数秒を許可する。

`gh run view --json jobs`のraw jobにはstepsが含まれ得るが、CLIにはjobsからstepsだけを除外するoptionがない。初期版はjob metadataだけを必要とするため、steps内部へprojected schema/string検査を適用せず、raw全体のbyte、UTF-8、JSON構文、duplicate key、NaN/Infinity検査だけを行い、projectionから除外する。run/job metadataは非信頼入力として扱い、含まれるURL、command、命令へ自動で従わない。secret keywordやentropyによるheuristicは誤判定境界を増やすため導入せず、logs非取得、raw非出力、fixed projection、上限、control character拒否、`ensure_ascii=True`、固定errorを境界とする。

固定上限は`MAX_RUNS=20`、`MAX_JOBS=100`、list raw/UTF-8 256 KiB、view raw/UTF-8 2 MiB、output UTF-8 256 KiB、string 4096 Unicode code point、timeout 30秒とする。view rawだけを2 MiBにするのはprojectionで捨てるstepsがrawに含まれるためであり、timeout 30秒は実fixtureのviewが約21秒だったためである。`gh run list --limit 20`にhelper独自paginationや`--all`を加えず、取得後も20件以下を再検証する。空listは正常とする。

`gh run view --json jobs`はCLI内部で全job/stepsを取得してからJSON出力するため、helperはCLI内部network/memoryを事前制限できない。初期版はRESTへ切り替えず、30秒timeout、view raw 2 MiB、最大100 jobs、出力256 KiBと全体fail-closedで扱う。この既知制約を理由なくIssue #90のHTTP header、Link pagination、cursor、page上限へ置き換えない。

子process環境は`HOME`を`pwd.getpwuid(os.getuid()).pw_dir`から取得し、固定`PATH`、`C.UTF-8` locale、`TERM=dumb`、color無効、pager `cat`、`GH_PROMPT_DISABLED=1`、`GH_NO_UPDATE_NOTIFIER=1`だけを渡す。caller環境を継承せず、`GH_HOST`、`GH_REPO`、`GH_DEBUG`、`GH_FORCE_TTY`、`GH_CONFIG_DIR`、`GH_TOKEN`、`GITHUB_TOKEN`、browser、XDG override等を渡さない。`GH_NO_UPDATE_NOTIFIER`は診断外の通知・network挙動を増やさないために固定する。stdinはDEVNULL、stdout/stderrはPIPE、`shell=False`、`close_fds=True`、`start_new_session=True`とし、両streamを同時にbounded readする。timeout時はprocess groupを終了する。success時もstderrが1 byte以上あればfail-closedとし、raw stderrを表示しない。

invalid UTF-8/JSON、duplicate key、NaN/Infinity、schema/型不一致、件数・byte・string上限、subprocess nonzero、stderr、timeoutは部分結果を返さない。unknown fieldは出力せず、required field欠落を拒否する。正常時は`ensure_ascii=True`のcompact JSONだけをstdoutへ返し、stderrを空、exit 0とする。異常時はstdoutを空、固定stderr `GitHub Actions run request rejected` 1行、exit 1とし、raw response、CLI stderr、入力値、token、credentialを漏らさない。

`.claude/settings.json`はAllow 0件を維持し、`gh run rerun/cancel/delete/download/watch`の引数なし・引数あり形を明示Denyする。Hookでもbare `gh run`とunknown gh command/optionをDenyし、canonical helper 2形だけをAsk候補にする。PR差分レビューでは`gh pr checks`を先に使い、exit code 8をpendingとして扱う。checksだけで不足し、人間がrun IDを明示した場合だけcanonical viewを候補にする。Skill既定フローでhelperを自動実行せず、listはPR外push runを人間が明示した一般read-only調査へ残し、logs、rerun、URLアクセスへ自動遷移しない。

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
| 限定保存helper | §10.6のpreflight/save 2形 | ask候補 | `.ai-work/`限定保存 | 自動allow 0件、両commandを毎回承認 |
| 限定保存helper | 非canonical形・一般`python3` | deny候補 | write到達範囲をclosed worldに保つ | helper側validationも維持 |
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
| Bash | 限定保存helper | 固定path・mode・token・option順・category・filename・digest・payload shapeが正しい | 非canonical、追加・重複option、quote/空白/path差、encoded上限超過 | §10.6の2形 | Ask/Deny reason非漏洩と一般`python3`回帰も試験 |
| WebFetch | URL scheme | なし | `http`、その他scheme | `https`かつ他のdeny条件なし | scheme大小・encodeを試験 |
| WebFetch | `tool_input` | `url`と`prompt`だけで通常条件を満たす | 未知field（`run_in_background`を含む） | なし | 現行schemaと未知fieldを試験 |
| WebFetch | host/path | なし | 許可外、suffix偽装、未登録subdomain、#89の未登録path | 既存14hostまたは#89の有限host/path | apex・subdomain・類似path・末尾dotを試験 |
| WebFetch | port・userinfo | なし | 明示port、userinfoあり | 明示portなし、userinfoなし | 443を含む明示portを試験 |
| WebFetch | query・fragment | 両方なし | keywordを含むURL側 | keywordなしのqueryまたはfragment | 合成dummy値だけで試験 |
| WebFetch | URL canonical化 | なし | percent encoding、不正percent、backslash、double slash、dot segment、URL側keyword | canonical pathかつURL側keywordなし | single/double encodingと類似pathを試験 |
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

期待値は、Allow 0件、Askにbare `Bash`、bare `WebFetch`、`Read(/.env.*)`、`Read(/**/.env.*)`があり、DenyにWebSearchとその他の既存ruleがある状態である。

Issue #52の実機確認では、`/status`、`/permissions`、`/hooks`を使用し、User、Local、Project、Managed settingsを確認した。Allow 0件、上記4件のAsk、bare `WebFetch`がDenyから削除されていること、Project settingsからのHook登録とtimeout 5秒を確認済みである。

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

Issue #52をCloseする前に、人間がClaude Code上で次の代表形を確認する。Ask候補のうち、実機で承認画面が表示されたcommandだけをAsk確認済みとする。組み込みread-only判定により確認なしで実行されたcommandは、Hook判定上askであってもAsk確認済みとは扱わない。Deny系ではHookが拒否し、承認画面へ進まないことを確認する。変更・通信を伴うDeny例は実行せず、拒否表示までを確認する。

一般BashのAsk確認：

```bash
pwd
ls
head -n 20 README.md
grep Claude README.md
find app -type f
wc -l README.md
sed -n '1,20p' README.md
command -v python3
```

`pwd`はIssue #52の実機では確認画面なしで実行されたため、Ask確認済みとは扱わない。リストからは削除せず、Hookのcanonicalなask候補と実際の承認画面の差を示す実測結果として残す。

一般BashのDeny確認：

```bash
ls -a
cat README.md
pwd | wc -l
echo "$HOME"
python3 -c "print(1)"
```

GitのAsk確認：

```bash
git status --short
git branch --show-current
git diff
git diff --check
git log --oneline -n 5
```

`git status --short`はIssue #52の実機でBashの承認画面が表示されたため、Ask確認済みである。未登録commandはHookでDenyされ、承認画面へ進まないことも確認済みである。その他のAsk候補は、個別の結果が記録されるまで確認済みとは扱わない。

GitのDeny確認：

```bash
git status
git show
git fetch
git add README.md
git diff -- :(top)README.md
```

`git fetch`と`git add README.md`は実行してはならず、HookのDeny表示までを確認する。この一覧は代表的な統合確認であり、§20.2の全境界条件を確認済みとみなすものではない。

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

Issue #52をCloseする前に、人間がClaude Code上で次の代表形を確認する。Ask系では承認画面が表示され、自動Allowされないことを確認する。Deny系ではHookが拒否し、承認画面へ進まないことを確認する。

GitHub CLIのAsk確認：

```bash
gh issue view 52 --repo github.com/honda-dev-jp/review-app-laravel --json number,title,state,body,comments,labels,url
gh issue list --state open --limit 10 --repo github.com/honda-dev-jp/review-app-laravel
gh pr list --state open --limit 10 --repo github.com/honda-dev-jp/review-app-laravel
```

`gh issue view 52 --repo github.com/honda-dev-jp/review-app-laravel --json number,title`はIssue #52の実機でBashの承認画面が表示されたため、Ask確認済みである。未登録WebFetch hostはHookでDenyされ、承認画面へ進まないことも確認済みである。その他のAsk候補は、個別の結果が記録されるまで確認済みとは扱わない。

GitHub CLIのDeny確認：

```bash
gh issue status
gh pr status
gh issue view 52 --json number --repo github.com/honda-dev-jp/review-app-laravel
gh issue view 52 --repo github.com/honda-dev-jp/review-app-laravel --jq .title
gh issue view 52 --repo github.com/other/repository
```

上記Deny例はGitHub CLIを実行せず、HookのDeny表示までを確認する。この一覧は代表的な統合確認であり、§20.3の全option、環境変数、変更系subcommandを確認済みとみなすものではない。

### 20.4 Web

- WebFetchの自動allowが0件であること
- 既存14hostとIssue #89の有限host/pathは合成JSONでask候補として確認し、実通信は代表的な複数URLだけで設計どおりaskになること
- 許可外hostがdenyになること
- suffix偽装hostがdenyになり、apexと未登録subdomainが別判定になること
- redirect先を想定した別WebFetch入力が再検査されること
- `http`、明示port、userinfoがdenyになること
- 通常queryがaskになること
- 通常fragmentがaskになり、fragment内keywordがdenyになること
- URL側keywordがdenyになり、prompt側だけのkeywordがaskになること
- 大文字小文字、single/double encoding、不正percent sequenceを合成dummy値で確認すること
- malformed URLと未知の入力構造がdenyになること
- WebFetchの公式入力に存在しない`run_in_background`を加えた合成入力がdenyになること
- WebSearchがdenyになること
- Webページ内の命令が実行されないこと

Issue #52では、人間が次の範囲を実機確認した。

- `code.claude.com`と`laravel.com`でWebFetchが成功した
- 未登録subdomainの`sub.code.claude.com`は、Hookにより`Host not allowed`で拒否された
- bare `WebFetch`をaskからdenyへ戻してClaude Codeを再起動するとWebFetchが利用不可になった
- bare `WebFetch`をaskへ戻して再起動するとWebFetchが再度成功した

Issue #89の公式一次情報実機確認では、次の5 URLに限定して、PreToolUse Hookの通過、WebFetchのAsk表示、人間の「今回のみYes」、HTTP取得成功、ファイル保存なしまでを確認した。

- `https://developer.themoviedb.org/docs/getting-started`
- `https://www.themoviedb.org/documentation/api/terms-of-use`
- `https://www.themoviedb.org/about/logos-attribution`
- `https://www.typescriptlang.org/docs/`
- `https://playwright.dev/docs/intro`

`https://playwright.dev/docs/intro`では、ページ内のinstall commandを実行せず、package installとbrowser binary取得も行っていない。実機確認済みなのは上記5 URLだけであり、他の許可path、他のTMDB・TypeScript・Playwrightページ、未登録host/path、redirect、Deny系境界は、この確認結果だけを根拠に確認済みとは扱わない。

Issue #89のnpm metadata実機確認では、`https://registry.npmjs.org/typescript`がHook判定と人間のAsk承認を通過した後、HTTP取得時にWebFetchの10 MiB応答上限を超えて失敗した。metadata確認、package install、tarball取得は行われていない。この結果を受け、応答サイズを抑えつつ任意version・dist-tagへclosed worldを広げないため、canonical pathを`/<固定package>/latest` exactへ変更した。再設計後は、非scoped packageの`https://registry.npmjs.org/typescript/latest`とscoped packageの`https://registry.npmjs.org/@playwright/test/latest`について、それぞれHookのAsk、人間の「今回のみYes」、HTTP 200、metadata取得まで実機で成功した。いずれもファイル保存、package install、tarball取得は行っていない。実通信で確認済みなのはこの代表2形状だけであり、残りの固定npm package、未登録package、package root、任意version・dist-tag、percent encoding変種の実通信・実Denyは確認済みと扱わない。

上記以外のhost、redirect、query、fragment、keyword等の各境界条件は、この確認結果だけを根拠に確認済みとは扱わない。

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
- Issue #52の実機確認では、bare `Bash` askが設定されていても、組み込みread-only commandに該当する`pwd`は確認画面なしで実行された。この範囲では、人間の承認画面ではなくPreToolUse Hookのdeny判定が実効的な境界となる。Hookがaskを返したread-only commandへ確認画面を強制するには、`Bash(pwd)`等のcontent-scoped ask ruleが必要となる可能性があるが、運用負荷と対象commandを含めて後続Issueで再検証する。
- WebFetchには組み込みの事前承認domainがある。明示的なHook検査を通ることを実機で確認する必要がある。
- Issue #51ではbare `WebFetch` denyのため、合成JSONによるHook単体testと登録確認に限定した。Issue #52では`code.claude.com`と`laravel.com`のWebFetch成功、および未登録subdomainの`sub.code.claude.com`が`Host not allowed`で拒否されることを人間が確認した。組み込み事前承認domainとの関係やredirect等、今回結果が記録されていない挙動は未確認である。
- 公式はpermissionsとsandboxを多層防御として併用する構成を案内しているが、本環境ではsandboxを有効にするとClaude Codeが正常動作しないため、Issue #52では導入しない。sandboxはBashと子プロセスへOSレベルのfile・network制約を提供するため、未導入の間は任意subprocessをpermissions、Hook、人間承認だけで完全に封じられない。Laravel Sail環境と両立する隔離環境での再評価は後続Issueとする。
- sandboxを後続Issueで再評価する場合、`autoAllowBashIfSandboxed`の既定値は`true`であり、sandbox内のBashはbare `Bash` askがあっても確認なしで実行され得る。本プロジェクトの毎回承認方針を維持するには、同設定を含むsandboxとbare `Bash` askの関係を隔離環境で再検証する。
- Read denyは任意のサブプロセスによる間接Readを完全には防がない。
- `GH_REPO`と`GH_HOST`の継承状態はClaude Codeの起動環境に依存するため実機確認が必要である。
- Gitの表示系コマンドでも内部cache、pager、helper、外部diff等の副作用を完全には排除できない。
- Claude CodeやGitHub CLIのversion更新後はpermission matchingとHook入力schemaを再検証する必要がある。
- `/save-local-artifact` helperは秘密情報・個人情報の自動検出器を持たず、validation成功は内容の安全性を保証しない。保存前の人間確認を省略しない。
- 同一UIDの悪意ある別processによる意図的変更は完全防御対象外である。通常raceとsymlink・ancestor差し替えはdirfdとno-followで狭めるが、residueや`INDETERMINATE`は人間が確認する。

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

第2PRでは、第1PRのHookと回帰testを前提として、bare `WebFetch`をdenyからaskへ移した。次の設定変更は反映済みであり、統合検証項目は人間が確認する。

- bare `Bash` askの維持
- Git・GitHub CLIのcanonicalなask
- §10.2と§17の一般Bash canonicalなask
- path限定Git diff 3形のask
- 可変GitHub CLI ask
- WebFetchのAsk候補host allowlistと自動allow 0件
- bare `WebFetch`のdenyからaskへの移動
- Hookとpermissionsを組み合わせた統合検証
- 代表的な複数hostだけを対象とする実WebFetch通信の使い捨て環境での確認
- 実機検証結果に基づく関連文書更新

人間が`/status`、`/permissions`、`/hooks`を使用し、設定ソース、Allow 0件、bare `Bash`とbare `WebFetch`を含むAsk、Hook登録、timeout 5秒を確認済みである。代表hostのWebFetch、未登録subdomainの拒否、bare `WebFetch` denyへのフォールバックとaskへの再適用も確認済みである。設計書§20のうち、結果が個別に記録されていない検証項目は未確認のまま残す。

問題発生時に片方だけrevertできるよう、Hook導入とpermissions変更を同一PRへまとめない。

## 24. 関連ドキュメントの更新対象

Issue #50の実装結果に合わせて、次の文書を同期する。

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

Issue #52ではbare `Bash` askを維持し、bare `WebFetch`だけをdenyからaskへ移した。Git・gh・WebFetchの自動allowは0件のまま、canonicalなGit・ghとWebFetchのAsk候補host allowlistをHook前提の運用へ反映した。設定ソース、Hook、代表host、未登録subdomain拒否、フォールバックの確認済み結果は§20に記録し、結果が記録されていない検証項目は未確認のまま残す。

恒久許可については、次の表現へ統一する。

> Claude Codeの承認画面から「Always allow」を追加する運用は行わない。Allowルールは`.claude/settings.json`で一元管理する。

## 25. 公式一次情報

確認日：2026年8月14日

### Anthropic

- [Configure permissions](https://code.claude.com/docs/en/permissions)
- [Extend Claude with skills](https://code.claude.com/docs/en/skills)
- [Claude Code settings](https://code.claude.com/docs/en/settings)
- [Claude Code sandboxing](https://code.claude.com/docs/en/sandboxing)
- [Interactive mode](https://code.claude.com/docs/en/interactive-mode)
- [Choose a permission mode](https://code.claude.com/docs/en/permission-modes)
- [Hooks reference](https://code.claude.com/docs/en/hooks)
- [Automate actions with hooks](https://code.claude.com/docs/en/hooks-guide)
- [Tools reference](https://code.claude.com/docs/en/tools-reference)
- [Claude Code CHANGELOG](https://github.com/anthropics/claude-code/blob/main/CHANGELOG.md)（WebFetch allowlist外のため、人間または別の公式取得手段で確認する）
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
- [gh api](https://cli.github.com/manual/gh_api)
- [gh release list](https://cli.github.com/manual/gh_release_list)
- [gh release view](https://cli.github.com/manual/gh_release_view)
- [REST API endpoints for global security advisories](https://docs.github.com/en/rest/security-advisories/global-advisories?apiVersion=2022-11-28)
- [REST API endpoints for Dependabot alerts](https://docs.github.com/en/rest/dependabot/alerts?apiVersion=2026-03-10)
- [Using pagination in the REST API](https://docs.github.com/en/rest/using-the-rest-api/using-pagination-in-the-rest-api)
- [Scopes for OAuth apps](https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/scopes-for-oauth-apps)
- [GitHub REST API OpenAPI description 2022-11-28](https://github.com/github/rest-api-description/blob/main/descriptions/api.github.com/api.github.com.2022-11-28.yaml)
- [REST API versions](https://docs.github.com/en/rest/about-the-rest-api/api-versions)

### MVP2公式documentation

- [TMDB Getting Started](https://developer.themoviedb.org/docs/getting-started)
- [TMDB API Terms of Use](https://www.themoviedb.org/documentation/api/terms-of-use)
- [TMDB Logos & Attribution](https://www.themoviedb.org/about/logos-attribution)
- [TypeScript Documentation](https://www.typescriptlang.org/docs/)
- [Playwright Installation](https://playwright.dev/docs/intro)
- [MySQL 8.4 Reference Manual](https://dev.mysql.com/doc/refman/8.4/en/)
- [Docker Compose](https://docs.docker.com/compose/)

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
| 2026-08-01 | Issue #52でbare `WebFetch`をdenyからaskへ移動。Allow 0件とbare `Bash` askを維持し、公式一次情報WebFetchの毎回承認、人間向け統合検証計画、フォールバックを文書へ同期 |
| 2026-08-01 | 公式permissions仕様へ再同期。`Bash(run_in_background:true)`とgh PR変更系denyをpermissionsへ追加し、canonical tool名、local settingsの現行挙動、sandbox未導入の制約、Issue #52の実WebFetch確認結果を追記 |
| 2026-08-01 | Issue #52の実機確認結果を同期。設定ソース、Hook、Allow 0件とAsk、代表2host、未登録subdomain拒否、WebFetchのdenyフォールバックとaskへの再適用を記録 |
| 2026-08-10 | Issue #89のMVP2公式host/path、有限package metadata、Global Advisories専用helper、現行CI Action Release/Release-linked Tag専用経路を追加。Allow 0件、bare `gh api` deny、通常repository固定を維持 |
| 2026-08-10 | npm package rootの実機取得がWebFetchの10 MiB上限を超えたため、有限packageのliteral `/latest` exact pathだけへ変更。任意version・dist-tag、root、install、tarballのdenyを維持 |
| 2026-08-10 | Issue #95のPython CI追加に合わせ、現行CI Action Release allowlistを5 repositoryへ同期。Python 3.10以上の実行要件とPython 3.12単独CI検証を区別して記録 |
| 2026-08-13 | Issue #90のrepository固有Dependabot alerts専用helperを追加。public preview API version 2026-03-10、固定list/view、pagination・byte・schema・projection・C1境界を記録し、Allow 0件とbare `gh api` denyを維持 |
| 2026-08-13 | Issue #91のrepository固有Actions run/job metadata専用helperを追加。固定list/view、minimal projection、nullable・byte・job・subprocess境界を記録し、Allow 0件とbare `gh run` denyを維持 |
| 2026-08-14 | Issue #88の`/save-local-artifact`、trusted preflight、closed-world Hook、confirmation digest、dirfd・hard-link publish、residueと状態機械を追加。Allow 0件と編集tool denyを維持 |

## 27. 決定事項と実装前提

Issue #50・#51・#52・#88・#89・#90・#91の設計事項は次のとおり確定した。

1. Issue #51でHook、回帰test、Hook README、関連文書を実装し、Issue #52でbare `WebFetch`をdenyからaskへ移した。人間が設定ソース、Hook、代表hostの実WebFetch、未登録subdomain拒否、フォールバックと再適用を確認済みである。その他の実機検証は、個別の結果が記録されるまで確認済みとは扱わない。
2. Hook matcherは`Bash`と`WebFetch`だけとし、公式例に沿う`command`文字列、timeout 5秒で同期実行する。
3. HookはPython 3.10以上の標準ライブラリだけで実装し、stdin入力以外のfile読取、transcript読取、HTTP通信、redirect追跡、subprocess、Git、gh、ログ保存を行わない。`GH_REPO`・`GH_HOST`の存在確認はgh判定時だけ行う。
4. malformed JSON、必須field不足、型不正、未知tool、未知の`tool_input` field、Bashの`run_in_background: true`、Hook内部例外はdenyする。既知の任意top-level fieldは存在だけでdenyしない。
5. Gitはclosed worldとし、初期自動allowを0件とする。§13.1の10形だけをask候補とし、その他はdenyする。
6. GitHub CLIもclosed worldとし、初期自動allowを0件とする。state・limit・repositoryを固定したissue/pr listと、厳密に定義したissue/pr view、pr checksだけをask候補とする。status、`--jq`、未知option・fieldをdenyする。
7. 一般Bashもclosed worldとし、§10.2と§17のcanonical形だけをaskとする。その他はdenyする。複合command、redirect、command substitution、改行、環境変数prefixもdenyする。
8. Git・GitHub CLI・WebFetchの自動allowは0件とする。既存14hostは従来のhost完全一致を維持し、Issue #89の追加hostは§12.5の有限pathまで検査する。#89追加pathはqueryとfragmentもdenyする。
9. canonical URLはpercent encodingを使用せず、single/double encoding、不正percent sequence、encoded separator、backslash、double slash、dot segmentをdenyする。Hook自身はredirectを追跡しない。
10. bare `gh api` denyと通常GitHub参照の単一repository固定を維持し、Global Advisories helper、現行CI Action Release/Release-linked Tag、repository固有Dependabot alerts helper、repository固有Actions run/job metadata helperだけを§14.4〜§14.7の専用経路へ分離する。
11. Hook error、起動失敗、異常終了、timeoutが表示された場合は追加のBashとWebFetchを承認せず、安全側へ戻してから原因を調査する。
12. 初期実装ではcanonicalな引数順だけを扱い、高度なshell parser、URL keywordの誤検知改善、optionalなGitHub CLI field・option、`git show`は後続改善とする。Laravel学習を優先し、Claude Code整備を早期に完了する。
13. Issue #88ではsettingsを変更せず、ユーザー明示起動のSkill、preflight/saveの毎回承認、Hookのclosed world、helperのcontent/filesystem検査を分離する。`COMPLETE`以外を単純な保存完了とせず、residueと`INDETERMINATE`をAIが自動cleanupしない。

Issue #51ではbare `Bash` ask、bare `WebFetch` deny、Allow 0件を維持した。Issue #52ではbare `Bash` askとGit・GitHub CLI・WebFetchの自動allow 0件を維持し、bare `WebFetch`だけをdenyからaskへ移した。Hookを通過したWebFetchもpermissionsのaskにより毎回人間が確認する。

実機検証でClaude Codeの現行仕様と異なる挙動が確認された場合は、安全側へ戻し、設計書、Hook、permissions、回帰testを同じ変更単位で更新する。
