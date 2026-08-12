"""固定Dependabot alerts helperの回帰テスト。"""

from __future__ import annotations

import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import unittest
from unittest import mock


HELPER_PATH = (
    Path(__file__).resolve().parents[2] / "helpers" / "github_dependabot_alerts.py"
)
SPEC = importlib.util.spec_from_file_location("github_dependabot_alerts", HELPER_PATH)
assert SPEC is not None and SPEC.loader is not None
helper = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(helper)


def alert(**overrides: object) -> dict[str, object]:
    value: dict[str, object] = {
        "number": 1,
        "state": "open",
        "dependency": {
            "package": {"ecosystem": "composer", "name": "laravel/framework"},
            "manifest_path": "composer.lock",
            "scope": "runtime",
            "relationship": "transitive",
        },
        "security_advisory": {
            "ghsa_id": "GHSA-2345-6789-cfgh",
            "cve_id": "CVE-2026-1234",
            "severity": "high",
            "summary": "Synthetic advisory",
            "published_at": "2026-01-01T00:00:00Z",
            "updated_at": "2026-01-02T00:00:00Z",
            "withdrawn_at": None,
            "cvss_severities": {
                "cvss_v3": {"score": 7.5, "vector_string": "CVSS:3.1/test"},
                "cvss_v4": {"score": 8.7, "vector_string": "CVSS:4.0/test"},
            },
            "cwes": [{"cwe_id": "CWE-123", "name": "Synthetic weakness"}],
            "description": "must not be projected",
            "references": [{"url": "https://example.invalid"}],
        },
        "security_vulnerability": {
            "package": {"ecosystem": "composer", "name": "laravel/framework"},
            "severity": "high",
            "vulnerable_version_range": ">= 10, < 10.99",
            "first_patched_version": {"identifier": "10.99.0"},
        },
        "created_at": "2026-01-01T00:00:00Z",
        "updated_at": "2026-01-02T00:00:00Z",
        "fixed_at": None,
        "unexpected": "ignored",
    }
    value.update(overrides)
    return value


def response(body: object, *, status: str = "200 OK", link: str | None = None) -> bytes:
    headers = [f"HTTP/2 {status}", "content-type: application/json"]
    if link is not None:
        headers.append(f"link: {link}")
    return ("\r\n".join(headers) + "\r\n\r\n").encode("ascii") + json.dumps(
        body, ensure_ascii=True, separators=(",", ":")
    ).encode("utf-8")


def next_link(cursor: str, *, query: str | None = None, path: str | None = None) -> str:
    actual_query = query or f"state=open&per_page=25&after={cursor}"
    actual_path = path or helper.REPOSITORY_PATH
    return f'<https://api.github.com{actual_path}?{actual_query}>; rel="next"'


class CliAndArgvTests(unittest.TestCase):
    def test_security_limits_match_the_approved_design(self) -> None:
        self.assertEqual(helper.PER_PAGE, 25)
        self.assertEqual(helper.MAX_PAGES, 6)
        self.assertEqual(helper.MAX_ALERTS, 150)
        self.assertEqual(helper.MAX_RESPONSE_RAW_BYTES, 512 * 1024)
        self.assertEqual(helper.MAX_RESPONSE_UTF8_BYTES, 480 * 1024)
        self.assertEqual(helper.MAX_TOTAL_RAW_BYTES, 1024 * 1024)
        self.assertEqual(helper.MAX_TOTAL_UTF8_BYTES, 960 * 1024)
        self.assertEqual(helper.MAX_HEADER_BYTES, 32 * 1024)
        self.assertEqual(helper.MAX_OUTPUT_UTF8_BYTES, 256 * 1024)
        self.assertEqual(helper.MAX_STRING_CHARS, 4096)
        self.assertEqual(helper.MAX_CURSOR_CHARS, 512)
        self.assertEqual(helper.MAX_CWES, 50)
        self.assertEqual(helper.MAX_ALERT_NUMBER, 2**63 - 1)
        self.assertEqual(helper.TIMEOUT_SECONDS, 20.0)

    def test_list_and_view_cli_boundaries(self) -> None:
        self.assertEqual(helper.parse_command(["list"]), ("list", None))
        self.assertEqual(helper.parse_command(["view", "1"]), ("view", 1))
        self.assertEqual(
            helper.parse_command(["view", str(helper.MAX_ALERT_NUMBER)]),
            ("view", helper.MAX_ALERT_NUMBER),
        )
        invalid = (
            [],
            ["view"],
            ["list", "extra"],
            ["unknown"],
            ["view", "0"],
            ["view", "-1"],
            ["view", "+1"],
            ["view", "01"],
            ["view", "1.0"],
            ["view", " 1"],
            ["view", "1 "],
            ["view", "alpha"],
            ["view", str(helper.MAX_ALERT_NUMBER + 1)],
            ["view", "9" * 20],
            ["view", "1", "extra"],
            ["view", "--repo"],
        )
        for argv in invalid:
            with self.subTest(argv=argv), self.assertRaises(helper.PolicyError):
                helper.parse_command(argv)

    def test_exact_argv_for_first_next_and_view(self) -> None:
        first = helper.build_argv(helper.build_list_endpoint())
        following = helper.build_argv(helper.build_list_endpoint("cursor=value"))
        view = helper.build_argv(helper.build_view_endpoint(7))
        suffix = [
            "--hostname",
            "github.com",
            "--method",
            "GET",
            "--include",
            "--header",
            "Accept: application/vnd.github+json",
            "--header",
            "X-GitHub-Api-Version: 2026-03-10",
        ]
        self.assertEqual(
            first,
            [
                "gh",
                "api",
                f"{helper.REPOSITORY_PATH}?state=open&per_page=25",
                *suffix,
            ],
        )
        self.assertEqual(
            following,
            [
                "gh",
                "api",
                f"{helper.REPOSITORY_PATH}?state=open&per_page=25&after=cursor%3Dvalue",
                *suffix,
            ],
        )
        self.assertEqual(view, ["gh", "api", f"{helper.REPOSITORY_PATH}/7", *suffix])
        for argv in (first, following, view):
            forbidden = {
                "-f",
                "-F",
                "--paginate",
                "--slurp",
                "--jq",
                "--template",
                "--verbose",
                "--input",
                "--cache",
                "--silent",
            }
            self.assertTrue(forbidden.isdisjoint(argv))
            self.assertNotIn("Authorization", " ".join(argv))

    def test_endpoint_revalidation_rejects_any_argv_expansion(self) -> None:
        valid = helper.build_argv(helper.build_list_endpoint())
        invalid = (
            valid + ["--paginate"],
            [*valid[:6], "POST", *valid[7:]],
            [*valid[:3], "--hostname", "example.com", *valid[5:]],
            helper.build_argv("/repos/other/repository/dependabot/alerts"),
            helper.build_argv(helper.REPOSITORY_PATH + "?state=all&per_page=25"),
        )
        for argv in invalid:
            with self.subTest(argv=argv), self.assertRaises(helper.PolicyError):
                helper.run_gh(argv)

    def test_environment_does_not_inherit_caller_overrides(self) -> None:
        expected = {
            "HOME",
            "PATH",
            "LANG",
            "LC_ALL",
            "TERM",
            "NO_COLOR",
            "CLICOLOR",
            "CLICOLOR_FORCE",
            "GH_PAGER",
            "PAGER",
            "GH_PROMPT_DISABLED",
        }
        with mock.patch.dict(
            os.environ,
            {
                "GH_HOST": "example.invalid",
                "GH_REPO": "other/repository",
                "GH_DEBUG": "api",
                "GH_FORCE_TTY": "1",
                "GH_CONFIG_DIR": "/tmp/synthetic",
                "GH_TOKEN": "synthetic",
                "GITHUB_TOKEN": "synthetic",
            },
            clear=False,
        ):
            environment = helper._subprocess_environment()
        self.assertEqual(set(environment), expected)
        self.assertEqual(environment["PATH"], "/usr/local/bin:/usr/bin:/bin")
        self.assertEqual(environment["LANG"], "C.UTF-8")
        self.assertEqual(environment["LC_ALL"], "C.UTF-8")
        self.assertEqual(environment["TERM"], "dumb")
        self.assertEqual(environment["GH_PAGER"], "cat")
        self.assertEqual(environment["GH_PROMPT_DISABLED"], "1")

    def test_subprocess_options_and_unexpected_stderr(self) -> None:
        stdout_read, stdout_write = os.pipe()
        stderr_read, stderr_write = os.pipe()
        os.write(stdout_write, response(alert()))
        os.close(stdout_write)
        os.write(stderr_write, b"synthetic diagnostic")
        os.close(stderr_write)

        class FakeProcess:
            def __init__(self) -> None:
                self.stdout = os.fdopen(stdout_read, "rb", buffering=0)
                self.stderr = os.fdopen(stderr_read, "rb", buffering=0)

            def wait(self, timeout: float) -> int:
                return 0

            def kill(self) -> None:
                return None

        argv = helper.build_argv(helper.build_view_endpoint(1))
        with mock.patch.object(
            helper.subprocess, "Popen", return_value=FakeProcess()
        ) as popen:
            with self.assertRaises(helper.PolicyError):
                helper.run_gh(argv)
        kwargs = popen.call_args.kwargs
        self.assertEqual(kwargs["stdin"], subprocess.DEVNULL)
        self.assertEqual(kwargs["stdout"], subprocess.PIPE)
        self.assertEqual(kwargs["stderr"], subprocess.PIPE)
        self.assertIs(kwargs["shell"], False)
        self.assertIs(kwargs["close_fds"], True)
        self.assertIs(kwargs["start_new_session"], True)
        self.assertEqual(kwargs["env"], helper._subprocess_environment())

    def test_subprocess_start_failure_and_combined_raw_limit_fail_closed(self) -> None:
        argv = helper.build_argv(helper.build_view_endpoint(1))
        with mock.patch.object(helper.subprocess, "Popen", side_effect=OSError):
            with self.assertRaises(helper.PolicyError):
                helper.run_gh(argv)

        stdout_read, stdout_write = os.pipe()
        stderr_read, stderr_write = os.pipe()
        os.write(stdout_write, b"1234")
        os.close(stdout_write)
        os.write(stderr_write, b"56")
        os.close(stderr_write)

        class FakeProcess:
            def __init__(self) -> None:
                self.stdout = os.fdopen(stdout_read, "rb", buffering=0)
                self.stderr = os.fdopen(stderr_read, "rb", buffering=0)
                self.killed = False

            def wait(self, timeout: float) -> int:
                return 0

            def kill(self) -> None:
                self.killed = True

        process = FakeProcess()
        with mock.patch.object(helper.subprocess, "Popen", return_value=process):
            with mock.patch.object(helper, "MAX_RESPONSE_RAW_BYTES", 5):
                with self.assertRaises(helper.PolicyError):
                    helper.run_gh(argv)
        self.assertTrue(process.killed)

    def test_subprocess_timeout_fails_closed(self) -> None:
        stdout_read, stdout_write = os.pipe()
        stderr_read, stderr_write = os.pipe()

        class FakeProcess:
            def __init__(self) -> None:
                self.stdout = os.fdopen(stdout_read, "rb", buffering=0)
                self.stderr = os.fdopen(stderr_read, "rb", buffering=0)

            def wait(self, timeout: float) -> int:
                return 0

            def kill(self) -> None:
                return None

        argv = helper.build_argv(helper.build_view_endpoint(1))
        try:
            with mock.patch.object(
                helper.subprocess, "Popen", return_value=FakeProcess()
            ):
                with mock.patch.object(helper, "TIMEOUT_SECONDS", 0.0):
                    with self.assertRaises(helper.PolicyError):
                        helper.run_gh(argv)
        finally:
            os.close(stdout_write)
            os.close(stderr_write)


class HttpAndSchemaTests(unittest.TestCase):
    def test_list_projection_is_fixed_and_unknown_fields_are_ignored(self) -> None:
        projected = helper.project_alert(alert(), detail=False)
        self.assertEqual(
            set(projected),
            {
                "number",
                "state",
                "dependency",
                "security_advisory",
                "security_vulnerability",
                "created_at",
                "updated_at",
                "fixed_at",
            },
        )
        self.assertEqual(
            set(projected["security_advisory"]),
            {"ghsa_id", "cve_id", "severity", "summary"},
        )
        self.assertEqual(
            projected["security_vulnerability"],
            {
                "vulnerable_version_range": ">= 10, < 10.99",
                "first_patched_version": {"identifier": "10.99.0"},
            },
        )

    def test_detail_projection_uses_cvss_severities_and_cwes(self) -> None:
        projected = helper.project_alert(alert(), detail=True, expected_number=1)
        advisory = projected["security_advisory"]
        self.assertNotIn("cvss", advisory)
        self.assertEqual(advisory["cvss_severities"]["cvss_v3"]["score"], 7.5)
        self.assertEqual(advisory["cvss_severities"]["cvss_v4"]["score"], 8.7)
        self.assertEqual(
            advisory["cwes"],
            [{"cwe_id": "CWE-123", "name": "Synthetic weakness"}],
        )
        self.assertNotIn("description", advisory)
        self.assertNotIn("references", advisory)

    def test_nullable_fields_and_cvss_variants(self) -> None:
        value = alert()
        value["dependency"]["scope"] = None
        value["dependency"]["relationship"] = None
        value["security_advisory"]["cve_id"] = None
        value["security_advisory"]["cvss_severities"] = None
        value["security_advisory"]["cwes"] = None
        value["security_vulnerability"]["first_patched_version"] = None
        projected = helper.project_alert(value, detail=True)
        self.assertIsNone(projected["dependency"]["scope"])
        self.assertIsNone(projected["dependency"]["relationship"])
        self.assertIsNone(projected["security_advisory"]["cvss_severities"])
        self.assertIsNone(projected["security_advisory"]["cwes"])
        self.assertIsNone(projected["security_vulnerability"]["first_patched_version"])

        for cvss in ({}, {"cvss_v3": None}, {"cvss_v4": None}):
            with self.subTest(cvss=cvss):
                item = alert()
                item["security_advisory"]["cvss_severities"] = cvss
                output = helper.project_alert(item, detail=True)
                self.assertIn("cvss_v3", output["security_advisory"]["cvss_severities"])
                self.assertIn("cvss_v4", output["security_advisory"]["cvss_severities"])
        nullable_values = (
            {
                "cvss_v3": {"score": None, "vector_string": None},
                "cvss_v4": {"score": None, "vector_string": None},
            },
            {
                "cvss_v3": {"score": None, "vector_string": "CVSS:3.1/test"},
                "cvss_v4": {"score": 8.7, "vector_string": None},
            },
        )
        for cvss in nullable_values:
            with self.subTest(cvss=cvss):
                item = alert()
                item["security_advisory"]["cvss_severities"] = cvss
                projected_cvss = helper.project_alert(item, detail=True)[
                    "security_advisory"
                ]["cvss_severities"]
                self.assertEqual(projected_cvss, cvss)
        missing = alert()
        del missing["security_advisory"]["cvss_severities"]
        self.assertIsNone(
            helper.project_alert(missing, detail=True)["security_advisory"][
                "cvss_severities"
            ]
        )

    def test_required_fields_wrong_types_enums_and_package_mismatch_fail(self) -> None:
        cases = []
        missing = alert()
        del missing["dependency"]["relationship"]
        cases.append(missing)
        wrong_number = alert(number=True)
        cases.append(wrong_number)
        wrong_state = alert(state="fixed")
        cases.append(wrong_state)
        wrong_ecosystem = alert()
        wrong_ecosystem["dependency"]["package"]["ecosystem"] = "pip"
        cases.append(wrong_ecosystem)
        mismatch = alert()
        mismatch["security_vulnerability"]["package"]["name"] = "other/package"
        cases.append(mismatch)
        wrong_timestamp = alert(created_at="not-a-timestamp")
        cases.append(wrong_timestamp)
        wrong_first_patch = alert()
        wrong_first_patch["security_vulnerability"]["first_patched_version"] = "10.99.0"
        cases.append(wrong_first_patch)
        for value in cases:
            with self.subTest(value=value), self.assertRaises(helper.PolicyError):
                helper.project_alert(value, detail=False)

    def test_all_documented_enums_are_accepted(self) -> None:
        for state in ("open", "fixed", "dismissed", "auto_dismissed"):
            with self.subTest(state=state):
                self.assertEqual(
                    helper.project_alert(alert(state=state), detail=True)["state"],
                    state,
                )
        for relationship in (None, "unknown", "direct", "transitive", "inconclusive"):
            with self.subTest(relationship=relationship):
                value = alert()
                value["dependency"]["relationship"] = relationship
                self.assertEqual(
                    helper.project_alert(value, detail=False)["dependency"][
                        "relationship"
                    ],
                    relationship,
                )
        for scope in ("development", "runtime"):
            with self.subTest(scope=scope):
                value = alert()
                value["dependency"]["scope"] = scope
                self.assertEqual(
                    helper.project_alert(value, detail=False)["dependency"]["scope"],
                    scope,
                )
        for severity in ("low", "medium", "high", "critical"):
            with self.subTest(severity=severity):
                value = alert()
                value["security_advisory"]["severity"] = severity
                value["security_vulnerability"]["severity"] = severity
                self.assertEqual(
                    helper.project_alert(value, detail=False)["security_advisory"][
                        "severity"
                    ],
                    severity,
                )

    def test_cvss_score_and_cwe_boundaries(self) -> None:
        for score in (0, 0.0, 10, 10.0):
            with self.subTest(score=score):
                value = alert()
                value["security_advisory"]["cvss_severities"]["cvss_v3"]["score"] = (
                    score
                )
                helper.project_alert(value, detail=True)
        for score in (True, -0.1, 10.1, float("nan"), float("inf")):
            with self.subTest(score=score):
                value = alert()
                value["security_advisory"]["cvss_severities"]["cvss_v3"]["score"] = (
                    score
                )
                with self.assertRaises(helper.PolicyError):
                    helper.project_alert(value, detail=True)
        for count in (0, 50):
            value = alert()
            value["security_advisory"]["cwes"] = [
                {"cwe_id": "CWE-1", "name": "name"} for _ in range(count)
            ]
            self.assertEqual(
                len(
                    helper.project_alert(value, detail=True)["security_advisory"][
                        "cwes"
                    ]
                ),
                count,
            )
        value = alert()
        value["security_advisory"]["cwes"] = [
            {"cwe_id": "CWE-1", "name": "name"} for _ in range(51)
        ]
        with self.assertRaises(helper.PolicyError):
            helper.project_alert(value, detail=True)

    def test_string_limits_and_c0_c1_del_are_rejected(self) -> None:
        value = alert()
        value["security_advisory"]["summary"] = "x" * helper.MAX_STRING_CHARS
        helper.project_alert(value, detail=False)
        value["security_advisory"]["summary"] += "x"
        with self.assertRaises(helper.PolicyError):
            helper.project_alert(value, detail=False)
        for character in ("\x00", "\x1b", "\x7f", "\x80", "\x9f"):
            with self.subTest(character=repr(character)):
                value = alert()
                value["security_advisory"]["summary"] = f"unsafe{character}value"
                with self.assertRaises(helper.PolicyError):
                    helper.project_alert(value, detail=False)

    def test_http_status_headers_json_utf8_and_constants_fail_closed(self) -> None:
        bad = [
            response({}, status="304 Not Modified"),
            response({}, status="400 Bad Request"),
            response({}, status="401 Unauthorized"),
            response({}, status="403 Forbidden"),
            response({}, status="404 Not Found"),
            response({}, status="410 Gone"),
            response({}, status="422 Unprocessable Entity"),
            response({}, status="429 Too Many Requests"),
            response({}, status="500 Internal Server Error"),
            b"HTTP/2 200\r\nlink: one\r\nlink: two\r\n\r\n{}",
            b"HTTP/2 200\r\nx: \xff\r\n\r\n{}",
            b"HTTP/2 200\r\ncontent-type: application/json\r\n\r\n\xff",
            b"HTTP/2 200\r\ncontent-type: application/json\r\n\r\n{",
            b'HTTP/2 200\r\ncontent-type: application/json\r\n\r\n{"x":1,"x":2}',
            b'HTTP/2 200\r\ncontent-type: application/json\r\n\r\n{"x":NaN}',
            b"HTTP/2 200\r\n\r\nHTTP/2 200\r\n\r\n{}",
            b"{}",
        ]
        for raw in bad:
            with self.subTest(raw=raw[:40]), self.assertRaises(helper.PolicyError):
                helper.split_response(raw)

    def test_control_character_in_json_key_is_rejected(self) -> None:
        raw = b'HTTP/2 200\r\ncontent-type: application/json\r\n\r\n{"bad\\u0080key":1}'
        with self.assertRaises(helper.PolicyError):
            helper.split_response(raw)

    def test_raw_header_and_utf8_size_limits(self) -> None:
        with self.assertRaises(helper.PolicyError):
            helper.split_response(b"x" * (helper.MAX_RESPONSE_RAW_BYTES + 1))
        raw = b"HTTP/2 200\r\nx: " + b"a" * helper.MAX_HEADER_BYTES + b"\r\n\r\n{}"
        with self.assertRaises(helper.PolicyError):
            helper.split_response(raw)
        raw = b"HTTP/2 200\r\ncontent-type: application/json\r\n\r\n" + b" " * (
            helper.MAX_RESPONSE_UTF8_BYTES + 1
        )
        with self.assertRaises(helper.PolicyError):
            helper.split_response(raw)


class PaginationTests(unittest.TestCase):
    def test_no_link_and_valid_next_query_order(self) -> None:
        self.assertIsNone(helper.next_cursor(None))
        link = next_link("abc", query="after=abc&per_page=25&state=open")
        self.assertEqual(helper.next_cursor(link), "abc")
        with_prev = (
            next_link("old", query="state=open&per_page=25&after=old").replace(
                'rel="next"', 'rel="prev"'
            )
            + ", "
            + next_link("new")
        )
        self.assertEqual(helper.next_cursor(with_prev), "new")

    def test_malformed_link_origin_path_and_query_fail_closed(self) -> None:
        cases = (
            next_link("one") + ", " + next_link("two"),
            '<http://api.github.com/x?state=open&per_page=25&after=x>; rel="next"',
            '<https://example.com/x?state=open&per_page=25&after=x>; rel="next"',
            f"<https://user@api.github.com{helper.REPOSITORY_PATH}?"
            'state=open&per_page=25&after=x>; rel="next"',
            f"<https://api.github.com:443{helper.REPOSITORY_PATH}?"
            'state=open&per_page=25&after=x>; rel="next"',
            next_link("x")[:-1] + '#fragment>; rel="next"',
            next_link("x", path="/repositories/1/dependabot/alerts"),
            next_link("x", path="/repos/other/repository/dependabot/alerts"),
            next_link("x", query="per_page=25&after=x"),
            next_link("x", query="state=all&per_page=25&after=x"),
            next_link("x", query="state=open&after=x"),
            next_link("x", query="state=open&per_page=100&after=x"),
            next_link("x", query="state=open&per_page=25"),
            next_link("x", query="state=open&per_page=25&after=x&extra=1"),
            next_link("x", query="state=open&per_page=25&after=x&after=y"),
            next_link("x", query="state=open&per_page=25&after="),
            next_link("x", query="state=open&per_page=25&after=%ZZ"),
            next_link("x", query="state=open&per_page=25&after=%2F"),
            next_link("x" * (helper.MAX_CURSOR_CHARS + 1)),
            "malformed",
        )
        for link in cases:
            with self.subTest(link=link[:80]), self.assertRaises(helper.PolicyError):
                helper.next_cursor(link)

    def test_multiple_pages_rebuild_fixed_endpoint_and_do_not_reuse_link(self) -> None:
        first_link = next_link("cursor=value")
        pages = [response([alert()], link=first_link), response([])]
        calls = []

        def fake_run(argv: list[str]) -> bytes:
            calls.append(argv)
            return pages.pop(0)

        with mock.patch.object(helper, "run_gh", side_effect=fake_run):
            result = helper.fetch_list()
        self.assertEqual(len(result), 1)
        self.assertEqual(
            calls[1][2],
            f"{helper.REPOSITORY_PATH}?state=open&per_page=25&after=cursor%3Dvalue",
        )
        self.assertNotIn("https://api.github.com", calls[1][2])

    def test_cursor_cycle_sixth_page_next_and_limits_fail_without_results(self) -> None:
        cycle = [
            response([], link=next_link("same")),
            response([], link=next_link("same")),
        ]
        with mock.patch.object(helper, "run_gh", side_effect=cycle):
            with self.assertRaises(helper.PolicyError):
                helper.fetch_list()

        six_pages = [
            response([], link=next_link(f"cursor{index}")) for index in range(6)
        ]
        with mock.patch.object(helper, "run_gh", side_effect=six_pages):
            with self.assertRaises(helper.PolicyError):
                helper.fetch_list()

        oversized_page = [
            alert(number=index + 1) for index in range(helper.PER_PAGE + 1)
        ]
        with mock.patch.object(helper, "run_gh", return_value=response(oversized_page)):
            with self.assertRaises(helper.PolicyError):
                helper.fetch_list()

        first = response([alert(number=1)], link=next_link("second"))
        second = response([alert(number=2)])
        with mock.patch.object(helper, "run_gh", side_effect=[first, second]):
            with mock.patch.object(helper, "MAX_ALERTS", 1):
                with self.assertRaises(helper.PolicyError):
                    helper.fetch_list()

    def test_six_pages_without_final_next_succeeds(self) -> None:
        pages = []
        for index in range(6):
            item = alert(number=index + 1)
            link = next_link(f"cursor{index}") if index < 5 else None
            pages.append(response([item], link=link))
        with mock.patch.object(helper, "run_gh", side_effect=pages):
            self.assertEqual(len(helper.fetch_list()), 6)

    def test_total_raw_and_utf8_limits(self) -> None:
        first = response([], link=next_link("next"))
        second = response([])
        with mock.patch.object(helper, "run_gh", side_effect=[first, second]):
            with mock.patch.object(helper, "MAX_TOTAL_RAW_BYTES", len(first)):
                with self.assertRaises(helper.PolicyError):
                    helper.fetch_list()
        body_size = helper.split_response(first)[2]
        with mock.patch.object(helper, "run_gh", side_effect=[first, second]):
            with mock.patch.object(helper, "MAX_TOTAL_UTF8_BYTES", body_size):
                with self.assertRaises(helper.PolicyError):
                    helper.fetch_list()


class MainSafetyTests(unittest.TestCase):
    def test_fixed_error_has_empty_stdout_and_single_stderr(self) -> None:
        stdout = io.StringIO()
        stderr = io.StringIO()
        with mock.patch.object(
            helper, "fetch_list", side_effect=RuntimeError("raw secret")
        ):
            with mock.patch.object(helper.sys, "stdout", stdout):
                with mock.patch.object(helper.sys, "stderr", stderr):
                    self.assertEqual(helper.main(["list"]), 1)
        self.assertEqual(stdout.getvalue(), "")
        self.assertEqual(stderr.getvalue(), "Dependabot alert request rejected\n")
        self.assertNotIn("raw secret", stderr.getvalue())

    def test_success_output_is_compact_ascii_and_bounded(self) -> None:
        value = helper.project_alert(alert(), detail=False)
        value["security_advisory"]["summary"] = "安全"
        stdout = io.StringIO()
        stderr = io.StringIO()
        with mock.patch.object(helper, "fetch_list", return_value=[value]):
            with mock.patch.object(helper.sys, "stdout", stdout):
                with mock.patch.object(helper.sys, "stderr", stderr):
                    self.assertEqual(helper.main(["list"]), 0)
        self.assertEqual(stderr.getvalue(), "")
        self.assertTrue(stdout.getvalue().isascii())
        self.assertIn("\\u5b89\\u5168", stdout.getvalue())
        with mock.patch.object(helper, "MAX_OUTPUT_UTF8_BYTES", 1):
            with mock.patch.object(helper, "fetch_list", return_value=[value]):
                with mock.patch.object(helper.sys, "stdout", io.StringIO()):
                    with mock.patch.object(helper.sys, "stderr", io.StringIO()):
                        self.assertEqual(helper.main(["list"]), 1)

    def test_view_requires_response_number_to_match_request(self) -> None:
        with mock.patch.object(
            helper, "run_gh", return_value=response(alert(number=2))
        ):
            with self.assertRaises(helper.PolicyError):
                helper.fetch_view(1)

    def test_main_view_success_uses_fixed_projection(self) -> None:
        projected = helper.project_alert(alert(), detail=True, expected_number=1)
        stdout = io.StringIO()
        stderr = io.StringIO()
        with mock.patch.object(helper, "fetch_view", return_value=projected) as fetch:
            with mock.patch.object(helper.sys, "stdout", stdout):
                with mock.patch.object(helper.sys, "stderr", stderr):
                    self.assertEqual(helper.main(["view", "1"]), 0)
        fetch.assert_called_once_with(1)
        self.assertEqual(json.loads(stdout.getvalue()), projected)
        self.assertEqual(stderr.getvalue(), "")


if __name__ == "__main__":
    unittest.main()
