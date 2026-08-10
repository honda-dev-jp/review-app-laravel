"""Global Security Advisories専用helperのclosed world回帰テスト。"""

from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import unittest
from unittest import mock


HELPER_PATH = Path(__file__).resolve().parents[2] / "helpers" / "github_global_advisories.py"
SPEC = importlib.util.spec_from_file_location("github_global_advisories", HELPER_PATH)
assert SPEC is not None and SPEC.loader is not None
helper = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(helper)


def advisory(**changes: object) -> dict[str, object]:
    value: dict[str, object] = {
        "ghsa_id": "GHSA-2345-6789-cfgh",
        "summary": "Synthetic advisory",
        "severity": "high",
        "published_at": "2026-01-01T00:00:00Z",
        "updated_at": "2026-01-02T00:00:00Z",
        "withdrawn_at": None,
        "vulnerabilities": [
            {
                "package": {"ecosystem": "composer", "name": "laravel/framework"},
                "vulnerable_version_range": ">= 1, < 2",
                "first_patched_version": "2.0.0",
            }
        ],
    }
    value.update(changes)
    return value


def response(body: object, link: str | None = None) -> bytes:
    headers = ["HTTP/2 200", "content-type: application/json"]
    if link is not None:
        headers.append(f"link: {link}")
    return ("\r\n".join(headers) + "\r\n\r\n" + json.dumps(body)).encode()


class ArgumentAndArgvTests(unittest.TestCase):
    def test_generated_argv_is_exact(self) -> None:
        endpoint = "/advisories/GHSA-2345-6789-cfgh"
        self.assertEqual(
            helper.build_argv(endpoint),
            [
                "gh",
                "api",
                endpoint,
                "--hostname",
                "github.com",
                "--method",
                "GET",
                "--include",
                "--header",
                "Accept: application/vnd.github+json",
                "--header",
                "X-GitHub-Api-Version: 2022-11-28",
            ],
        )

    def test_arbitrary_endpoint_cannot_reach_subprocess(self) -> None:
        endpoints = (
            "/repos/honda-dev-jp/review-app-laravel/dependabot/alerts",
            "/advisories-extra",
            "/advisories?ecosystem=composer&affects=other%2Fpackage&per_page=50",
            "/advisories?ecosystem=composer&affects=laravel%2Fframework&per_page=50&extra=x",
        )
        for endpoint in endpoints:
            with self.subTest(endpoint=endpoint):
                with mock.patch.object(helper.subprocess, "Popen") as popen:
                    with self.assertRaises(helper.PolicyError):
                        helper.run_gh(helper.build_argv(endpoint))
                popen.assert_not_called()

    def test_list_endpoint_is_rebuilt_only_from_validated_values(self) -> None:
        self.assertEqual(
            helper.build_list_endpoint("composer", "laravel/framework", "safe-cursor"),
            "/advisories?ecosystem=composer&affects=laravel%2Fframework&per_page=50&after=safe-cursor",
        )
        for ecosystem, package, cursor in (
            ("pip", "laravel/framework", None),
            ("composer", "other/package", None),
            ("composer", "laravel/framework", "bad&cursor"),
        ):
            with self.subTest(ecosystem=ecosystem, package=package, cursor=cursor):
                with self.assertRaises(helper.PolicyError):
                    helper.build_list_endpoint(ecosystem, package, cursor)

    def test_command_parser_is_finite_and_ordered(self) -> None:
        self.assertEqual(
            helper.parse_command(["list", "--ecosystem", "npm", "--package", "typescript"]),
            ("list", ("npm", "typescript")),
        )
        self.assertEqual(
            helper.parse_command(["view", "GHSA-2345-6789-cfgh"]),
            ("view", ("GHSA-2345-6789-cfgh",)),
        )
        for argv in (
            ["list", "--package", "typescript", "--ecosystem", "npm"],
            ["list", "--ecosystem", "npm", "--package", "unknown"],
            ["view", "GHSA-zzzz-zzzz-zzzz"],
            ["view", "GHSA-2345-6789-cfgh", "--method", "POST"],
        ):
            with self.subTest(argv=argv):
                with self.assertRaises(helper.PolicyError):
                    helper.parse_command(argv)

    def test_subprocess_environment_excludes_external_gh_controls(self) -> None:
        environment = helper._subprocess_environment()
        for name in ("GH_HOST", "GH_REPO", "GH_DEBUG", "GH_FORCE_TTY", "GH_CONFIG_DIR"):
            self.assertNotIn(name, environment)
        self.assertEqual(environment["GH_PAGER"], "cat")
        self.assertEqual(environment["PAGER"], "cat")
        self.assertEqual(environment["NO_COLOR"], "1")


class ResponseValidationTests(unittest.TestCase):
    def test_http_two_point_zero_status_from_gh_is_accepted(self) -> None:
        # ghが出力し得るHTTP/2.0表記を拒否して正常応答をfail-closedにしすぎない。
        raw = response(advisory()).replace(b"HTTP/2 200", b"HTTP/2.0 200 OK", 1)
        _, body = helper.split_response(raw)
        self.assertIsInstance(body, dict)

    def test_valid_projection_ignores_unknown_fields(self) -> None:
        value = advisory(severity="medium", unknown={"untrusted": True})
        value["vulnerabilities"][0]["unknown"] = {"untrusted": True}
        projected = helper.project_advisory(value)
        self.assertNotIn("unknown", projected)
        self.assertNotIn("unknown", projected["vulnerabilities"][0])
        self.assertEqual(projected["ghsa_id"], "GHSA-2345-6789-cfgh")
        self.assertEqual(projected["severity"], "medium")

    def test_nullable_global_advisory_schema_is_projected(self) -> None:
        # 公式schemaのnullable値を欠落・型不正と混同して正常応答を拒否しない。
        self.assertIsNone(helper.project_advisory(advisory(vulnerabilities=None))["vulnerabilities"])

        nullable_package = advisory()
        nullable_package["vulnerabilities"][0]["package"] = None
        projected = helper.project_advisory(nullable_package)
        self.assertIsNone(projected["vulnerabilities"][0]["package"])

        nullable_name = advisory()
        nullable_name["vulnerabilities"][0]["package"]["name"] = None
        projected = helper.project_advisory(nullable_name)
        self.assertIsNone(projected["vulnerabilities"][0]["package"]["name"])

        nullable_versions = advisory()
        nullable_versions["vulnerabilities"][0]["vulnerable_version_range"] = None
        nullable_versions["vulnerabilities"][0]["first_patched_version"] = None
        projected = helper.project_advisory(nullable_versions)
        self.assertIsNone(projected["vulnerabilities"][0]["vulnerable_version_range"])
        self.assertIsNone(projected["vulnerabilities"][0]["first_patched_version"])
        self.assertIsNone(projected["withdrawn_at"])
        withdrawn = helper.project_advisory(advisory(withdrawn_at="2026-01-03T00:00:00Z"))
        self.assertEqual(withdrawn["withdrawn_at"], "2026-01-03T00:00:00Z")

    def test_package_filter_skips_nullable_unmatchable_vulnerabilities(self) -> None:
        nullable_package = {
            "package": None,
            "vulnerable_version_range": None,
            "first_patched_version": None,
        }
        nullable_name = {
            "package": {"ecosystem": "composer", "name": None},
            "vulnerable_version_range": None,
            "first_patched_version": None,
        }
        matching = advisory()["vulnerabilities"][0]
        projected = helper.project_advisory(
            advisory(vulnerabilities=[nullable_package, nullable_name, matching]),
            ("composer", "laravel/framework"),
        )
        self.assertEqual(len(projected["vulnerabilities"]), 1)
        self.assertEqual(projected["vulnerabilities"][0]["package"]["name"], "laravel/framework")

    def test_package_filter_with_no_projected_match_fails_closed(self) -> None:
        for vulnerabilities in (
            None,
            [{"package": None, "vulnerable_version_range": None, "first_patched_version": None}],
            [
                {
                    "package": {"ecosystem": "composer", "name": None},
                    "vulnerable_version_range": None,
                    "first_patched_version": None,
                }
            ],
            [
                {
                    "package": {"ecosystem": "composer", "name": "laravel/sail"},
                    "vulnerable_version_range": ">= 1, < 2",
                    "first_patched_version": "2.0.0",
                }
            ],
        ):
            with self.subTest(vulnerabilities=vulnerabilities):
                with self.assertRaises(helper.PolicyError):
                    helper.project_advisory(advisory(vulnerabilities=vulnerabilities), ("composer", "laravel/framework"))

    def test_missing_wrong_schema_and_control_characters_fail_closed(self) -> None:
        missing = advisory()
        del missing["summary"]
        wrong_vulnerability_field = advisory()
        vulnerability = wrong_vulnerability_field["vulnerabilities"][0]
        vulnerability["patched_versions"] = vulnerability.pop("first_patched_version")
        values = (
            missing,
            wrong_vulnerability_field,
            advisory(severity=1),
            advisory(summary="unsafe\x1b[31m"),
            advisory(vulnerabilities="not-a-list"),
            advisory(published_at="not-a-timestamp"),
            advisory(withdrawn_at="not-a-timestamp"),
        )
        for value in values:
            with self.subTest(value=value):
                with self.assertRaises(helper.PolicyError):
                    helper.project_advisory(value)

    def test_raw_size_invalid_utf8_and_invalid_json_fail_before_projection(self) -> None:
        values = (
            b"x" * (helper.MAX_RESPONSE_BYTES + 1),
            b"HTTP/2 200\r\ncontent-type: application/json\r\n\r\n\xff",
            b"HTTP/2 200\r\ncontent-type: application/json\r\n\r\n{",
            b'HTTP/2 200\r\ncontent-type: application/json\r\n\r\n{"x":1,"x":2}',
            b'HTTP/2 200\r\ncontent-type: application/json\r\n\r\n{"x":NaN}',
        )
        for raw in values:
            with self.subTest(size=len(raw)):
                with self.assertRaises(helper.PolicyError):
                    helper.split_response(raw)

    def test_subprocess_combined_output_limit_is_enforced_while_reading(self) -> None:
        # communicate後ではなく読み取り中に止め、巨大stdout/stderrの蓄積を防ぐ。
        stdout_read, stdout_write = os.pipe()
        stderr_read, stderr_write = os.pipe()
        os.write(stdout_write, b"123456")
        os.close(stdout_write)
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
        argv = helper.build_argv(helper.build_view_endpoint("GHSA-2345-6789-cfgh"))
        with mock.patch.object(helper.subprocess, "Popen", return_value=process):
            with mock.patch.object(helper, "MAX_RESPONSE_BYTES", 5):
                with self.assertRaises(helper.PolicyError):
                    helper.run_gh(argv)
        self.assertTrue(process.killed)

    def test_non_200_duplicate_headers_and_oversize_headers_fail_closed(self) -> None:
        values = (
            b"HTTP/2 404\r\ncontent-type: application/json\r\n\r\n{}",
            b"HTTP/2 200\r\nlink: a\r\nlink: b\r\n\r\n{}",
            b"HTTP/2 200\r\nx: " + b"a" * helper.MAX_HEADER_BYTES + b"\r\n\r\n{}",
        )
        for raw in values:
            with self.subTest(size=len(raw)):
                with self.assertRaises(helper.PolicyError):
                    helper.split_response(raw)


class PaginationTests(unittest.TestCase):
    def test_next_link_origin_path_query_and_cursor_are_validated(self) -> None:
        valid = (
            '<https://api.github.com/advisories?ecosystem=composer&affects=laravel%2Fframework&per_page=50&after=cursor-2>; rel="next"'
        )
        self.assertEqual(helper.next_cursor(valid, "composer", "laravel/framework"), "cursor-2")
        invalid = (
            '<https://example.com/advisories?ecosystem=composer&affects=laravel%2Fframework&per_page=50&after=x>; rel="next"',
            '<https://api.github.com/repos/x?ecosystem=composer&affects=laravel%2Fframework&per_page=50&after=x>; rel="next"',
            '<https://api.github.com/advisories?ecosystem=npm&affects=laravel%2Fframework&per_page=50&after=x>; rel="next"',
            '<https://api.github.com/advisories?ecosystem=composer&affects=laravel%2Fframework&per_page=50&after=bad%26cursor>; rel="next"',
            '<https://api.github.com/advisories?ecosystem=composer&affects=laravel%2Fframework&per_page=50&after=x&extra=y>; rel="next"',
        )
        for link in invalid:
            with self.subTest(link=link):
                with self.assertRaises(helper.PolicyError):
                    helper.next_cursor(link, "composer", "laravel/framework")

    def test_pagination_rebuilds_argv_from_cursor_instead_of_reusing_link(self) -> None:
        first_link = (
            '<https://api.github.com/advisories?ecosystem=composer&affects=laravel%2Fframework&per_page=50&after=cursor-2>; rel="next"'
        )
        calls: list[list[str]] = []

        def fake_run(argv: list[str]) -> bytes:
            calls.append(argv)
            return response([advisory()], first_link if len(calls) == 1 else None)

        with mock.patch.object(helper, "run_gh", side_effect=fake_run):
            result = helper.fetch_list("composer", "laravel/framework")
        self.assertEqual(len(result), 2)
        self.assertEqual(calls[0], helper.build_argv(helper.build_list_endpoint("composer", "laravel/framework")))
        self.assertEqual(calls[1], helper.build_argv(helper.build_list_endpoint("composer", "laravel/framework", "cursor-2")))

    def test_more_than_maximum_pages_fails_closed(self) -> None:
        # 上限到達後もnextがあれば部分結果を成功扱いせず、request全体を拒否する。
        link = (
            '<https://api.github.com/advisories?ecosystem=composer&affects=laravel%2Fframework&per_page=50&after=cursor>; rel="next"'
        )
        with mock.patch.object(helper, "run_gh", return_value=response([advisory()], link)):
            with self.assertRaises(helper.PolicyError):
                helper.fetch_list("composer", "laravel/framework")

    def test_zero_maximum_pages_fails_closed(self) -> None:
        with mock.patch.object(helper, "MAX_PAGES", 0):
            with mock.patch.object(helper, "run_gh") as run_gh:
                with self.assertRaises(helper.PolicyError):
                    helper.fetch_list("composer", "laravel/framework")
        run_gh.assert_not_called()


class MainTests(unittest.TestCase):
    def test_main_outputs_only_projected_json(self) -> None:
        with mock.patch.object(helper, "fetch_view", return_value=helper.project_advisory(advisory())):
            with mock.patch.object(helper.sys, "stdout") as stdout:
                self.assertEqual(helper.main(["view", "GHSA-2345-6789-cfgh"]), 0)
        written = "".join(call.args[0] for call in stdout.write.call_args_list)
        self.assertNotIn("unknown", written)
        self.assertIn("GHSA-2345-6789-cfgh", written)

    def test_main_error_is_fixed_and_does_not_echo_input(self) -> None:
        # 攻撃者制御の引数や応答をerror経由で端末へ反射させない。
        with mock.patch.object(helper.sys, "stderr") as stderr:
            self.assertEqual(helper.main(["view", "synthetic-sensitive-value"]), 1)
        rendered = " ".join(str(call) for call in stderr.write.call_args_list)
        self.assertNotIn("synthetic-sensitive-value", rendered)

    def test_unexpected_helper_error_is_also_fixed(self) -> None:
        with mock.patch.object(helper, "fetch_view", side_effect=RuntimeError("raw sensitive response")):
            with mock.patch.object(helper.sys, "stderr") as stderr:
                self.assertEqual(helper.main(["view", "GHSA-2345-6789-cfgh"]), 1)
        rendered = " ".join(str(call) for call in stderr.write.call_args_list)
        self.assertNotIn("raw sensitive response", rendered)


if __name__ == "__main__":
    unittest.main()
