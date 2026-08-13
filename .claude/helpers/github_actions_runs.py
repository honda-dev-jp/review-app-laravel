#!/usr/bin/env python3
"""GitHub Actions run/job metadataを固定read-only形で取得・縮約する。"""

from __future__ import annotations

from datetime import datetime
import json
import os
import pwd
import re
import selectors
import signal
import subprocess
import sys
import time
from typing import Sequence


REPOSITORY = "github.com/honda-dev-jp/review-app-laravel"
RUN_FIELDS = (
    "databaseId,workflowName,displayTitle,event,status,conclusion,headBranch,"
    "headSha,createdAt,updatedAt"
)
VIEW_FIELDS = f"{RUN_FIELDS},jobs"

MAX_RUNS = 20
MAX_JOBS = 100
LIST_MAX_RESPONSE_RAW_BYTES = 256 * 1024
# `gh run view --json jobs`のrawにはprojectionで捨てるstepsも含まれるため、
# viewだけは正常応答を収められる2 MiBとし、出力側は共通上限へ縮約する。
VIEW_MAX_RESPONSE_RAW_BYTES = 2 * 1024 * 1024
LIST_MAX_RESPONSE_UTF8_BYTES = 256 * 1024
VIEW_MAX_RESPONSE_UTF8_BYTES = 2 * 1024 * 1024
MAX_OUTPUT_UTF8_BYTES = 256 * 1024
MAX_STRING_CHARS = 4096
# 実fixtureのviewが約21秒だったため、正常取得を20秒で拒否しない余裕を持たせる。
TIMEOUT_SECONDS = 30.0
# int64境界はcanonical入力を有限にする型境界であり、実在run IDの上限ではない。
MAX_RUN_ID = 2**63 - 1

FIXED_ERROR = "GitHub Actions run request rejected"
RUN_ID_RE = re.compile(r"[1-9][0-9]*\Z", re.ASCII)
IDENTIFIER_RE = re.compile(r"[a-z_]{1,32}\Z", re.ASCII)
CONTROL_RE = re.compile(r"[\x00-\x1f\x7f-\x9f]")
TIMESTAMP_RE = re.compile(
    r"(?P<base>\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.\d+)?Z\Z",
    re.ASCII,
)
ZERO_TIME = "0001-01-01T00:00:00Z"


class PolicyError(Exception):
    """入力、外部応答、subprocessの固定契約違反。"""


def parse_run_id(value: str) -> int:
    # leading zero等の別表現を拒否し、caller入力をrun IDのcanonical表現1形にする。
    if len(value) > len(str(MAX_RUN_ID)) or not RUN_ID_RE.fullmatch(value):
        raise PolicyError
    run_id = int(value)
    if run_id > MAX_RUN_ID:
        raise PolicyError
    return run_id


def parse_command(argv: Sequence[str]) -> tuple[str, int | None]:
    if list(argv) == ["list"]:
        return "list", None
    if len(argv) == 2 and argv[0] == "view":
        return "view", parse_run_id(argv[1])
    raise PolicyError


def build_list_argv() -> list[str]:
    return [
        "gh",
        "run",
        "list",
        "--limit",
        str(MAX_RUNS),
        "--repo",
        REPOSITORY,
        "--json",
        RUN_FIELDS,
    ]


def build_view_argv(run_id: int) -> list[str]:
    if isinstance(run_id, bool) or not isinstance(run_id, int):
        raise PolicyError
    canonical = parse_run_id(str(run_id))
    return [
        "gh",
        "run",
        "view",
        str(canonical),
        "--repo",
        REPOSITORY,
        "--json",
        VIEW_FIELDS,
    ]


def _subprocess_environment() -> dict[str, str]:
    # HOMEだけは認証済みghの設定探索に必要だが、その他も含めcaller環境は継承しない。
    # update通知も外部出力・network挙動を増やさないよう明示的に無効化する。
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
        "GH_NO_UPDATE_NOTIFIER": "1",
    }


def _canonical_argv(argv: Sequence[str]) -> tuple[int, int]:
    value = list(argv)
    if value == build_list_argv():
        return LIST_MAX_RESPONSE_RAW_BYTES, LIST_MAX_RESPONSE_UTF8_BYTES
    if len(value) == 8 and value[:3] == ["gh", "run", "view"]:
        try:
            run_id = parse_run_id(value[3])
        except PolicyError:
            raise PolicyError from None
        if value == build_view_argv(run_id):
            return VIEW_MAX_RESPONSE_RAW_BYTES, VIEW_MAX_RESPONSE_UTF8_BYTES
    raise PolicyError


def _terminate_process(process: subprocess.Popen[bytes]) -> None:
    try:
        exited = process.poll() is not None
    except Exception:
        exited = False
    if not exited:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except Exception:
            try:
                process.kill()
            except Exception:
                pass
    try:
        process.wait(timeout=1)
    except Exception:
        pass


def run_gh(argv: Sequence[str]) -> bytes:
    # build時だけに依存せず、subprocess直前にargv全体をcanonical形と再照合する。
    raw_limit, utf8_limit = _canonical_argv(argv)
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

    selector: selectors.BaseSelector | None = None
    stdout: bytearray
    try:
        # Popen成功後はselector setupも保護領域へ含め、途中のどの例外でも
        # 子processとpipeを残さない。正常終了後は不要なkillを行わない。
        if process.stdout is None or process.stderr is None:
            raise PolicyError
        stdout = bytearray()
        stderr_seen = False
        total = 0
        deadline = time.monotonic() + TIMEOUT_SECONDS
        selector = selectors.DefaultSelector()
        selector.register(process.stdout, selectors.EVENT_READ, "stdout")
        selector.register(process.stderr, selectors.EVENT_READ, "stderr")
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
                if total > raw_limit:
                    raise PolicyError
                if key.data == "stdout":
                    stdout.extend(chunk)
                else:
                    stderr_seen = True
        # warningを含むstderrもrawのまま漏らさず、成功応答としては受理しない。
        if process.wait(timeout=1) != 0 or stderr_seen:
            raise PolicyError
    except Exception:
        _terminate_process(process)
        raise PolicyError from None
    finally:
        if selector is not None:
            try:
                selector.close()
            except Exception:
                pass
        for stream in (process.stdout, process.stderr):
            if stream is None:
                continue
            try:
                stream.close()
            except Exception:
                pass

    raw = bytes(stdout)
    if not raw or len(raw) > raw_limit:
        raise PolicyError
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        raise PolicyError from None
    if len(text.encode("utf-8")) > utf8_limit:
        raise PolicyError
    return raw


def _reject_duplicate_keys(pairs: list[tuple[str, object]]) -> dict[str, object]:
    value: dict[str, object] = {}
    for key, item in pairs:
        if key in value or CONTROL_RE.search(key):
            raise PolicyError
        value[key] = item
    return value


def _reject_constant(_: str) -> object:
    raise PolicyError


def decode_json(raw: bytes) -> object:
    try:
        return json.loads(
            raw.decode("utf-8"),
            object_pairs_hook=_reject_duplicate_keys,
            parse_constant=_reject_constant,
        )
    except (UnicodeDecodeError, json.JSONDecodeError, PolicyError):
        raise PolicyError from None


def _required(value: dict[str, object], field: str) -> object:
    # unknown fieldは権限拡大せず無視する一方、要求したschemaの欠落は拒否する。
    if field not in value:
        raise PolicyError
    return value[field]


def _positive_int64(value: object) -> int:
    if isinstance(value, bool) or not isinstance(value, int):
        raise PolicyError
    if not 1 <= value <= MAX_RUN_ID:
        raise PolicyError
    return value


def _string(value: object, *, nullable: bool = False) -> str | None:
    if nullable and (value is None or value == ""):
        return None
    if not isinstance(value, str) or not value or len(value) > MAX_STRING_CHARS:
        raise PolicyError
    if CONTROL_RE.search(value):
        raise PolicyError
    return value


def _identifier(value: object, *, nullable: bool = False) -> str | None:
    # CLI/APIの将来値を永久的なclosed enumにせず、安全な識別子構文だけを固定する。
    if nullable and (value is None or value == ""):
        return None
    if not isinstance(value, str) or not IDENTIFIER_RE.fullmatch(value):
        raise PolicyError
    return value


def _timestamp(value: object, *, nullable: bool = False) -> str | None:
    # CLIがnullable時刻をnull・空文字・zero timeで表し得るため、同じnullへ正規化する。
    if nullable and (value is None or value == "" or value == ZERO_TIME):
        return None
    # projected string共通上限をtimestampだけが長いfractionで迂回しないようにする。
    if (
        not isinstance(value, str)
        or not 1 <= len(value) <= MAX_STRING_CHARS
        or value == ZERO_TIME
    ):
        raise PolicyError
    match = TIMESTAMP_RE.fullmatch(value)
    if match is None:
        raise PolicyError
    try:
        datetime.strptime(match.group("base"), "%Y-%m-%dT%H:%M:%S")
    except ValueError:
        raise PolicyError from None
    return value


def project_run(
    value: object, *, include_jobs: bool = False, expected_run_id: int | None = None
) -> dict[str, object]:
    if not isinstance(value, dict):
        raise PolicyError
    database_id = _positive_int64(_required(value, "databaseId"))
    if expected_run_id is not None and database_id != expected_run_id:
        raise PolicyError
    projected: dict[str, object] = {
        "databaseId": database_id,
        "workflowName": _string(_required(value, "workflowName"), nullable=True),
        "displayTitle": _string(_required(value, "displayTitle")),
        "event": _string(_required(value, "event")),
        "status": _identifier(_required(value, "status")),
        "conclusion": _identifier(_required(value, "conclusion"), nullable=True),
        "headBranch": _string(_required(value, "headBranch"), nullable=True),
        "headSha": _string(_required(value, "headSha")),
        "createdAt": _timestamp(_required(value, "createdAt")),
        "updatedAt": _timestamp(_required(value, "updatedAt")),
    }
    if include_jobs:
        jobs = _required(value, "jobs")
        if not isinstance(jobs, list) or len(jobs) > MAX_JOBS:
            raise PolicyError
        # raw jobにはstepsがあり得るが、初期版の診断に不要なため個別検証も出力もしない。
        projected["jobs"] = [project_job(job) for job in jobs]
    return projected


def project_job(value: object) -> dict[str, object]:
    if not isinstance(value, dict):
        raise PolicyError
    return {
        "name": _string(_required(value, "name")),
        "status": _identifier(_required(value, "status")),
        "conclusion": _identifier(_required(value, "conclusion"), nullable=True),
        "completedAt": _timestamp(_required(value, "completedAt"), nullable=True),
    }


def _encode(value: object) -> bytes:
    encoded = (
        json.dumps(value, ensure_ascii=True, separators=(",", ":")) + "\n"
    ).encode("utf-8")
    if len(encoded) > MAX_OUTPUT_UTF8_BYTES:
        raise PolicyError
    return encoded


def fetch_list() -> bytes:
    value = decode_json(run_gh(build_list_argv()))
    if not isinstance(value, list) or len(value) > MAX_RUNS:
        raise PolicyError
    return _encode([project_run(run) for run in value])


def fetch_view(run_id: int) -> bytes:
    value = decode_json(run_gh(build_view_argv(run_id)))
    return _encode(project_run(value, include_jobs=True, expected_run_id=run_id))


def main(argv: Sequence[str] | None = None) -> int:
    try:
        command, run_id = parse_command(sys.argv[1:] if argv is None else argv)
        if command == "list":
            output = fetch_list()
        else:
            assert run_id is not None
            # `--exit-status`は付けない。workflow failureは診断対象の正常metadataであり、
            # helper/subprocess failureとは分離してexit 0で返す。
            output = fetch_view(run_id)
        sys.stdout.buffer.write(output)
        return 0
    except Exception:
        print(FIXED_ERROR, file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
