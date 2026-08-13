#!/usr/bin/env python3
"""Repository Dependabot alertsを固定read-only形で取得・縮約する。"""

from __future__ import annotations

import json
import math
import os
import pwd
import re
import selectors
import subprocess
import sys
import time
from typing import Mapping, Sequence
from urllib.parse import parse_qsl, quote, urlsplit


REPOSITORY_PATH = "/repos/honda-dev-jp/review-app-laravel/dependabot/alerts"
ACCEPT_HEADER = "Accept: application/vnd.github+json"
VERSION_HEADER = "X-GitHub-Api-Version: 2026-03-10"

# 外部responseの増大でmemory・実行時間・端末出力が無制限にならないよう、
# page/件数より総byte上限が先に効く場合も意図した境界とする。
# 超過時は上限を自動拡張せず、部分結果も返さずfail-closedにする。
PER_PAGE = 25
MAX_PAGES = 6
MAX_ALERTS = 150
MAX_RESPONSE_RAW_BYTES = 512 * 1024
MAX_RESPONSE_UTF8_BYTES = 480 * 1024
MAX_TOTAL_RAW_BYTES = 1024 * 1024
MAX_TOTAL_UTF8_BYTES = 960 * 1024
MAX_HEADER_BYTES = 32 * 1024
MAX_OUTPUT_UTF8_BYTES = 256 * 1024
MAX_STRING_CHARS = 4096
MAX_CURSOR_CHARS = 512
MAX_CWES = 50
MAX_ALERT_NUMBER = 2**63 - 1
TIMEOUT_SECONDS = 20.0

ALERT_NUMBER_RE = re.compile(r"[1-9][0-9]*\Z")
# cursorが固定path/query構造を変えないよう、URL上で意味を持つ
# `/`、`?`、`&`、`%`、`#`等はopaque valueへ持ち込ませない。
CURSOR_RE = re.compile(rf"[A-Za-z0-9._~=-]{{1,{MAX_CURSOR_CHARS}}}\Z")
# Issue #90ではC1も明示的な拒否対象であり、#89との機械的な統一で
# C1境界を落とさないため、C0・DELと同じ場所で検査する。
CONTROL_RE = re.compile(r"[\x00-\x1f\x7f-\x9f]")
TIMESTAMP_RE = re.compile(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\Z")
GHSA_ID_RE = re.compile(
    r"GHSA-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}-[23456789cfghjmpqrvwx]{4}\Z"
)
CVE_ID_RE = re.compile(r"CVE-[0-9]{4}-[0-9]{4,}\Z")
HEADER_STATUS_RE = re.compile(r"HTTP/(?:1\.[01]|2(?:\.0)?) 200(?: .*)?\Z")


class PolicyError(Exception):
    """入力、外部応答、subprocessの固定契約違反。"""


def parse_alert_number(value: str) -> int:
    if len(value) > len(str(MAX_ALERT_NUMBER)) or not ALERT_NUMBER_RE.fullmatch(value):
        raise PolicyError
    number = int(value)
    if number > MAX_ALERT_NUMBER:
        raise PolicyError
    return number


def build_list_endpoint(cursor: str | None = None) -> str:
    endpoint = f"{REPOSITORY_PATH}?state=open&per_page={PER_PAGE}"
    if cursor is None:
        return endpoint
    if not CURSOR_RE.fullmatch(cursor):
        raise PolicyError
    return f"{endpoint}&after={quote(cursor, safe='')}"


def build_view_endpoint(alert_number: int) -> str:
    if (
        isinstance(alert_number, bool)
        or not isinstance(alert_number, int)
        or not 1 <= alert_number <= MAX_ALERT_NUMBER
    ):
        raise PolicyError
    return f"{REPOSITORY_PATH}/{alert_number}"


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


def _subprocess_environment() -> dict[str, str]:
    return {
        "HOME": pwd.getpwuid(os.getuid()).pw_dir,
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


def _valid_percent_encoding(value: str) -> bool:
    index = 0
    while index < len(value):
        if value[index] != "%":
            index += 1
            continue
        if index + 2 >= len(value) or not re.fullmatch(
            r"[0-9A-Fa-f]{2}", value[index + 1 : index + 3]
        ):
            return False
        index += 3
    return True


def _endpoint_is_allowed(endpoint: str) -> bool:
    if re.fullmatch(rf"{re.escape(REPOSITORY_PATH)}/[1-9][0-9]*", endpoint):
        try:
            alert_number = parse_alert_number(endpoint.rsplit("/", 1)[1])
            return endpoint == build_view_endpoint(alert_number)
        except PolicyError:
            return False
    if not _valid_percent_encoding(endpoint):
        return False
    parsed = urlsplit(endpoint)
    if parsed.path != REPOSITORY_PATH or parsed.fragment or not parsed.query:
        return False
    try:
        pairs = parse_qsl(parsed.query, keep_blank_values=True, strict_parsing=True)
    except ValueError:
        return False
    if len(pairs) not in {2, 3} or len({key for key, _ in pairs}) != len(pairs):
        return False
    query = dict(pairs)
    if set(query) not in ({"state", "per_page"}, {"state", "per_page", "after"}):
        return False
    if query["state"] != "open" or query["per_page"] != str(PER_PAGE):
        return False
    cursor = query.get("after")
    try:
        return endpoint == build_list_endpoint(cursor)
    except PolicyError:
        return False


def run_gh(argv: Sequence[str]) -> bytes:
    # build時の固定だけに依存せず、将来内部でargvが拡張されても、
    # 外部processへ出る最後の境界でcanonical形以外をfail-closedにする。
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
    stderr_seen = False
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
                if total > MAX_RESPONSE_RAW_BYTES:
                    raise PolicyError
                if key.data == "stdout":
                    stdout.extend(chunk)
                else:
                    stderr_seen = True
        # 成功時のwarning/debugも安全な出力経路へ混入させないため、
        # stderrは内容を表示せず、1 byteでもあればfail-closedにする。
        if process.wait(timeout=1) != 0 or stderr_seen:
            raise PolicyError
    except (OSError, subprocess.SubprocessError, PolicyError):
        try:
            process.kill()
        except OSError:
            pass
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


def _reject_duplicate_keys(pairs: list[tuple[str, object]]) -> dict[str, object]:
    value: dict[str, object] = {}
    for key, item in pairs:
        if key in value or CONTROL_RE.search(key):
            raise PolicyError
        value[key] = item
    return value


def _reject_constant(_: str) -> object:
    raise PolicyError


def split_response(raw: bytes) -> tuple[Mapping[str, str], object, int]:
    if len(raw) > MAX_RESPONSE_RAW_BYTES:
        raise PolicyError
    separators = [separator for separator in (b"\r\n\r\n", b"\n\n") if separator in raw]
    if not separators:
        raise PolicyError
    separator = b"\r\n\r\n" if b"\r\n\r\n" in raw else b"\n\n"
    header_raw, body_raw = raw.split(separator, 1)
    if (
        not header_raw
        or len(header_raw) > MAX_HEADER_BYTES
        or not body_raw
        or len(body_raw) > MAX_RESPONSE_UTF8_BYTES
        or body_raw.startswith(b"HTTP/")
    ):
        raise PolicyError
    try:
        header_text = header_raw.decode("ascii")
        body_text = body_raw.decode("utf-8")
    except UnicodeDecodeError:
        raise PolicyError from None
    lines = header_text.replace("\r\n", "\n").split("\n")
    if (
        not lines
        or CONTROL_RE.search(lines[0])
        or not HEADER_STATUS_RE.fullmatch(lines[0])
    ):
        raise PolicyError
    headers: dict[str, str] = {}
    for line in lines[1:]:
        if not line or ":" not in line or CONTROL_RE.search(line):
            raise PolicyError
        name, value = line.split(":", 1)
        lowered = name.strip().casefold()
        if (
            not re.fullmatch(r"[a-z0-9-]+", lowered)
            or lowered in headers
            or CONTROL_RE.search(value)
        ):
            raise PolicyError
        headers[lowered] = value.strip()
    try:
        body = json.loads(
            body_text,
            object_pairs_hook=_reject_duplicate_keys,
            parse_constant=_reject_constant,
        )
    except (json.JSONDecodeError, UnicodeError):
        raise PolicyError from None
    return headers, body, len(body_raw)


def next_cursor(link_header: str | None) -> str | None:
    if link_header is None:
        return None
    next_urls: list[str] = []
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
    # server由来URLはorigin/path/queryのdriftやquery injectionを避けるため流用せず、
    # 検証済みcursorだけを返してcaller側で固定endpointを再構築する。
    url = next_urls[0]
    if not _valid_percent_encoding(url):
        raise PolicyError
    try:
        parsed = urlsplit(url)
        port = parsed.port
        pairs = parse_qsl(parsed.query, keep_blank_values=True, strict_parsing=True)
    except (UnicodeError, ValueError):
        raise PolicyError from None
    if (
        parsed.scheme != "https"
        or parsed.hostname != "api.github.com"
        or port is not None
        or parsed.username is not None
        or parsed.password is not None
        or parsed.path != REPOSITORY_PATH
        or parsed.fragment
        or len(pairs) != 3
        or len({key for key, _ in pairs}) != 3
    ):
        raise PolicyError
    query = dict(pairs)
    if (
        set(query) != {"state", "per_page", "after"}
        or query["state"] != "open"
        or query["per_page"] != str(PER_PAGE)
    ):
        raise PolicyError
    cursor = query["after"]
    if not CURSOR_RE.fullmatch(cursor):
        raise PolicyError
    return cursor


def _required_object(value: object, fields: set[str]) -> dict[str, object]:
    if not isinstance(value, dict) or not fields.issubset(value):
        raise PolicyError
    return value


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


def _timestamp(value: object, *, nullable: bool = False) -> str | None:
    result = _safe_string(value, nullable=nullable)
    if result is not None and not TIMESTAMP_RE.fullmatch(result):
        raise PolicyError
    return result


def _enum(value: object, allowed: set[str], *, nullable: bool = False) -> str | None:
    result = _safe_string(value, nullable=nullable)
    if result is not None and result not in allowed:
        raise PolicyError
    return result


def _cvss(value: object) -> dict[str, object] | None:
    if value is None:
        return None
    item = _required_object(value, {"score", "vector_string"})
    score = item["score"]
    if score is not None and (
        isinstance(score, bool)
        or not isinstance(score, (int, float))
        or not math.isfinite(score)
        or not 0.0 <= score <= 10.0
    ):
        raise PolicyError
    return {
        "score": score,
        "vector_string": _safe_string(item["vector_string"], nullable=True),
    }


def _project_cvss(advisory: dict[str, object]) -> dict[str, object] | None:
    value = advisory.get("cvss_severities")
    if value is None:
        return None
    source = _required_object(value, set())
    return {
        "cvss_v3": _cvss(source.get("cvss_v3")),
        "cvss_v4": _cvss(source.get("cvss_v4")),
    }


def _project_cwes(value: object) -> list[dict[str, object]] | None:
    if value is None:
        return None
    if not isinstance(value, list) or len(value) > MAX_CWES:
        raise PolicyError
    results = []
    for item in value:
        source = _required_object(item, {"cwe_id", "name"})
        results.append(
            {
                "cwe_id": _safe_string(source["cwe_id"]),
                "name": _safe_string(source["name"]),
            }
        )
    return results


def project_alert(
    value: object, *, detail: bool, expected_number: int | None = None
) -> dict[str, object]:
    required = {
        "number",
        "state",
        "dependency",
        "security_advisory",
        "security_vulnerability",
        "created_at",
        "updated_at",
        "fixed_at",
    }
    source = _required_object(value, required)
    number = source["number"]
    if (
        isinstance(number, bool)
        or not isinstance(number, int)
        or not 1 <= number <= MAX_ALERT_NUMBER
        or (expected_number is not None and number != expected_number)
    ):
        raise PolicyError
    states = (
        {"open"} if not detail else {"open", "fixed", "dismissed", "auto_dismissed"}
    )
    state = _enum(source["state"], states)

    dependency = _required_object(
        source["dependency"], {"package", "manifest_path", "scope", "relationship"}
    )
    package = _required_object(dependency["package"], {"ecosystem", "name"})
    ecosystem = _enum(package["ecosystem"], {"composer", "npm"})
    package_name = _safe_string(package["name"])

    advisory_fields = {"ghsa_id", "cve_id", "severity", "summary"}
    if detail:
        advisory_fields |= {"published_at", "updated_at", "withdrawn_at", "cwes"}
    advisory = _required_object(source["security_advisory"], advisory_fields)
    ghsa_id = _safe_string(advisory["ghsa_id"])
    if not isinstance(ghsa_id, str) or not GHSA_ID_RE.fullmatch(ghsa_id):
        raise PolicyError
    cve_id = _safe_string(advisory["cve_id"], nullable=True)
    if cve_id is not None and not CVE_ID_RE.fullmatch(cve_id):
        raise PolicyError
    advisory_severity = _enum(
        advisory["severity"], {"low", "medium", "high", "critical"}
    )

    vulnerability = _required_object(
        source["security_vulnerability"],
        {"package", "severity", "vulnerable_version_range", "first_patched_version"},
    )
    vulnerability_package = _required_object(
        vulnerability["package"], {"ecosystem", "name"}
    )
    vulnerability_ecosystem = _enum(
        vulnerability_package["ecosystem"], {"composer", "npm"}
    )
    vulnerability_name = _safe_string(vulnerability_package["name"])
    # 別packageの脆弱version情報をdependencyへ誤結合しないため、source内の
    # 2つのpackage表現が一致することをprojection前に要求する。
    if (vulnerability_ecosystem, vulnerability_name) != (ecosystem, package_name):
        raise PolicyError
    _enum(vulnerability["severity"], {"low", "medium", "high", "critical"})
    first_patched = vulnerability["first_patched_version"]
    if first_patched is None:
        projected_first_patched = None
    else:
        first_patched_object = _required_object(first_patched, {"identifier"})
        projected_first_patched = {
            "identifier": _safe_string(first_patched_object["identifier"])
        }

    projected_advisory: dict[str, object] = {
        "ghsa_id": ghsa_id,
        "cve_id": cve_id,
        "severity": advisory_severity,
        "summary": _safe_string(advisory["summary"]),
    }
    if detail:
        projected_advisory.update(
            {
                "published_at": _timestamp(advisory["published_at"]),
                "updated_at": _timestamp(advisory["updated_at"]),
                "withdrawn_at": _timestamp(advisory["withdrawn_at"], nullable=True),
                "cvss_severities": _project_cvss(advisory),
                "cwes": _project_cwes(advisory["cwes"]),
            }
        )

    return {
        "number": number,
        "state": state,
        "dependency": {
            "package": {"ecosystem": ecosystem, "name": package_name},
            "manifest_path": _safe_string(dependency["manifest_path"]),
            "scope": _enum(
                dependency["scope"], {"development", "runtime"}, nullable=True
            ),
            "relationship": _enum(
                dependency["relationship"],
                {"unknown", "direct", "transitive", "inconclusive"},
                nullable=True,
            ),
        },
        "security_advisory": projected_advisory,
        "security_vulnerability": {
            "vulnerable_version_range": _safe_string(
                vulnerability["vulnerable_version_range"]
            ),
            "first_patched_version": projected_first_patched,
        },
        "created_at": _timestamp(source["created_at"]),
        "updated_at": _timestamp(source["updated_at"]),
        "fixed_at": _timestamp(source["fixed_at"], nullable=True),
    }


def fetch_list() -> list[dict[str, object]]:
    cursor = None
    seen_cursors: set[str] = set()
    total_raw_bytes = 0
    total_utf8_bytes = 0
    results: list[dict[str, object]] = []
    for page_index in range(MAX_PAGES):
        # Link URL自体ではなく検証済みcursorだけから、固定repository/queryの
        # endpointとcanonical argvを毎page再構築する。
        raw = run_gh(build_argv(build_list_endpoint(cursor)))
        total_raw_bytes += len(raw)
        if total_raw_bytes > MAX_TOTAL_RAW_BYTES:
            raise PolicyError
        headers, body, body_utf8_bytes = split_response(raw)
        total_utf8_bytes += body_utf8_bytes
        if total_utf8_bytes > MAX_TOTAL_UTF8_BYTES:
            raise PolicyError
        if not isinstance(body, list) or len(body) > PER_PAGE:
            raise PolicyError
        results.extend(project_alert(item, detail=False) for item in body)
        if len(results) > MAX_ALERTS:
            raise PolicyError
        cursor = next_cursor(headers.get("link"))
        if cursor is None:
            return results
        if page_index == MAX_PAGES - 1 or cursor in seen_cursors:
            raise PolicyError
        seen_cursors.add(cursor)
    raise PolicyError


def fetch_view(alert_number: int) -> dict[str, object]:
    raw = run_gh(build_argv(build_view_endpoint(alert_number)))
    _, body, _ = split_response(raw)
    return project_alert(body, detail=True, expected_number=alert_number)


def parse_command(argv: Sequence[str]) -> tuple[str, int | None]:
    if list(argv) == ["list"]:
        return "list", None
    if len(argv) == 2 and argv[0] == "view":
        return "view", parse_alert_number(argv[1])
    raise PolicyError


def main(argv: Sequence[str] | None = None) -> int:
    try:
        command, alert_number = parse_command(sys.argv[1:] if argv is None else argv)
        if command == "list":
            projected: object = fetch_list()
        else:
            assert alert_number is not None
            projected = fetch_view(alert_number)
        output = json.dumps(projected, ensure_ascii=True, separators=(",", ":")) + "\n"
        if len(output.encode("utf-8")) > MAX_OUTPUT_UTF8_BYTES:
            raise PolicyError
    except Exception:
        print("Dependabot alert request rejected", file=sys.stderr)
        return 1
    sys.stdout.write(output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
