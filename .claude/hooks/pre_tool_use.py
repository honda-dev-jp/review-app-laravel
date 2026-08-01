#!/usr/bin/env python3
"""BashとWebFetchを検査するClaude Code PreToolUseポリシーHook。"""

from __future__ import annotations

import json
import os
import re
import shlex
import sys
from pathlib import PurePosixPath
from typing import IO, Mapping
from urllib.parse import unquote, urlsplit


# 拒否理由からcommand、URL、path、prompt、環境変数値が漏れないよう、
# reasonは入力を含まない固定プロトコルとして扱う。
REASONS = {
    "ask": "Canonical command requires approval",
    "unregistered": "Unregistered command",
    "compound": "Compound command not allowed",
    "shell": "Unsafe shell syntax",
    "secret_path": "Secret-like path",
    "repository": "Repository mismatch",
    "gh_option": "GitHub CLI option not allowed",
    "gh_environment": "GitHub CLI environment override",
    "host": "Host not allowed",
    "url": "Unsafe URL",
    "secret_url": "Secret-like URL",
    "malformed": "Malformed input",
    "unsupported": "Unsupported field",
    "background": "Background execution not allowed",
    "internal": "Internal policy error",
}

COMMON_REQUIRED_FIELDS = {
    "session_id": str,
    "transcript_path": str,
    "cwd": str,
    "hook_event_name": str,
    "tool_name": str,
    "tool_input": dict,
    "tool_use_id": str,
}
COMMON_OPTIONAL_FIELDS = {
    "prompt_id": str,
    "permission_mode": str,
    "effort": dict,
    "agent_id": str,
    "agent_type": str,
}
BASH_FIELDS = {
    "command": str,
    "description": str,
    "timeout": (int, float),
    "run_in_background": bool,
}
WEBFETCH_FIELDS = {"url": str, "prompt": str}

REPOSITORY = "github.com/honda-dev-jp/review-app-laravel"
ISSUE_JSON_FIELDS = {
    "number",
    "title",
    "state",
    "body",
    "comments",
    "labels",
    "url",
}
PR_JSON_FIELDS = {
    "number",
    "title",
    "state",
    "body",
    "comments",
    "files",
    "commits",
    "reviews",
    "reviewDecision",
    "baseRefName",
    "headRefName",
    "mergeable",
    "statusCheckRollup",
    "url",
}
WEBFETCH_HOSTS = {
    "code.claude.com",
    "laravel.com",
    "docs.github.com",
    "cli.github.com",
    "git-scm.com",
    "getcomposer.org",
    "docs.phpunit.de",
    "phpstan.org",
    "docs.npmjs.com",
    "www.php.net",
    "v3.tailwindcss.com",
    "vite.dev",
    "nodejs.org",
    "www.xserver.ne.jp",
}
SECRET_KEYWORDS = (
    "token",
    "api_key",
    "apikey",
    "secret",
    "password",
    "passwd",
    "credential",
    "private_key",
    "access_key",
)

# EXACT_*はAskへ進めるcanonical形の正本とし、表記差を暗黙に許可しない。
# EXPLICIT_GENERAL_DENYは代表的な危険commandを可視化する一覧であり、
# 未掲載commandも最終的なclosed world判定でDenyする。
EXACT_GENERAL_COMMANDS = {
    "pwd",
    "ls",
    "ls -la",
    "command -v python3",
    "command -v git",
    "command -v gh",
    "command -v php",
    'python3 -m unittest discover -s .claude/hooks/tests -p "test_*.py"',
    "php -v",
    "python3 --version",
    # 両version commandはAsk対象の正本にも含めるが、実際の判定はGit・ghの
    # closed worldへ入る前の専用分岐が担う。特例分岐を削除すると未登録へ回帰する。
    "git --version",
    "gh --version",
    "composer --version",
    "npm --version",
    "node --version",
    "php artisan --version",
    "php -m",
    "vendor/bin/phpunit --version",
    "vendor/bin/phpstan --version",
    "./vendor/bin/sail php ./vendor/bin/pint --version",
    "composer show",
    "npm list",
    "php artisan about",
    "php artisan route:list",
    "./vendor/bin/sail artisan route:list",
}
EXACT_GIT_COMMANDS = {
    "git status --short",
    "git branch --show-current",
    "git branch -a",
    "git diff",
    "git diff --check",
}
EXPLICIT_GENERAL_DENY = {
    "cat",
    "awk",
    "sort",
    "uniq",
    "tree",
    "basename",
    "dirname",
    "realpath",
    "printf",
    "test",
    "[",
    "stat",
    "du",
    "which",
    "python",
    "curl",
    "wget",
    "ssh",
    "scp",
    "rsync",
    "touch",
    "mkdir",
    "rmdir",
    "rm",
    "mv",
    "cp",
    "dd",
    "truncate",
    "tee",
}

SIMPLE_SEARCH_RE = re.compile(r"[A-Za-z0-9_-]+\Z")
# `@`と`+`はscoped packageや正当なfile名で現れ得る一方、この位置では
# shell option、glob、展開構文として解釈されないため許可する。
SAFE_PATH_RE = re.compile(r"[A-Za-z0-9_./@+-]+\Z")
COMPOSER_PACKAGE_RE = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\Z")
NPM_PACKAGE_RE = re.compile(r"(?:@[A-Za-z0-9_.-]+/)?[A-Za-z0-9_.-]+\Z")
ENV_PREFIX_RE = re.compile(r"[A-Za-z_][A-Za-z0-9_]*=")
CONTROL_RE = re.compile(r"[\x00-\x1f\x7f]")


def decision(kind: str, reason_key: str) -> dict[str, object]:
    return {
        "hookSpecificOutput": {
            "hookEventName": "PreToolUse",
            "permissionDecision": kind,
            "permissionDecisionReason": REASONS[reason_key],
        }
    }


def ask() -> dict[str, object]:
    return decision("ask", "ask")


def deny(reason_key: str) -> dict[str, object]:
    return decision("deny", reason_key)


def _has_secret_keyword(value: str) -> bool:
    lowered = value.casefold()
    return any(keyword in lowered for keyword in SECRET_KEYWORDS)


def _validate_top_level(payload: object) -> dict[str, object] | None:
    if not isinstance(payload, dict):
        return deny("malformed")

    # 未知fieldを黙って受理すると、Schema追加時に未検査の入力がAskへ流れ得る。
    # 仕様変更を回帰テストで検出できるよう、既知fieldだけを受理する。
    allowed = set(COMMON_REQUIRED_FIELDS) | set(COMMON_OPTIONAL_FIELDS)
    if set(payload) - allowed:
        return deny("unsupported")

    for field, expected_type in COMMON_REQUIRED_FIELDS.items():
        if field not in payload or not isinstance(payload[field], expected_type):
            return deny("malformed")

    for field, expected_type in COMMON_OPTIONAL_FIELDS.items():
        if field in payload and not isinstance(payload[field], expected_type):
            return deny("malformed")

    if payload["hook_event_name"] != "PreToolUse":
        return deny("malformed")
    if payload["tool_name"] not in {"Bash", "WebFetch"}:
        return deny("unsupported")
    return None


def _validate_tool_fields(
    tool_input: dict[str, object],
    allowed_fields: Mapping[str, object],
    required_fields: set[str],
) -> dict[str, object] | None:
    if set(tool_input) - set(allowed_fields):
        return deny("unsupported")
    if not required_fields.issubset(tool_input):
        return deny("malformed")
    for field, value in tool_input.items():
        if not isinstance(value, allowed_fields[field]):
            return deny("malformed")
        # Pythonではboolがintのsubclassなので、通常のisinstanceだけではtimeout=Trueも数値になる。
        # JSON Schema上のbooleanとnumberを混同しないため、明示的に拒否する。
        if isinstance(value, bool) and allowed_fields[field] != bool:
            return deny("malformed")
    return None


def _path_is_safe(path: str) -> bool:
    # repository内の単純相対pathだけに限定し、`-`によるoption注入や
    # `:`によるGit pathspec magicを正規化せず拒否して独自parserの肥大化を避ける。
    if (
        not path
        or path.startswith(("/", "-", ":"))
        or not SAFE_PATH_RE.fullmatch(path)
        or "\\" in path
        or CONTROL_RE.search(path)
    ):
        return False
    if any(character in path for character in "*?[]{}"):
        return False
    parsed = PurePosixPath(path)
    if parsed.is_absolute() or ".." in parsed.parts or len(parsed.parts) == 0:
        return False
    if any(part in {"", ".."} for part in parsed.parts):
        return False

    normalized = parsed.as_posix()
    lowered = normalized.casefold()
    parts = tuple(part.casefold() for part in parsed.parts)

    # プロジェクトルールが例外とするrepository rootの`.env.example`だけを信頼する。
    # `config/.env.example`等へ例外を広げないため、path全体の完全一致を先に判定する。
    if lowered == ".env.example":
        return True
    if lowered == ".env" or any(part == ".env" or part.startswith(".env.") for part in parts):
        return False

    # 部分文字列で一律拒否するとCacheService.php等も誤検知する。
    # 規則を追加・レビュー・テストしやすいcomponent、suffix、既知prefixで比較する。
    protected_prefixes = (
        "storage/logs",
        "storage/framework",
        "storage/app/private",
    )
    if any(lowered == prefix or lowered.startswith(prefix + "/") for prefix in protected_prefixes):
        return False

    if any(part in {"sessions", "cache", "backup", "backups"} for part in parts):
        return False
    if parsed.suffix.casefold() in {".pem", ".key", ".p12", ".pfx", ".sql", ".sqlite"}:
        return False
    if any(
        part
        in {
            "id_rsa",
            "id_ed25519",
            "credential",
            "credentials",
            "token",
            "password",
            "secret",
            "private_key",
            "access_key",
        }
        for part in parts
    ):
        return False
    return True


def _split(command: str) -> list[str] | None:
    try:
        return shlex.split(command, posix=True)
    except ValueError:
        return None


def _common_shell_denial(command: str) -> dict[str, object] | None:
    # 先頭がcanonicalでも、pipe、redirect、置換、複合化を加えると別操作になる。
    # 個別allowlistへ振り分ける前にshell構文を拒否し、部分一致による迂回を防ぐ。
    if not command or CONTROL_RE.search(command):
        return deny("shell")
    if any(symbol in command for symbol in ("|", "&", ";", ">", "<", "(", ")")):
        return deny("compound")
    if "`" in command or "$(" in command or "$" in command:
        return deny("shell")
    if "\\" in command or "~" in command or "{" in command or "}" in command or "!" in command:
        return deny("shell")
    tokens = _split(command)
    if not tokens:
        return deny("shell")
    if ENV_PREFIX_RE.fullmatch(tokens[0]) or ENV_PREFIX_RE.match(command):
        return deny("shell")
    return None


def _path_decision(path: str) -> dict[str, object] | None:
    if _path_is_safe(path):
        return None
    return deny("secret_path")


def _evaluate_git(command: str, tokens: list[str]) -> dict[str, object]:
    # quote、空白、引数順を正規化すると元入力の差が隠れ、許可範囲が広がる。
    # 文書・テスト・承認画面と同じcanonical文字列だけをAskにする。
    if command in EXACT_GIT_COMMANDS:
        return ask()

    path = None
    if len(tokens) == 4 and tokens[:3] == ["git", "diff", "--"]:
        path = tokens[3]
    elif len(tokens) == 5 and tokens[:4] == ["git", "diff", "--cached", "--"]:
        path = tokens[4]
    elif len(tokens) == 5 and tokens[:4] == ["git", "diff", "HEAD", "--"]:
        path = tokens[4]
    if path is not None:
        if command != " ".join(tokens):
            return deny("unregistered")
        return _path_decision(path) or ask()

    match = re.fullmatch(r"git log --oneline -n ([1-9]|[1-4][0-9]|50)", command)
    if match:
        return ask()

    if len(tokens) == 5 and tokens[:2] == ["git", "grep"] and tokens[3] == "--":
        term, path = tokens[2], tokens[4]
        if command != " ".join(tokens) or not SIMPLE_SEARCH_RE.fullmatch(term) or term.startswith("-"):
            return deny("unregistered")
        return _path_decision(path) or ask()

    return deny("unregistered")


def _json_fields_are_allowed(raw: str, allowed: set[str]) -> bool:
    # field順は意味を持たせない一方、重複と未知fieldは非canonicalとして拒否する。
    # `--jq`等の式言語を導入せず、レビュー可能なfield集合だけに閉じる。
    fields = raw.split(",")
    return bool(fields) and all(fields) and len(fields) == len(set(fields)) and set(fields) <= allowed


def _evaluate_gh(command: str, tokens: list[str], environ: Mapping[str, str]) -> dict[str, object]:
    # 暗黙の接続先変更を防ぐGH環境変数検査は、影響を受けるghだけへ適用する。
    # Gitや一般Bashまで過剰にDenyせず、値も比較・保存・出力しない。
    if "GH_REPO" in environ or "GH_HOST" in environ:
        return deny("gh_environment")
    # `gh --version`だけを先にAskすると、全gh共通の環境制約に例外が生じる。
    # 順序変更による回帰を防ぐため、version確認も同じ境界の内側で判定する。
    if command == "gh --version":
        return ask()

    list_match = re.fullmatch(
        rf"gh (issue|pr) list --state (open|closed|all) --limit ([1-9]|[1-9][0-9]|100) --repo {re.escape(REPOSITORY)}",
        command,
    )
    if list_match:
        return ask()

    checks_match = re.fullmatch(
        rf"gh pr checks ([1-9][0-9]*) --repo {re.escape(REPOSITORY)}",
        command,
    )
    if checks_match:
        return ask()

    view_match = re.fullmatch(
        rf"gh (issue|pr) view ([1-9][0-9]*) --repo {re.escape(REPOSITORY)}(?: (--comments)| --json ([A-Za-z,]+))?",
        command,
    )
    if view_match:
        kind, _, comments, fields = view_match.groups()
        if comments:
            return ask()
        if fields:
            allowed = ISSUE_JSON_FIELDS if kind == "issue" else PR_JSON_FIELDS
            return ask() if _json_fields_are_allowed(fields, allowed) else deny("gh_option")
        return ask()

    if "--repo" in tokens:
        index = tokens.index("--repo")
        if index + 1 >= len(tokens) or tokens[index + 1] != REPOSITORY:
            return deny("repository")
    if any(token in {"-R", "--jq", "--web", "--watch"} or token.startswith(("--repo=", "-R=")) for token in tokens):
        return deny("gh_option")
    return deny("unregistered")


def _evaluate_find(command: str) -> dict[str, object] | None:
    # quote差を自動正規化すると許可範囲が文書上の形より広がるため、
    # 高度なshell parserを持たず、承認画面と同じdouble quote形へ固定する。
    patterns = (
        r'find ([A-Za-z0-9_./-]+) -type f',
        r'find ([A-Za-z0-9_./-]+) -name "([A-Za-z0-9_.*?-]+)"',
        r'find ([A-Za-z0-9_./-]+) -type f -name "([A-Za-z0-9_.*?-]+)"',
    )
    for pattern in patterns:
        match = re.fullmatch(pattern, command)
        if match:
            path = match.group(1)
            if not _path_is_safe(path):
                return deny("secret_path")
            return ask()
    return None


def _evaluate_general(command: str, tokens: list[str]) -> dict[str, object]:
    if command in EXACT_GENERAL_COMMANDS:
        return ask()

    if tokens[0] in EXPLICIT_GENERAL_DENY:
        return deny("unregistered")
    if tokens[0] == "python3":
        return deny("unregistered")
    if tokens[:2] in (["php", "-r"], ["node", "-e"]):
        return deny("unregistered")

    if len(tokens) == 2 and tokens[0] == "ls" and command == f"ls {tokens[1]}":
        return _path_decision(tokens[1]) or ask()
    if len(tokens) == 3 and tokens[:2] == ["ls", "-la"] and command == f"ls -la {tokens[2]}":
        return _path_decision(tokens[2]) or ask()

    if len(tokens) == 4 and tokens[0] in {"head", "tail"} and tokens[1] == "-n":
        if command != " ".join(tokens) or not tokens[2].isdigit() or not 1 <= int(tokens[2]) <= 200:
            return deny("unregistered")
        return _path_decision(tokens[3]) or ask()

    if len(tokens) == 3 and tokens[0] == "grep":
        if command != " ".join(tokens) or not SIMPLE_SEARCH_RE.fullmatch(tokens[1]) or tokens[1].startswith("-"):
            return deny("unregistered")
        return _path_decision(tokens[2]) or ask()
    if len(tokens) == 4 and tokens[:2] == ["grep", "-n"]:
        if command != " ".join(tokens) or not SIMPLE_SEARCH_RE.fullmatch(tokens[2]) or tokens[2].startswith("-"):
            return deny("unregistered")
        return _path_decision(tokens[3]) or ask()

    find_result = _evaluate_find(command)
    if find_result is not None:
        return find_result
    if tokens[0] == "find":
        return deny("unregistered")

    if len(tokens) == 3 and tokens[0] == "wc" and tokens[1] in {"-l", "-w", "-c"}:
        if command != " ".join(tokens):
            return deny("unregistered")
        return _path_decision(tokens[2]) or ask()

    sed_match = re.fullmatch(r"sed -n '([1-9][0-9]*),([1-9][0-9]*)p' ([A-Za-z0-9_./-]+)", command)
    if sed_match:
        start, end, path = int(sed_match.group(1)), int(sed_match.group(2)), sed_match.group(3)
        if end < start or end - start + 1 > 200:
            return deny("unregistered")
        return _path_decision(path) or ask()
    if tokens[0] == "sed":
        return deny("unregistered")

    echo_match = re.fullmatch(r'echo (?:([A-Za-z0-9_.,:/@+-]+)|"([A-Za-z0-9_ .,:/@+-]+)")', command)
    if echo_match:
        literal = echo_match.group(1) or echo_match.group(2)
        return deny("secret_path") if _has_secret_keyword(literal) else ask()
    if tokens[0] == "echo":
        return deny("unregistered")

    if len(tokens) == 3 and tokens[:2] == ["composer", "show"]:
        if command == " ".join(tokens) and not tokens[2].startswith("-") and COMPOSER_PACKAGE_RE.fullmatch(tokens[2]):
            return ask()
        return deny("unregistered")
    if len(tokens) == 3 and tokens[:2] == ["npm", "list"]:
        if command == " ".join(tokens) and not tokens[2].startswith("-") and NPM_PACKAGE_RE.fullmatch(tokens[2]):
            return ask()
        return deny("unregistered")

    # 品質確認はレビュー対象の単一pathへ限定し、全体実行による時間・対象範囲の
    # 意図しない拡大を防ぐ。PHPUnitはtests/配下だけを対象にする。
    quality_shapes = (
        (["./vendor/bin/sail", "artisan", "test"], 3, "tests/"),
        (["./vendor/bin/sail", "php", "./vendor/bin/phpstan", "analyse"], 4, None),
    )
    for prefix, path_index, required_prefix in quality_shapes:
        if len(tokens) == path_index + 1 and tokens[:path_index] == prefix and command == " ".join(tokens):
            path = tokens[path_index]
            if required_prefix and not path.startswith(required_prefix):
                return deny("unregistered")
            return _path_decision(path) or ask()

    # Pintは`--test`がないとファイルを変更するため、確認専用形だけをAskへ進める。
    pint_prefix = ["./vendor/bin/sail", "php", "./vendor/bin/pint"]
    if len(tokens) == 5 and tokens[:3] == pint_prefix and tokens[4] == "--test" and command == " ".join(tokens):
        return _path_decision(tokens[3]) or ask()

    return deny("unregistered")


def evaluate_bash(tool_input: dict[str, object], environ: Mapping[str, str]) -> dict[str, object]:
    field_error = _validate_tool_fields(tool_input, BASH_FIELDS, {"command"})
    if field_error:
        return field_error
    if tool_input.get("run_in_background") is True:
        return deny("background")

    command = tool_input["command"]
    assert isinstance(command, str)

    # 一般的な環境変数prefix判定より先にGH overrideを識別し、値を調べたり露出したりせず
    # 専用の固定reasonで拒否する。これは実行を許可する例外ではない。
    if re.match(r"(?:GH_REPO|GH_HOST)=\S+\s+gh(?:\s|$)", command):
        return deny("gh_environment")

    # canonicalな先頭部分にpipe、redirect、置換を足す迂回を防ぐため、
    # shell共通Denyはすべてのcommand別allowlistより先に評価する。
    shell_error = _common_shell_denial(command)
    if shell_error:
        return shell_error
    tokens = _split(command)
    if not tokens:
        return deny("shell")

    # `git --version`は一般的な環境確認として認める意図的な例外であり、
    # Gitのclosed worldへ先にdispatchすると未登録GitとしてDenyされる。
    if command == "git --version":
        return ask()
    if tokens[0] == "git":
        return _evaluate_git(command, tokens)
    if tokens[0] == "gh":
        return _evaluate_gh(command, tokens, environ)
    return _evaluate_general(command, tokens)


def evaluate_webfetch(tool_input: dict[str, object]) -> dict[str, object]:
    field_error = _validate_tool_fields(tool_input, WEBFETCH_FIELDS, {"url", "prompt"})
    if field_error:
        return field_error

    url = tool_input["url"]
    prompt = tool_input["prompt"]
    assert isinstance(url, str) and isinstance(prompt, str)
    if CONTROL_RE.search(url) or CONTROL_RE.search(prompt):
        return deny("url")

    try:
        parsed = urlsplit(url)
        host = parsed.hostname
        port = parsed.port
    except ValueError:
        return deny("url")

    if parsed.scheme != "https" or not host or parsed.username is not None or parsed.password is not None:
        return deny("url")
    if port is not None or ":" in parsed.netloc:
        return deny("url")
    # suffix偽装hostや未確認subdomainを許可せず、公式host集合を暗黙に広げないため、
    # suffix判定ではなくhostの完全一致を要求する。
    if host not in WEBFETCH_HOSTS:
        return deny("host")
    # 1回encodeされた秘密語は検出するが、複数回decodeしてHook独自のURL解釈を作らない。
    # Claude Codeや接続先との解釈差はIssue #52の実機確認へ残す。
    if _has_secret_keyword(url) or _has_secret_keyword(unquote(url)):
        return deny("secret_url")
    # prompt中のtokenやcredentialは公式文書を調べる正常な用語でもあるためDenyしない。
    # 外部送信されない保証ではなく、安全候補もAskとして人間の最終確認へ残す。
    return ask()


def evaluate_input(payload: object, environ: Mapping[str, str] | None = None) -> dict[str, object]:
    top_level_error = _validate_top_level(payload)
    if top_level_error:
        return top_level_error

    assert isinstance(payload, dict)
    tool_input = payload["tool_input"]
    assert isinstance(tool_input, dict)
    # 実運用ではHook processの環境を検査し、テストでは合成環境を注入して再現性を保つ。
    # GH overrideは値を扱わず存在だけをBash内のgh判定へ渡し、WebFetchには適用しない。
    if payload["tool_name"] == "Bash":
        return evaluate_bash(tool_input, os.environ if environ is None else environ)
    return evaluate_webfetch(tool_input)


def run(stdin: IO[str], stdout: IO[str], environ: Mapping[str, str] | None = None) -> int:
    try:
        payload = json.loads(stdin.read())
        result = evaluate_input(payload, environ)
    except json.JSONDecodeError:
        result = deny("malformed")
    except Exception:
        # policy上の例外はexit code 0の構造化Denyへ一本化し、例外内容を出力しない。
        # プロセス起動失敗等はHook内で隠さず、文書化した人間のfallbackへ委ねる。
        result = deny("internal")
    json.dump(result, stdout, ensure_ascii=True, separators=(",", ":"))
    stdout.write("\n")
    return 0


def main() -> int:
    return run(sys.stdin, sys.stdout)


if __name__ == "__main__":
    raise SystemExit(main())
