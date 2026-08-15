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
from urllib.parse import urlsplit


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
LEGACY_WEBFETCH_HOSTS = frozenset(
    {
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
)

# Issue #89で追加するhostはpathもclosed worldにする。既存14hostは従来の
# host固定を維持し、後続Issueへ無関係な権限変更を混在させない。
WEBFETCH_EXACT_PATHS = {
    "www.themoviedb.org": {
        "/documentation/api/terms-of-use",
        "/about/logos-attribution",
    },
    "playwright.dev": {
        "/docs/intro",
        "/docs/browsers",
        "/docs/ci",
        "/docs/docker",
        "/docs/test-configuration",
        "/docs/trace-viewer",
        "/docs/release-notes",
    },
}
WEBFETCH_PATH_PREFIXES = {
    "developer.themoviedb.org": ("/docs/", "/reference/"),
    "www.typescriptlang.org": (
        "/docs/",
        "/docs/handbook/",
        "/docs/handbook/release-notes/",
        "/tsconfig/",
    ),
    "playwright.dev": ("/docs/api/",),
    "dev.mysql.com": ("/doc/refman/8.4/en/",),
    "docs.docker.com": ("/compose/",),
}

COMPOSER_METADATA_PACKAGES = {
    "askdkc/breezejp",
    "barryvdh/laravel-ide-helper",
    "fakerphp/faker",
    "guzzlehttp/guzzle",
    "larastan/larastan",
    "laravel/breeze",
    "laravel/framework",
    "laravel/pint",
    "laravel/sail",
    "laravel/sanctum",
    "laravel/tinker",
    "mockery/mockery",
    "nunomaduro/collision",
    "phpunit/phpunit",
    "spatie/laravel-ignition",
}
NPM_METADATA_PACKAGES = {
    "@playwright/test",
    "@tailwindcss/forms",
    "alpinejs",
    "autoprefixer",
    "axios",
    "laravel-vite-plugin",
    "postcss",
    "tailwindcss",
    "typescript",
    "vite",
}
# package rootのfull packumentはWebFetchの応答上限を超え得る。応答を抑え、
# 任意version/dist-tagへ範囲を広げないため、literal `latest`だけに固定する。
NPM_METADATA_PATHS = frozenset(
    f"/{package}/latest" for package in NPM_METADATA_PACKAGES
)

# restricted hostの分類はpath正本から導出する。分類されていないhostを
# WEBFETCH_HOSTSへ単独追加できない形にし、将来の追加漏れをfail-closedにする。
RESTRICTED_WEBFETCH_HOSTS = (
    frozenset(WEBFETCH_EXACT_PATHS)
    | frozenset(WEBFETCH_PATH_PREFIXES)
    | {"repo.packagist.org", "registry.npmjs.org"}
)
WEBFETCH_HOSTS = LEGACY_WEBFETCH_HOSTS | RESTRICTED_WEBFETCH_HOSTS

ADVISORY_HELPER = ".claude/helpers/github_global_advisories.py"
DEPENDABOT_HELPER = ".claude/helpers/github_dependabot_alerts.py"
ACTIONS_RUNS_HELPER = ".claude/helpers/github_actions_runs.py"
SAVE_LOCAL_ARTIFACT_HELPER = (
    ".claude/skills/save-local-artifact/scripts/save_local_artifact.py"
)
SAVE_LOCAL_ARTIFACT_CATEGORIES = frozenset({"reports", "handoffs", "scratch"})
# Claude Code E2E transportのclosed-world境界。Hookからhelperをimportせず独立
# 定義するが同値を必須とし、helper側とのdriftはsource-sync回帰testで検出する。
SAVE_LOCAL_ARTIFACT_MAX_ENCODED_BYTES = 2_048
MAX_DEPENDABOT_ALERT_NUMBER = 2**63 - 1
MAX_ACTIONS_RUN_ID = 2**63 - 1
ADVISORY_ECOSYSTEM_PACKAGES = {
    "composer": COMPOSER_METADATA_PACKAGES,
    "npm": NPM_METADATA_PACKAGES,
}
# ci.ymlで実際に使用するAction repositoryと同期するclosed worldとし、
# CI Action追加時のallowlist更新漏れを完全一致testで拒否する。
ACTION_RELEASE_REPOSITORIES = {
    "github.com/actions/checkout",
    "github.com/actions/setup-node",
    "github.com/actions/setup-python",
    "github.com/astral-sh/ruff-action",
    "github.com/shivammathur/setup-php",
}
ACTION_RELEASE_LIST_JSON = "tagName,name,publishedAt,isDraft,isPrerelease"
ACTION_RELEASE_VIEW_JSON = "tagName,name,publishedAt,isDraft,isPrerelease,url"
GH_RELEASE_ENVIRONMENT_OVERRIDES = {
    "GH_DEBUG",
    "GH_FORCE_TTY",
    "GH_PAGER",
    "PAGER",
    "NO_COLOR",
    "CLICOLOR",
    "CLICOLOR_FORCE",
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
GHSA_ID_RE = re.compile(
    r"GHSA-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}\Z"
)
RELEASE_TAG_RE = re.compile(r"[A-Za-z0-9][A-Za-z0-9._+-]{0,99}\Z")
SAVE_LOCAL_ARTIFACT_FILENAME_RE = re.compile(
    r"[A-Za-z0-9][A-Za-z0-9_-]{0,62}\.(?:md|txt)\Z", re.ASCII
)
SAVE_LOCAL_ARTIFACT_DIGEST_RE = re.compile(r"[0-9a-f]{64}\Z", re.ASCII)
SAVE_LOCAL_ARTIFACT_PAYLOAD_RE = re.compile(r"[A-Za-z0-9_-]*\Z", re.ASCII)


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
        if isinstance(value, bool) and allowed_fields[field] is not bool:
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
    if lowered == ".env" or any(
        part == ".env" or part.startswith(".env.") for part in parts
    ):
        return False

    # 部分文字列で一律拒否するとCacheService.php等も誤検知する。
    # 規則を追加・レビュー・テストしやすいcomponent、suffix、既知prefixで比較する。
    protected_prefixes = (
        "storage/logs",
        "storage/framework",
        "storage/app/private",
    )
    if any(
        lowered == prefix or lowered.startswith(prefix + "/")
        for prefix in protected_prefixes
    ):
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
    if (
        "\\" in command
        or "~" in command
        or "{" in command
        or "}" in command
        or "!" in command
    ):
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
        if (
            command != " ".join(tokens)
            or not SIMPLE_SEARCH_RE.fullmatch(term)
            or term.startswith("-")
        ):
            return deny("unregistered")
        return _path_decision(path) or ask()

    return deny("unregistered")


def _json_fields_are_allowed(raw: str, allowed: set[str]) -> bool:
    # field順は意味を持たせない一方、重複と未知fieldは非canonicalとして拒否する。
    # `--jq`等の式言語を導入せず、レビュー可能なfield集合だけに閉じる。
    fields = raw.split(",")
    return (
        bool(fields)
        and all(fields)
        and len(fields) == len(set(fields))
        and set(fields) <= allowed
    )


def _evaluate_advisory_helper(
    command: str, tokens: list[str]
) -> dict[str, object] | None:
    """専用helperのrepository相対canonical形だけを一般python3 Denyより先に扱う。"""
    prefix = ["python3", ADVISORY_HELPER]
    if tokens[:2] != prefix:
        return None
    if command != " ".join(tokens):
        return deny("unregistered")
    if len(tokens) == 4 and tokens[2] == "view" and GHSA_ID_RE.fullmatch(tokens[3]):
        return ask()
    if (
        len(tokens) == 7
        and tokens[2:4] == ["list", "--ecosystem"]
        and tokens[5] == "--package"
    ):
        ecosystem, package = tokens[4], tokens[6]
        if package in ADVISORY_ECOSYSTEM_PACKAGES.get(ecosystem, set()):
            return ask()
    return deny("unregistered")


def _evaluate_dependabot_helper(
    command: str, tokens: list[str]
) -> dict[str, object] | None:
    """一般python3 Denyより先にcanonical helperだけをAsk候補へ昇格する。"""
    prefix = ["python3", DEPENDABOT_HELPER]
    if tokens[:2] != prefix:
        return None
    if command != " ".join(tokens):
        return deny("unregistered")
    if tokens == [*prefix, "list"]:
        return ask()
    if len(tokens) == 4 and tokens[2] == "view":
        alert_number = tokens[3]
        if (
            len(alert_number) <= len(str(MAX_DEPENDABOT_ALERT_NUMBER))
            and re.fullmatch(r"[1-9][0-9]*", alert_number)
            and int(alert_number) <= MAX_DEPENDABOT_ALERT_NUMBER
        ):
            return ask()
    return deny("unregistered")


def _evaluate_actions_runs_helper(
    command: str, tokens: list[str]
) -> dict[str, object] | None:
    """run ID以外を可変にせず、2つのcanonical helper形だけをAsk候補にする。"""
    prefix = ["python3", ACTIONS_RUNS_HELPER]
    if tokens[:2] != prefix:
        return None
    if command != " ".join(tokens):
        return deny("unregistered")
    if tokens == [*prefix, "list"]:
        return ask()
    if len(tokens) == 4 and tokens[2] == "view":
        run_id = tokens[3]
        # leading zeroを拒否し、helperとHookでcanonical表現を一致させる。
        if (
            len(run_id) <= len(str(MAX_ACTIONS_RUN_ID))
            and re.fullmatch(r"[1-9][0-9]*", run_id)
            and int(run_id) <= MAX_ACTIONS_RUN_ID
        ):
            return ask()
    return deny("unregistered")


def _evaluate_save_local_artifact_helper(
    command: str, tokens: list[str]
) -> dict[str, object] | None:
    """限定保存helperの2つのcanonical形だけをAsk候補にする。"""
    # Hookでpath解決まで担うとcommand shapeとfilesystem境界が混ざるため、
    # 固定相対pathだけを比較し、実path・symlink・root検証はhelperへ一元化する。
    prefix = ["python3", SAVE_LOCAL_ARTIFACT_HELPER]
    if tokens[:2] != prefix:
        return None

    category: str
    filename: str
    digest: str | None
    payload_option: str
    if (
        len(tokens) == 8
        and tokens[2] == "preflight"
        and tokens[3] == "--category"
        and tokens[5] == "--filename"
        and tokens[7].startswith("--content-base64url=")
    ):
        category = tokens[4]
        filename = tokens[6]
        digest = None
        payload_option = tokens[7]
    elif (
        len(tokens) == 10
        and tokens[2] == "save"
        and tokens[3] == "--category"
        and tokens[5] == "--filename"
        and tokens[7] == "--confirmation-digest"
        and tokens[9].startswith("--content-base64url=")
    ):
        category = tokens[4]
        filename = tokens[6]
        digest = tokens[8]
        payload_option = tokens[9]
    else:
        return deny("unregistered")

    # 非信頼本文を承認境界へ持ち込まず責務を重複させないため、Hookはencoded
    # shapeと上限だけを見て、decode・UTF-8・Unicode検証はhelperへ一元化する。
    payload = payload_option.removeprefix("--content-base64url=")
    # helper到達後に拒否できるだけではAsk範囲が広がるため、保存先・digest・
    # payload shapeはHookでも検査し、承認候補自体をclosed worldに保つ。
    if (
        category not in SAVE_LOCAL_ARTIFACT_CATEGORIES
        or not SAVE_LOCAL_ARTIFACT_FILENAME_RE.fullmatch(filename)
        or (digest is not None and not SAVE_LOCAL_ARTIFACT_DIGEST_RE.fullmatch(digest))
        or len(payload) > SAVE_LOCAL_ARTIFACT_MAX_ENCODED_BYTES
        or not SAVE_LOCAL_ARTIFACT_PAYLOAD_RE.fullmatch(payload)
    ):
        return deny("unregistered")

    canonical_tokens = [
        *prefix,
        tokens[2],
        "--category",
        category,
        "--filename",
        filename,
    ]
    if digest is not None:
        canonical_tokens.extend(("--confirmation-digest", digest))
    canonical_tokens.append(f"--content-base64url={payload}")
    # shlex上で同じtokenでもquote・空白差を許すとcommand shapeが広がるため、
    # 検証済み値から再構築した一義的な文字列との完全一致だけをAskにする。
    if command != " ".join(canonical_tokens):
        return deny("unregistered")
    # Hook reasonは承認UIやlogへ露出し得るため入力値を反映せず、固定reasonだけを
    # 再利用してpayload・digest・filename・raw commandの漏洩経路を増やさない。
    return ask()


def _evaluate_action_release(
    command: str, tokens: list[str]
) -> dict[str, object] | None:
    """通常repository固定とは分離した、現行CI ActionのRelease専用経路。"""
    if tokens[:2] != ["gh", "release"]:
        return None

    list_match = re.fullmatch(
        r"gh release list --limit 20 --repo (github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+) "
        r"--json ([A-Za-z,]+)",
        command,
    )
    if list_match:
        repository, fields = list_match.groups()
        if repository not in ACTION_RELEASE_REPOSITORIES:
            return deny("repository")
        return ask() if fields == ACTION_RELEASE_LIST_JSON else deny("gh_option")

    view_match = re.fullmatch(
        r"gh release view ([A-Za-z0-9][A-Za-z0-9._+-]{0,99}) --repo "
        r"(github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+) --json ([A-Za-z,]+)",
        command,
    )
    if view_match:
        tag, repository, fields = view_match.groups()
        if repository not in ACTION_RELEASE_REPOSITORIES:
            return deny("repository")
        # parserとは別に最終値も検査し、regex変更時にtag境界が暗黙に広がるのを防ぐ。
        if not RELEASE_TAG_RE.fullmatch(tag):
            return deny("gh_option")
        return ask() if fields == ACTION_RELEASE_VIEW_JSON else deny("gh_option")
    return deny("unregistered")


def _evaluate_gh(
    command: str, tokens: list[str], environ: Mapping[str, str]
) -> dict[str, object]:
    # 暗黙の接続先変更を防ぐGH環境変数検査は、影響を受けるghだけへ適用する。
    # Gitや一般Bashまで過剰にDenyせず、値も比較・保存・出力しない。
    if "GH_REPO" in environ or "GH_HOST" in environ:
        return deny("gh_environment")
    # `gh --version`だけを先にAskすると、全gh共通の環境制約に例外が生じる。
    # 順序変更による回帰を防ぐため、version確認も同じ境界の内側で判定する。
    if command == "gh --version":
        return ask()

    # 値に関係なく存在だけで拒否し、canonical commandの出力・実行挙動が
    # callerのdebug、TTY、pager、color設定で変化するのを防ぐ。
    if tokens[:2] == [
        "gh",
        "release",
    ] and GH_RELEASE_ENVIRONMENT_OVERRIDES.intersection(environ):
        return deny("gh_environment")
    release_result = _evaluate_action_release(command, tokens)
    if release_result is not None:
        return release_result

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
            return (
                ask()
                if _json_fields_are_allowed(fields, allowed)
                else deny("gh_option")
            )
        return ask()

    if "--repo" in tokens:
        index = tokens.index("--repo")
        if index + 1 >= len(tokens) or tokens[index + 1] != REPOSITORY:
            return deny("repository")
    if any(
        token in {"-R", "--jq", "--web", "--watch"}
        or token.startswith(("--repo=", "-R="))
        for token in tokens
    ):
        return deny("gh_option")
    return deny("unregistered")


def _evaluate_find(command: str) -> dict[str, object] | None:
    # quote差を自動正規化すると許可範囲が文書上の形より広がるため、
    # 高度なshell parserを持たず、承認画面と同じdouble quote形へ固定する。
    patterns = (
        r"find ([A-Za-z0-9_./-]+) -type f",
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
    # 一般python3を解禁せず、固定したpreflight/saveだけを例外として
    # Askへ進めるため、汎用python3 Denyより先に専用形を評価する。
    artifact_result = _evaluate_save_local_artifact_helper(command, tokens)
    if artifact_result is not None:
        return artifact_result

    actions_result = _evaluate_actions_runs_helper(command, tokens)
    if actions_result is not None:
        return actions_result

    dependabot_result = _evaluate_dependabot_helper(command, tokens)
    if dependabot_result is not None:
        return dependabot_result

    advisory_result = _evaluate_advisory_helper(command, tokens)
    if advisory_result is not None:
        return advisory_result

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
    if (
        len(tokens) == 3
        and tokens[:2] == ["ls", "-la"]
        and command == f"ls -la {tokens[2]}"
    ):
        return _path_decision(tokens[2]) or ask()

    if len(tokens) == 4 and tokens[0] in {"head", "tail"} and tokens[1] == "-n":
        if (
            command != " ".join(tokens)
            or not tokens[2].isdigit()
            or not 1 <= int(tokens[2]) <= 200
        ):
            return deny("unregistered")
        return _path_decision(tokens[3]) or ask()

    if len(tokens) == 3 and tokens[0] == "grep":
        if (
            command != " ".join(tokens)
            or not SIMPLE_SEARCH_RE.fullmatch(tokens[1])
            or tokens[1].startswith("-")
        ):
            return deny("unregistered")
        return _path_decision(tokens[2]) or ask()
    if len(tokens) == 4 and tokens[:2] == ["grep", "-n"]:
        if (
            command != " ".join(tokens)
            or not SIMPLE_SEARCH_RE.fullmatch(tokens[2])
            or tokens[2].startswith("-")
        ):
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

    sed_match = re.fullmatch(
        r"sed -n '([1-9][0-9]*),([1-9][0-9]*)p' ([A-Za-z0-9_./-]+)", command
    )
    if sed_match:
        start, end, path = (
            int(sed_match.group(1)),
            int(sed_match.group(2)),
            sed_match.group(3),
        )
        if end < start or end - start + 1 > 200:
            return deny("unregistered")
        return _path_decision(path) or ask()
    if tokens[0] == "sed":
        return deny("unregistered")

    echo_match = re.fullmatch(
        r'echo (?:([A-Za-z0-9_.,:/@+-]+)|"([A-Za-z0-9_ .,:/@+-]+)")', command
    )
    if echo_match:
        literal = echo_match.group(1) or echo_match.group(2)
        return deny("secret_path") if _has_secret_keyword(literal) else ask()
    if tokens[0] == "echo":
        return deny("unregistered")

    if len(tokens) == 3 and tokens[:2] == ["composer", "show"]:
        if (
            command == " ".join(tokens)
            and not tokens[2].startswith("-")
            and COMPOSER_PACKAGE_RE.fullmatch(tokens[2])
        ):
            return ask()
        return deny("unregistered")
    if len(tokens) == 3 and tokens[:2] == ["npm", "list"]:
        if (
            command == " ".join(tokens)
            and not tokens[2].startswith("-")
            and NPM_PACKAGE_RE.fullmatch(tokens[2])
        ):
            return ask()
        return deny("unregistered")

    # 品質確認はレビュー対象の単一pathへ限定し、全体実行による時間・対象範囲の
    # 意図しない拡大を防ぐ。PHPUnitはtests/配下だけを対象にする。
    quality_shapes = (
        (["./vendor/bin/sail", "artisan", "test"], 3, "tests/"),
        (["./vendor/bin/sail", "php", "./vendor/bin/phpstan", "analyse"], 4, None),
    )
    for prefix, path_index, required_prefix in quality_shapes:
        if (
            len(tokens) == path_index + 1
            and tokens[:path_index] == prefix
            and command == " ".join(tokens)
        ):
            path = tokens[path_index]
            if required_prefix and not path.startswith(required_prefix):
                return deny("unregistered")
            return _path_decision(path) or ask()

    # Pintは`--test`がないとファイルを変更するため、確認専用形だけをAskへ進める。
    pint_prefix = ["./vendor/bin/sail", "php", "./vendor/bin/pint"]
    if (
        len(tokens) == 5
        and tokens[:3] == pint_prefix
        and tokens[4] == "--test"
        and command == " ".join(tokens)
    ):
        return _path_decision(tokens[3]) or ask()

    return deny("unregistered")


def evaluate_bash(
    tool_input: dict[str, object], environ: Mapping[str, str]
) -> dict[str, object]:
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


def _webfetch_path_is_allowed(host: str, path: str) -> bool:
    if host in LEGACY_WEBFETCH_HOSTS:
        return True
    if host not in RESTRICTED_WEBFETCH_HOSTS:
        return False
    if host == "repo.packagist.org":
        return any(
            path == f"/p2/{package}.json" for package in COMPOSER_METADATA_PACKAGES
        )
    if host == "registry.npmjs.org":
        return path in NPM_METADATA_PATHS

    exact = WEBFETCH_EXACT_PATHS.get(host, set())
    if path in exact:
        return True
    prefixes = WEBFETCH_PATH_PREFIXES.get(host)
    if prefixes is not None:
        return any(path.startswith(prefix) for prefix in prefixes)
    return False


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

    if (
        parsed.scheme != "https"
        or not host
        or parsed.username is not None
        or parsed.password is not None
    ):
        return deny("url")
    if port is not None or ":" in parsed.netloc:
        return deny("url")
    # suffix偽装hostや未確認subdomainを許可せず、公式host集合を暗黙に広げないため、
    # suffix判定ではなくhostの完全一致を要求する。
    if host not in WEBFETCH_HOSTS:
        return deny("host")

    # URL解釈差を作らないため、percent encodingは正当・不正を問わずcanonical URLでは
    # 使用しない。これによりencoded separator、double encoding、秘密語のencodeを同時に閉じる。
    if "%" in url:
        return deny("url")
    path = parsed.path or "/"
    if "\\" in path or "//" in path:
        return deny("url")
    segments = path.split("/")
    if any(segment in {".", ".."} for segment in segments):
        return deny("url")
    if _has_secret_keyword(url):
        return deny("secret_url")

    restricted_host = host in RESTRICTED_WEBFETCH_HOSTS
    # #89で追加した有限pathはquery/fragmentで別resourceへ変化させない。
    if restricted_host and (parsed.query or parsed.fragment):
        return deny("url")
    lowered_segments = {segment.casefold() for segment in segments}
    if restricted_host and (
        lowered_segments.intersection({"download", "downloads"})
        or path.casefold().endswith(
            (
                ".zip",
                ".tar",
                ".tar.gz",
                ".tgz",
                ".gz",
                ".exe",
                ".dmg",
                ".pkg",
                ".deb",
                ".rpm",
                ".msi",
                ".whl",
            )
        )
    ):
        return deny("url")
    if not _webfetch_path_is_allowed(host, path):
        return deny("url")
    # prompt中のtokenやcredentialは公式文書を調べる正常な用語でもあるためDenyしない。
    # 外部送信されない保証ではなく、安全候補もAskとして人間の最終確認へ残す。
    return ask()


def evaluate_input(
    payload: object, environ: Mapping[str, str] | None = None
) -> dict[str, object]:
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


def run(
    stdin: IO[str], stdout: IO[str], environ: Mapping[str, str] | None = None
) -> int:
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
