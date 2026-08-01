# Claude Code PreToolUse Hook

## 目的

このディレクトリのHookは、Claude Codeが`Bash`または`WebFetch`を実行する直前に入力を追加検査します。Claude Codeはレビュー専用であり、このHookはpermissionsと人間の承認を補強するガードレールです。完全なセキュリティ境界ではありません。

権限判定の詳細な正本は、[Claude Code権限設計](../../docs/CLAUDE_CODE_PERMISSION_DESIGN.md)です。

## 構成

```text
.claude/hooks/
├── pre_tool_use.py
├── README.md
└── tests/
    └── test_pre_tool_use.py
```

- 対象tool: `Bash`、`WebFetch`
- matcher: `Bash|WebFetch`
- 実装: Python 3.10以上、標準ライブラリのみ
- 外部package: なし

## 登録

Project settingsの`.claude/settings.json`に同期command Hookとして登録します。

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

`$CLAUDE_PROJECT_DIR`の展開、script起動、stdin受信、timeout時の挙動は、Claude Codeの実機で人間が確認します。

現在のProject settingsはbare `Bash`とbare `WebFetch`をAsk、Allowを0件としています。PreToolUse HookがAskを返した入力のうち、Claude Codeの組み込みread-only判定に該当しないものは、permissionsのAskにより人間の承認画面へ進みます。Issue #52の実機では`pwd`が確認画面なしで実行されたため、HookがAskを返したすべてのBashで承認画面が残るとは仮定しません。HookがDenyを返した入力は、組み込みread-only commandであっても承認画面へ進まず拒否されます。HookのAskやAllowはpermissionsのAskやDenyを迂回しません。

## 入出力

command HookはstdinからJSONを1件受け取り、stdoutへ判定JSONを1件だけ返します。初期版は`permissionDecision`の`ask`と`deny`だけを使用し、正常な構造化判定はexit code 0で返します。

```json
{
  "hookSpecificOutput": {
    "hookEventName": "PreToolUse",
    "permissionDecision": "ask",
    "permissionDecisionReason": "Canonical command requires approval"
  }
}
```

設計書§9.4が想定するexit code 2のfail-safe経路は、初期版では実装しません。初期版は、Hook内部で捕捉できる入力不正と例外を、固定理由を持つexit code 0の構造化`deny`へ一本化します。プロセス起動失敗、捕捉不能な異常終了、timeoutはHook自身から制御できないため、下記「異常時」の人間側手順で扱います。

### top-level field

実装時点の公式PreToolUse Schemaに基づき、次を扱います。

- 必須: `session_id`、`transcript_path`、`cwd`、`hook_event_name`、`tool_name`、`tool_input`、`tool_use_id`
- 任意: `prompt_id`、`permission_mode`、`effort`、`agent_id`、`agent_type`

必須field不足、型不正、未知field、`PreToolUse`以外の`hook_event_name`、`Bash`と`WebFetch`以外の`tool_name`はDenyします。Schema更新時は公式仕様を再確認し、README、実装、回帰テストを同時に更新します。

### tool_input field

- Bash
  - 必須: `command`（string）
  - 任意: `description`（string）、`timeout`（number）、`run_in_background`（boolean）
  - `run_in_background: true`はDeny
- WebFetch
  - 必須: `url`（string）、`prompt`（string）
  - その他のfieldはDeny。`run_in_background`も現行Schema外の未知fieldとして扱う

## 判定

初期版は自動Allowを行いません。

- `ask`: 設計書で定義したcanonicalな安全候補。人間が入力全体を確認する
- `deny`: 禁止操作、非canonical形、未登録command、秘密情報path、許可外host、解析不能入力

一般Bash、Git、GitHub CLIはclosed worldで判定します。余分な空白、quote差、option順差、追加optionは別commandとして扱います。高度なshell parserは使用せず、安全に分類できない入力はDenyします。

代表的なcanonical形:

```text
pwd
ls -la app
find resources -type f -name "*.blade.php"
git log --oneline -n 20
gh issue list --state open --limit 20 --repo github.com/honda-dev-jp/review-app-laravel
gh issue view 51 --repo github.com/honda-dev-jp/review-app-laravel --json number,title,state,body,comments,labels,url
python3 -m unittest discover -s .claude/hooks/tests -p "test_*.py"
```

代表的な非canonical形:

```text
ls .
ls -a
find resources -type f -name '*.blade.php'
gh issue view 51 --json number,title --repo github.com/honda-dev-jp/review-app-laravel
python3 -m unittest discover -s .claude/hooks/tests -p 'test_*.py'
```

`Secret-like path`は秘密情報pathだけでなく、単純なrepository相対pathとして受理できないpath構文にも使用します。`ls .`は引数なしの`ls`へ置き換えます。

list系gh commandでは`--repo`を末尾に置き、view系では番号の直後に置きます。正確な全command形は設計書の§10、§13、§14、§17を参照してください。

### 秘密情報path

pathを受け取るcommandへ設計書§15の共通規則を適用します。`.env`、`.env.*`、logs、framework生成物、private storage、秘密鍵、SQL dump、credential等をDenyします。

`.env.example`は、path全体が正確に`.env.example`であり、他の秘密情報規則に一致しない場合だけ例外候補です。たとえば`config/.env.example`は例外ではありません。

### WebFetch

設計書の公式14 hostと完全一致する`https` URLだけをAsk候補にします。明示port、userinfo、許可外host、suffix偽装host、URL側の秘密情報らしきkeywordはDenyします。percent decodeは1回だけ行います。Hook自身はHTTP通信もredirect追跡も行いません。

Issue #51では合成JSONによる単体テストだけを行いました。Issue #52ではbare `WebFetch` Askへの設定変更後に、`code.claude.com`と`laravel.com`でWebFetchが成功し、未登録subdomainの`sub.code.claude.com`が`Host not allowed`で拒否されることを人間が確認済みです。`/status`、`/permissions`、`/hooks`による設定ソースとHook登録、timeout 5秒の確認、およびbare `WebFetch` denyへのフォールバックとaskへの再適用も完了しています。その他の境界条件は、個別の結果が記録されるまで確認済みとは扱いません。

## 固定permissionDecisionReason

入力内容、URL、prompt、path、環境変数値は理由へ含めません。

```text
Canonical command requires approval
Unregistered command
Compound command not allowed
Unsafe shell syntax
Secret-like path
Repository mismatch
GitHub CLI option not allowed
GitHub CLI environment override
Host not allowed
Unsafe URL
Secret-like URL
Malformed input
Unsupported field
Background execution not allowed
Internal policy error
```

`Secret-like path`は、path判定だけでなく、`echo`のliteralに設計書§12.2の秘密情報keywordが含まれる場合にも使用します。

## 回帰テスト

実行主体は人間です。次の完全一致commandを使用します。

```bash
python3 -m unittest discover -s .claude/hooks/tests -p "test_*.py"
```

実行時期:

- Hook実装時
- PR作成前
- Claude Code更新後
- GitHub CLI更新後

実token、`.env`、実ログ、本番repository、実WebFetch通信は使用せず、合成JSONだけで検証します。

## Hook自身が行わないこと

- repository内file、transcript、`.env`の読み取り
- HTTP通信、redirect追跡
- subprocess、Git、GitHub CLIの実行
- 入力JSON、URL、prompt、環境変数値の保存・出力
- ログファイルの作成
- 高度なshell解析や高度な秘密情報検出

`GH_REPO`と`GH_HOST`は、`gh` commandを判定するときだけHook実行環境で存在有無を確認します。値は比較、保存、出力しません。

## 異常時

Hook error、起動失敗、異常終了、timeoutが表示された場合は、そのセッションで追加のBashとWebFetchを承認しません。Claude Codeを終了し、設計書「フォールバック」の手順に従ってbare `WebFetch`をAskからDenyへ戻し、settings、Hook登録、bare `Bash` Ask、復元したbare `WebFetch` Denyを確認してから再開します。Hook本体、回帰テスト、README、Hook登録はこのフォールバックでは戻しません。

すべてのBashまたはWebFetchが`Malformed input`または`Unsupported field`でDenyされる場合は、Claude CodeのSchema変更を疑います。Hook登録を無効化して安全側へ戻したうえで、最新の公式Schemaと`COMMON_REQUIRED_FIELDS`、`COMMON_OPTIONAL_FIELDS`、tool固有fieldを突き合わせます。未知fieldを許可する方向へ先にHookを緩めません。

Hookが正常に動作していても、Askは安全の保証ではありません。承認画面では原則として今回だけの`Yes`を使用し、恒久許可を追加しません。
