#!/usr/bin/env python3
"""GitHub Global Security Advisoriesを固定read-only形で取得・縮約する。"""

from __future__ import annotations

import json
import os
import pwd
import re
import selectors
import subprocess
import sys
import time
from typing import Mapping, Sequence
from urllib.parse import parse_qs, urlencode, urlsplit


GHSA_ID_RE = re.compile(
    r"GHSA-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}\Z"
)
# `&`、`?`、`%`、`/`等を受理せず、cursorが固定queryの構造を変えないようにする。
CURSOR_RE = re.compile(r"[A-Za-z0-9._~=-]{1,512}\Z")
CONTROL_RE = re.compile(r"[\x00-\x1f\x7f]")
TIMESTAMP_RE = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\Z")

PACKAGES = {
    "composer": {
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
    },
    "npm": {
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
    },
}

ACCEPT_HEADER = "Accept: application/vnd.github+json"
VERSION_HEADER = "X-GitHub-Api-Version: 2022-11-28"
# 外部応答が予期せず増大しても、memory・実行時間・端末出力を有限に保つ。
# いずれかの上限超過時は部分結果を返さず、request全体をfail-closedにする。
PER_PAGE = 50
MAX_PAGES = 3
MAX_ADVISORIES = PER_PAGE * MAX_PAGES
MAX_RESPONSE_BYTES = 512 * 1024
MAX_TOTAL_BYTES = 1024 * 1024
MAX_HEADER_BYTES = 32 * 1024
MAX_OUTPUT_BYTES = 256 * 1024
MAX_STRING_CHARS = 4096
TIMEOUT_SECONDS = 20.0


class PolicyError(Exception):
    """入力、外部応答、subprocessの固定契約違反。"""


def build_argv(endpoint: str) -> list[str]:
    return [
        "gh",
        "api",
        endpoint,
        "--hostname",
        "github.com",
        "--method",
        "GET",
        "--include",
        "--header",
        ACCEPT_HEADER,
        "--header",
        VERSION_HEADER,
    ]


def build_list_endpoint(ecosystem: str, package: str, cursor: str | None = None) -> str:
    if package not in PACKAGES.get(ecosystem, set()):
        raise PolicyError
    values = [
        ("ecosystem", ecosystem),
        ("affects", package),
        ("per_page", str(PER_PAGE)),
    ]
    if cursor is not None:
        if not CURSOR_RE.fullmatch(cursor):
            raise PolicyError
        values.append(("after", cursor))
    return "/advisories?" + urlencode(values)


def build_view_endpoint(ghsa_id: str) -> str:
    if not GHSA_ID_RE.fullmatch(ghsa_id):
        raise PolicyError
    return f"/advisories/{ghsa_id}"


def _subprocess_environment() -> dict[str, str]:
    # HOMEは認証済みghの設定探索に必要だが、process環境からは継承しない。
    # callerのPATH先頭にある別ghやwrapperを実行しない。固定PATHにghがなければ
    # 実行不能としてfail-closedでよい。
    home = pwd.getpwuid(os.getuid()).pw_dir
    return {
        "HOME": home,
        "PATH": "/usr/local/bin:/usr/bin:/bin",
        "LANG": "C.UTF-8",
        "LC_ALL": "C.UTF-8",
        "TERM": "dumb",
        "NO_COLOR": "1",
        "CLICOLOR": "0",
        "CLICOLOR_FORCE": "0",
        "GH_PAGER": "cat",
        "PAGER": "cat",
        "GH_PROMPT_DISABLED": "1",
    }


def _endpoint_is_allowed(endpoint: str) -> bool:
    if re.fullmatch(
        r"/advisories/GHSA-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}",
        endpoint,
    ):
        return True
    parsed = urlsplit(endpoint)
    if parsed.path != "/advisories" or not parsed.query or parsed.fragment:
        return False
    try:
        query = parse_qs(parsed.query, keep_blank_values=True, strict_parsing=True)
    except ValueError:
        return False
    expected_keys = {"ecosystem", "affects", "per_page"}
    if "after" in query:
        expected_keys.add("after")
    if set(query) != expected_keys or any(
        len(values) != 1 for values in query.values()
    ):
        return False
    ecosystem, package = query["ecosystem"][0], query["affects"][0]
    cursor = query.get("after", [None])[0]
    try:
        return endpoint == build_list_endpoint(ecosystem, package, cursor)
    except PolicyError:
        return False


def run_gh(argv: Sequence[str]) -> bytes:
    # 将来呼び出し元が増えても、外部processへ到達する直前の最終境界として
    # argv全体とliteral endpointを再検証する。
    if (
        len(argv) != 12
        or list(argv) != build_argv(argv[2])
        or not _endpoint_is_allowed(argv[2])
    ):
        raise PolicyError
    try:
        process = subprocess.Popen(
            list(argv),
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            env=_subprocess_environment(),
            shell=False,
            close_fds=True,
            start_new_session=True,
        )
    except (OSError, ValueError):
        raise PolicyError from None

    assert process.stdout is not None and process.stderr is not None
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ, "stdout")
    selector.register(process.stderr, selectors.EVENT_READ, "stderr")
    stdout = bytearray()
    total = 0
    deadline = time.monotonic() + TIMEOUT_SECONDS
    try:
        while selector.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise PolicyError
            events = selector.select(remaining)
            if not events:
                raise PolicyError
            for key, _ in events:
                chunk = os.read(key.fileobj.fileno(), 65536)
                if not chunk:
                    selector.unregister(key.fileobj)
                    continue
                total += len(chunk)
                if total > MAX_RESPONSE_BYTES:
                    raise PolicyError
                if key.data == "stdout":
                    stdout.extend(chunk)
        if process.wait(timeout=1) != 0:
            raise PolicyError
    except (OSError, subprocess.SubprocessError, PolicyError):
        process.kill()
        try:
            process.wait(timeout=1)
        except subprocess.SubprocessError:
            pass
        raise PolicyError from None
    finally:
        selector.close()
        process.stdout.close()
        process.stderr.close()
    return bytes(stdout)


def split_response(raw: bytes) -> tuple[Mapping[str, str], object]:
    if len(raw) > MAX_RESPONSE_BYTES:
        raise PolicyError
    separator = b"\r\n\r\n" if b"\r\n\r\n" in raw else b"\n\n"
    if separator not in raw:
        raise PolicyError
    header_raw, body_raw = raw.split(separator, 1)
    if not header_raw or len(header_raw) > MAX_HEADER_BYTES or not body_raw:
        raise PolicyError
    try:
        # HTTP headerはASCII構文として、JSON bodyはAPI契約どおりUTF-8として
        # 別々にdecodeし、曖昧な文字encodingを受理しない。
        header_text = header_raw.decode("ascii")
        body_text = body_raw.decode("utf-8")
    except UnicodeDecodeError:
        raise PolicyError from None
    lines = header_text.replace("\r\n", "\n").split("\n")
    # projection可能な正常応答だけを扱い、redirectやerror bodyをJSONとして
    # 誤って処理しないためHTTP 200以外はfail-closedにする。
    if not re.fullmatch(r"HTTP/(?:1\.[01]|2(?:\.0)?) 200(?: .*)?", lines[0]):
        raise PolicyError
    headers: dict[str, str] = {}
    for line in lines[1:]:
        if not line or ":" not in line or CONTROL_RE.search(line):
            raise PolicyError
        name, value = line.split(":", 1)
        lowered = name.strip().casefold()
        if not re.fullmatch(r"[a-z0-9-]+", lowered) or lowered in headers:
            raise PolicyError
        headers[lowered] = value.strip()

    def reject_duplicate_keys(pairs: list[tuple[str, object]]) -> dict[str, object]:
        value: dict[str, object] = {}
        for key, item in pairs:
            if key in value:
                raise PolicyError
            value[key] = item
        return value

    def reject_constant(_: str) -> object:
        raise PolicyError

    try:
        return headers, json.loads(
            body_text,
            object_pairs_hook=reject_duplicate_keys,
            parse_constant=reject_constant,
        )
    except json.JSONDecodeError:
        raise PolicyError from None


def next_cursor(link_header: str | None, ecosystem: str, package: str) -> str | None:
    if link_header is None:
        return None
    next_urls = []
    for item in link_header.split(","):
        match = re.fullmatch(r'\s*<([^>]+)>;\s*rel="([a-z]+)"\s*', item)
        if not match:
            raise PolicyError
        if match.group(2) == "next":
            next_urls.append(match.group(1))
    if len(next_urls) > 1:
        raise PolicyError
    if not next_urls:
        return None
    try:
        parsed = urlsplit(next_urls[0])
        port = parsed.port
        query = parse_qs(parsed.query, keep_blank_values=True, strict_parsing=True)
    except ValueError:
        raise PolicyError from None
    if (
        parsed.scheme != "https"
        or parsed.hostname != "api.github.com"
        or port is not None
        or parsed.username is not None
        or parsed.password is not None
        or parsed.path != "/advisories"
        or parsed.fragment
    ):
        raise PolicyError
    if set(query) != {"ecosystem", "affects", "per_page", "after"} or any(
        len(values) != 1 for values in query.values()
    ):
        raise PolicyError
    cursor = query["after"][0]
    if (
        query["ecosystem"][0] != ecosystem
        or query["affects"][0] != package
        or query["per_page"][0] != str(PER_PAGE)
    ):
        raise PolicyError
    if not CURSOR_RE.fullmatch(cursor):
        raise PolicyError
    return cursor


def _safe_string(value: object, *, nullable: bool = False) -> str | None:
    if nullable and value is None:
        return None
    if (
        not isinstance(value, str)
        or len(value) > MAX_STRING_CHARS
        or CONTROL_RE.search(value)
    ):
        raise PolicyError
    return value


def project_advisory(
    value: object, package_filter: tuple[str, str] | None = None
) -> dict[str, object]:
    if not isinstance(value, dict):
        raise PolicyError
    required = {
        "ghsa_id",
        "summary",
        "severity",
        "published_at",
        "updated_at",
        "withdrawn_at",
        "vulnerabilities",
    }
    if not required.issubset(value):
        raise PolicyError
    ghsa_id = _safe_string(value["ghsa_id"])
    assert isinstance(ghsa_id, str)
    if not GHSA_ID_RE.fullmatch(ghsa_id):
        raise PolicyError
    severity = _safe_string(value["severity"])
    if severity not in {"low", "medium", "high", "critical", "unknown"}:
        raise PolicyError
    published_at = _safe_string(value["published_at"])
    updated_at = _safe_string(value["updated_at"])
    withdrawn_at = _safe_string(value["withdrawn_at"], nullable=True)
    for timestamp in (published_at, updated_at, withdrawn_at):
        if timestamp is not None and not TIMESTAMP_RE.fullmatch(timestamp):
            raise PolicyError
    vulnerabilities = value["vulnerabilities"]
    if vulnerabilities is not None and (
        not isinstance(vulnerabilities, list) or len(vulnerabilities) > 100
    ):
        raise PolicyError
    if vulnerabilities is None:
        projected_vulnerabilities: list[dict[str, object]] | None = None
    else:
        projected_vulnerabilities = []
    for vulnerability in vulnerabilities or []:
        if not isinstance(vulnerability, dict) or not {
            "package",
            "vulnerable_version_range",
            "first_patched_version",
        }.issubset(vulnerability):
            raise PolicyError
        package = vulnerability["package"]
        if package is not None and (
            not isinstance(package, dict) or not {"ecosystem", "name"}.issubset(package)
        ):
            raise PolicyError
        if package is None:
            if package_filter is not None:
                continue
            projected_package = None
        else:
            ecosystem = _safe_string(package["ecosystem"])
            name = _safe_string(package["name"], nullable=True)
            assert isinstance(ecosystem, str)
            if package_filter is not None:
                if name is None or (ecosystem.casefold(), name) != package_filter:
                    continue
            projected_package = {"ecosystem": ecosystem, "name": name}
        assert projected_vulnerabilities is not None
        projected_vulnerabilities.append(
            {
                "package": projected_package,
                "vulnerable_version_range": _safe_string(
                    vulnerability["vulnerable_version_range"], nullable=True
                ),
                "first_patched_version": _safe_string(
                    vulnerability["first_patched_version"], nullable=True
                ),
            }
        )
    # server-side filterだけを信頼せず、要求packageと照合できるprojectionが
    # 0件なら無関係なadvisoryを返さないためfail-closedにする。
    if package_filter is not None and not projected_vulnerabilities:
        raise PolicyError
    return {
        "ghsa_id": ghsa_id,
        "summary": _safe_string(value["summary"]),
        "severity": severity,
        "published_at": published_at,
        "updated_at": updated_at,
        "withdrawn_at": withdrawn_at,
        "vulnerabilities": projected_vulnerabilities,
    }


def fetch_list(ecosystem: str, package: str) -> list[dict[str, object]]:
    cursor = None
    total_bytes = 0
    results: list[dict[str, object]] = []
    for _ in range(MAX_PAGES):
        raw = run_gh(build_argv(build_list_endpoint(ecosystem, package, cursor)))
        total_bytes += len(raw)
        if total_bytes > MAX_TOTAL_BYTES:
            raise PolicyError
        headers, body = split_response(raw)
        if not isinstance(body, list) or len(body) > PER_PAGE:
            raise PolicyError
        results.extend(project_advisory(item, (ecosystem, package)) for item in body)
        if len(results) > MAX_ADVISORIES:
            raise PolicyError
        cursor = next_cursor(headers.get("link"), ecosystem, package)
        if cursor is None:
            return results
    # 規定page数を使い切った時点では部分結果を返さず、無条件でfail-closedにする。
    raise PolicyError


def fetch_view(ghsa_id: str) -> dict[str, object]:
    raw = run_gh(build_argv(build_view_endpoint(ghsa_id)))
    _, body = split_response(raw)
    return project_advisory(body)


def parse_command(argv: Sequence[str]) -> tuple[str, tuple[str, ...]]:
    if len(argv) == 2 and argv[0] == "view" and GHSA_ID_RE.fullmatch(argv[1]):
        return "view", (argv[1],)
    if (
        len(argv) == 5
        and list(argv[:2]) == ["list", "--ecosystem"]
        and argv[3] == "--package"
    ):
        ecosystem, package = argv[2], argv[4]
        if package in PACKAGES.get(ecosystem, set()):
            return "list", (ecosystem, package)
    raise PolicyError


def main(argv: Sequence[str] | None = None) -> int:
    try:
        command, values = parse_command(sys.argv[1:] if argv is None else argv)
        projected: object = (
            fetch_view(values[0])
            if command == "view"
            else fetch_list(values[0], values[1])
        )
        # ensure_asciiは検証済み文字列に加え、非ASCII・制御文字を端末へ直接
        # 出力しない多重防御として維持する（schema/control検査の代替ではない）。
        output = json.dumps(projected, ensure_ascii=True, separators=(",", ":")) + "\n"
        if len(output.encode("utf-8")) > MAX_OUTPUT_BYTES:
            raise PolicyError
    except Exception:
        # 外部入力やlocal実行環境に由来する例外内容を端末へ露出させない。
        print("Global advisory request rejected", file=sys.stderr)
        return 1
    sys.stdout.write(output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
