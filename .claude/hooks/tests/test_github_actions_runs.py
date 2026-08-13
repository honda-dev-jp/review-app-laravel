"""固定GitHub Actions run/job metadata helperの回帰テスト。"""

from __future__ import annotations

import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import unittest
from unittest import mock


HELPER_PATH = Path(__file__).resolve().parents[2] / "helpers" / "github_actions_runs.py"
SPEC = importlib.util.spec_from_file_location("github_actions_runs", HELPER_PATH)
assert SPEC is not None and SPEC.loader is not None
helper = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(helper)


def run_value(**overrides: object) -> dict[str, object]:
    value: dict[str, object] = {
        "databaseId": 7,
        "workflowName": "CI",
        "displayTitle": "Synthetic run",
        "event": "push",
        "status": "completed",
        "conclusion": "success",
        "headBranch": "feature/synthetic",
        "headSha": "0123456789abcdef",
        "createdAt": "2026-01-01T00:00:00Z",
        "updatedAt": "2026-01-01T00:01:00.123Z",
        "url": "https://github.com/should/not/be/projected",
        "startedAt": "2026-01-01T00:00:01Z",
        "attempt": 1,
        "unknown": "ignored",
    }
    value.update(overrides)
    return value


def job_value(**overrides: object) -> dict[str, object]:
    value: dict[str, object] = {
        "name": "tests",
        "status": "completed",
        "conclusion": "success",
        "completedAt": "2026-01-01T00:00:30Z",
        "databaseId": 99,
        "startedAt": "2026-01-01T00:00:02Z",
        "url": "https://github.com/should/not/be/projected",
        "steps": [{"name": "untrusted\nstep", "conclusion": "success"}],
        "unknown": "ignored",
    }
    value.update(overrides)
    return value


def encoded(value: object) -> bytes:
    return json.dumps(value, ensure_ascii=True, separators=(",", ":")).encode()


def fake_process(stdout_value: bytes, stderr_value: bytes = b"", returncode: int = 0):
    stdout_read, stdout_write = os.pipe()
    stderr_read, stderr_write = os.pipe()
    os.write(stdout_write, stdout_value)
    os.close(stdout_write)
    os.write(stderr_write, stderr_value)
    os.close(stderr_write)

    class FakeProcess:
        pid = 424242

        def __init__(self) -> None:
            self.stdout = os.fdopen(stdout_read, "rb", buffering=0)
            self.stderr = os.fdopen(stderr_read, "rb", buffering=0)
            self.killed = False
            self.running = True
            self.wait_calls = 0

        def wait(self, timeout: float) -> int:
            self.wait_calls += 1
            self.running = False
            return returncode

        def poll(self) -> int | None:
            return None if self.running else returncode

        def kill(self) -> None:
            self.killed = True
            self.running = False

    return FakeProcess()


class CliAndSubprocessTests(unittest.TestCase):
    def test_security_limits_match_the_approved_design(self) -> None:
        self.assertEqual(helper.MAX_RUNS, 20)
        self.assertEqual(helper.MAX_JOBS, 100)
        self.assertEqual(helper.LIST_MAX_RESPONSE_RAW_BYTES, 256 * 1024)
        self.assertEqual(helper.VIEW_MAX_RESPONSE_RAW_BYTES, 2 * 1024 * 1024)
        self.assertEqual(helper.LIST_MAX_RESPONSE_UTF8_BYTES, 256 * 1024)
        self.assertEqual(helper.VIEW_MAX_RESPONSE_UTF8_BYTES, 2 * 1024 * 1024)
        self.assertEqual(helper.MAX_OUTPUT_UTF8_BYTES, 256 * 1024)
        self.assertEqual(helper.MAX_STRING_CHARS, 4096)
        self.assertEqual(helper.TIMEOUT_SECONDS, 30.0)
        self.assertEqual(helper.MAX_RUN_ID, 2**63 - 1)

    def test_parser_accepts_only_canonical_boundaries(self) -> None:
        self.assertEqual(helper.parse_command(["list"]), ("list", None))
        self.assertEqual(helper.parse_command(["view", "1"]), ("view", 1))
        self.assertEqual(
            helper.parse_command(["view", str(helper.MAX_RUN_ID)]),
            ("view", helper.MAX_RUN_ID),
        )
        invalid = (
            [],
            ["list", "extra"],
            ["view"],
            ["view", "0"],
            ["view", "01"],
            ["view", "+1"],
            ["view", "-1"],
            ["view", "1.0"],
            ["view", " 1"],
            ["view", "1 "],
            ["view", "１"],
            ["view", str(helper.MAX_RUN_ID + 1)],
            ["view", "9" * 20],
            ["view", "1", "--repo"],
        )
        for argv in invalid:
            with self.subTest(argv=argv), self.assertRaises(helper.PolicyError):
                helper.parse_command(argv)

    def test_exact_list_and_view_argv(self) -> None:
        self.assertEqual(
            helper.build_list_argv(),
            [
                "gh",
                "run",
                "list",
                "--limit",
                "20",
                "--repo",
                "github.com/honda-dev-jp/review-app-laravel",
                "--json",
                "databaseId,workflowName,displayTitle,event,status,conclusion,headBranch,headSha,createdAt,updatedAt",
            ],
        )
        self.assertEqual(
            helper.build_view_argv(7),
            [
                "gh",
                "run",
                "view",
                "7",
                "--repo",
                "github.com/honda-dev-jp/review-app-laravel",
                "--json",
                "databaseId,workflowName,displayTitle,event,status,conclusion,headBranch,headSha,createdAt,updatedAt,jobs",
            ],
        )
        forbidden = {
            "--include",
            "--all",
            "--attempt",
            "--exit-status",
            "--job",
            "--log",
            "--log-failed",
            "--verbose",
            "--jq",
            "--template",
            "--web",
        }
        self.assertTrue(forbidden.isdisjoint(helper.build_list_argv()))
        self.assertTrue(forbidden.isdisjoint(helper.build_view_argv(7)))

    def test_subprocess_boundary_rejects_argv_expansion_before_popen(self) -> None:
        invalid = (
            helper.build_list_argv() + ["--all"],
            [
                *helper.build_list_argv()[:6],
                "other/repository",
                *helper.build_list_argv()[7:],
            ],
            helper.build_view_argv(7) + ["--log"],
            [*helper.build_view_argv(7)[:3], "07", *helper.build_view_argv(7)[4:]],
        )
        with mock.patch.object(helper.subprocess, "Popen") as popen:
            for argv in invalid:
                with self.subTest(argv=argv), self.assertRaises(helper.PolicyError):
                    helper.run_gh(argv)
        popen.assert_not_called()

    def test_fixed_environment_does_not_inherit_caller_values(self) -> None:
        expected = {
            "HOME": mock.ANY,
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
                "GH_BROWSER": "synthetic",
                "BROWSER": "synthetic",
                "XDG_CONFIG_HOME": "/tmp/synthetic",
            },
            clear=False,
        ):
            environment = helper._subprocess_environment()
        self.assertEqual(environment, expected)

    def test_popen_options_and_successful_bounded_read(self) -> None:
        raw = encoded([])
        process = fake_process(raw)
        with mock.patch.object(
            helper.subprocess, "Popen", return_value=process
        ) as popen:
            self.assertEqual(helper.run_gh(helper.build_list_argv()), raw)
        self.assertEqual(popen.call_args.args[0], helper.build_list_argv())
        kwargs = popen.call_args.kwargs
        self.assertEqual(kwargs["stdin"], subprocess.DEVNULL)
        self.assertEqual(kwargs["stdout"], subprocess.PIPE)
        self.assertEqual(kwargs["stderr"], subprocess.PIPE)
        self.assertIs(kwargs["shell"], False)
        self.assertIs(kwargs["close_fds"], True)
        self.assertIs(kwargs["start_new_session"], True)
        self.assertEqual(kwargs["env"], helper._subprocess_environment())

    def test_canonical_view_reaches_popen(self) -> None:
        raw = encoded(run_value(jobs=[]))
        process = fake_process(raw)
        argv = helper.build_view_argv(7)
        with mock.patch.object(
            helper.subprocess, "Popen", return_value=process
        ) as popen:
            self.assertEqual(helper.run_gh(argv), raw)
        popen.assert_called_once()
        self.assertEqual(popen.call_args.args[0], argv)

    def test_nonzero_stderr_raw_and_utf8_limits_fail_closed(self) -> None:
        cases = (
            (fake_process(b"[]", returncode=1), {}),
            (fake_process(b"[]", b"private diagnostic"), {}),
            (fake_process(b"\xff"), {}),
            (fake_process(b"12345678901"), {"LIST_MAX_RESPONSE_RAW_BYTES": 10}),
            (
                fake_process("éé".encode()),
                {"LIST_MAX_RESPONSE_UTF8_BYTES": 3},
            ),
        )
        for process, constants in cases:
            patches = [
                mock.patch.object(helper, name, value)
                for name, value in constants.items()
            ]
            for patcher in patches:
                patcher.start()
            try:
                with (
                    self.subTest(constants=constants),
                    mock.patch.object(helper.subprocess, "Popen", return_value=process),
                    self.assertRaises(helper.PolicyError),
                ):
                    helper.run_gh(helper.build_list_argv())
            finally:
                for patcher in reversed(patches):
                    patcher.stop()
            self.assertTrue(process.stdout.closed)
            self.assertTrue(process.stderr.closed)

        view_process = fake_process(b"12345678901")
        with (
            mock.patch.object(helper, "VIEW_MAX_RESPONSE_RAW_BYTES", 10),
            mock.patch.object(helper.subprocess, "Popen", return_value=view_process),
            self.assertRaises(helper.PolicyError),
        ):
            helper.run_gh(helper.build_view_argv(7))
        self.assertTrue(view_process.stdout.closed)
        self.assertTrue(view_process.stderr.closed)

    def test_timeout_terminates_the_process_group(self) -> None:
        process = fake_process(b"")

        class TimeoutSelector:
            def register(self, *args: object) -> None:
                return None

            def get_map(self) -> dict[str, object]:
                return {"pending": object()}

            def select(self, timeout: float) -> list[object]:
                return []

            def close(self) -> None:
                return None

        with (
            mock.patch.object(helper.subprocess, "Popen", return_value=process),
            mock.patch.object(
                helper.selectors, "DefaultSelector", return_value=TimeoutSelector()
            ),
            mock.patch.object(helper.os, "killpg") as killpg,
            self.assertRaises(helper.PolicyError),
        ):
            helper.run_gh(helper.build_list_argv())
        killpg.assert_called_once_with(process.pid, helper.signal.SIGKILL)
        self.assertEqual(process.wait_calls, 1)
        self.assertTrue(process.stdout.closed)
        self.assertTrue(process.stderr.closed)

    def test_selector_creation_failure_cleans_up_process_and_descriptors(self) -> None:
        process = fake_process(b"")
        with (
            mock.patch.object(helper.subprocess, "Popen", return_value=process),
            mock.patch.object(
                helper.selectors, "DefaultSelector", side_effect=OSError("synthetic")
            ),
            mock.patch.object(helper.os, "killpg") as killpg,
            self.assertRaises(helper.PolicyError),
        ):
            helper.run_gh(helper.build_list_argv())
        killpg.assert_called_once_with(process.pid, helper.signal.SIGKILL)
        self.assertEqual(process.wait_calls, 1)
        self.assertTrue(process.stdout.closed)
        self.assertTrue(process.stderr.closed)

    def test_partial_selector_registration_failure_cleans_up_everything(self) -> None:
        process = fake_process(b"")

        class PartialSelector:
            def __init__(self) -> None:
                self.register_calls = 0
                self.closed = False

            def register(self, *args: object) -> None:
                self.register_calls += 1
                if self.register_calls == 2:
                    raise OSError("synthetic")

            def close(self) -> None:
                self.closed = True

        selector = PartialSelector()
        with (
            mock.patch.object(helper.subprocess, "Popen", return_value=process),
            mock.patch.object(
                helper.selectors, "DefaultSelector", return_value=selector
            ),
            mock.patch.object(helper.os, "killpg") as killpg,
            self.assertRaises(helper.PolicyError),
        ):
            helper.run_gh(helper.build_list_argv())
        self.assertEqual(selector.register_calls, 2)
        self.assertTrue(selector.closed)
        killpg.assert_called_once_with(process.pid, helper.signal.SIGKILL)
        self.assertEqual(process.wait_calls, 1)
        self.assertTrue(process.stdout.closed)
        self.assertTrue(process.stderr.closed)

    def test_killpg_failure_falls_back_to_process_kill_and_wait(self) -> None:
        process = fake_process(b"")
        with (
            mock.patch.object(helper.subprocess, "Popen", return_value=process),
            mock.patch.object(
                helper.selectors, "DefaultSelector", side_effect=OSError("synthetic")
            ),
            mock.patch.object(helper.os, "killpg", side_effect=OSError("synthetic")),
            self.assertRaises(helper.PolicyError),
        ):
            helper.run_gh(helper.build_list_argv())
        self.assertTrue(process.killed)
        self.assertEqual(process.wait_calls, 1)
        self.assertTrue(process.stdout.closed)
        self.assertTrue(process.stderr.closed)


class JsonProjectionTests(unittest.TestCase):
    def test_invalid_utf8_json_duplicate_keys_nan_and_infinity_are_rejected(
        self,
    ) -> None:
        invalid = (
            b"\xff",
            b"{",
            b'{"a":1,"a":2}',
            b'{"value":NaN}',
            b'{"value":Infinity}',
            b'{"bad\\u0000key":1}',
        )
        for raw in invalid:
            with self.subTest(raw=raw), self.assertRaises(helper.PolicyError):
                helper.decode_json(raw)

    def test_run_projection_is_fixed_and_unknown_fields_are_ignored(self) -> None:
        projected = helper.project_run(run_value())
        self.assertEqual(
            list(projected),
            [
                "databaseId",
                "workflowName",
                "displayTitle",
                "event",
                "status",
                "conclusion",
                "headBranch",
                "headSha",
                "createdAt",
                "updatedAt",
            ],
        )
        for excluded in ("url", "startedAt", "attempt", "unknown"):
            self.assertNotIn(excluded, projected)

    def test_view_projection_checks_id_and_projects_only_four_job_fields(self) -> None:
        value = run_value(jobs=[job_value()])
        projected = helper.project_run(value, include_jobs=True, expected_run_id=7)
        self.assertEqual(
            projected["jobs"],
            [
                {
                    "name": "tests",
                    "status": "completed",
                    "conclusion": "success",
                    "completedAt": "2026-01-01T00:00:30Z",
                }
            ],
        )
        for excluded in ("databaseId", "startedAt", "url", "steps", "unknown"):
            self.assertNotIn(excluded, projected["jobs"][0])
        with self.assertRaises(helper.PolicyError):
            helper.project_run(value, include_jobs=True, expected_run_id=8)

    def test_nullable_forms_are_normalized(self) -> None:
        projected = helper.project_run(
            run_value(workflowName="", headBranch=None, conclusion="")
        )
        self.assertIsNone(projected["workflowName"])
        self.assertIsNone(projected["headBranch"])
        self.assertIsNone(projected["conclusion"])
        for raw in (None, "", helper.ZERO_TIME):
            with self.subTest(raw=raw):
                self.assertIsNone(
                    helper.project_job(job_value(conclusion="", completedAt=raw))[
                        "completedAt"
                    ]
                )

    def test_safe_identifier_is_open_ended_but_strict(self) -> None:
        for value in ("completed", "future_status", "a" * 32):
            with self.subTest(value=value):
                self.assertEqual(helper._identifier(value), value)
        for value in ("", "in-progress", "UPPER", "a" * 33, None, 1):
            with self.subTest(value=value), self.assertRaises(helper.PolicyError):
                helper._identifier(value)

    def test_timestamp_accepts_utc_fraction_and_rejects_invalid_or_required_zero(
        self,
    ) -> None:
        for value in ("2026-02-28T23:59:59Z", "2026-02-28T23:59:59.123456789Z"):
            self.assertEqual(helper._timestamp(value), value)
        for value in (
            None,
            "",
            helper.ZERO_TIME,
            "2026-02-30T00:00:00Z",
            "2026-01-01T00:00:00+00:00",
        ):
            with self.subTest(value=value), self.assertRaises(helper.PolicyError):
                helper._timestamp(value)

    def test_timestamp_enforces_the_projected_string_length_boundary(self) -> None:
        prefix = "2026-01-01T00:00:00."
        at_limit = prefix + "1" * (helper.MAX_STRING_CHARS - len(prefix) - 1) + "Z"
        over_limit = prefix + "1" * (helper.MAX_STRING_CHARS - len(prefix)) + "Z"
        self.assertEqual(len(at_limit), helper.MAX_STRING_CHARS)
        self.assertEqual(helper._timestamp(at_limit), at_limit)
        self.assertEqual(len(over_limit), helper.MAX_STRING_CHARS + 1)
        with self.assertRaises(helper.PolicyError):
            helper._timestamp(over_limit)

    def test_required_fields_types_controls_string_and_count_limits_fail_closed(
        self,
    ) -> None:
        missing = run_value()
        del missing["headSha"]
        invalid = (
            missing,
            run_value(databaseId=True),
            run_value(databaseId=0),
            run_value(displayTitle=1),
            run_value(displayTitle="bad\nvalue"),
            run_value(displayTitle="x" * (helper.MAX_STRING_CHARS + 1)),
            run_value(status="completed-now"),
            run_value(createdAt=None),
        )
        for value in invalid:
            with self.subTest(value=value), self.assertRaises(helper.PolicyError):
                helper.project_run(value)
        with self.assertRaises(helper.PolicyError):
            helper.project_run(run_value(jobs=[job_value()] * 101), include_jobs=True)

    def test_list_count_empty_list_and_output_limit(self) -> None:
        with mock.patch.object(helper, "run_gh", return_value=b"[]"):
            self.assertEqual(helper.fetch_list(), b"[]\n")
        with (
            mock.patch.object(
                helper, "run_gh", return_value=encoded([run_value()] * 21)
            ),
            self.assertRaises(helper.PolicyError),
        ):
            helper.fetch_list()
        with (
            mock.patch.object(helper, "MAX_OUTPUT_UTF8_BYTES", 2),
            self.assertRaises(helper.PolicyError),
        ):
            helper._encode([])

    def test_failed_workflow_is_successful_metadata(self) -> None:
        raw = encoded(
            run_value(conclusion="failure", jobs=[job_value(conclusion="failure")])
        )
        with mock.patch.object(helper, "run_gh", return_value=raw):
            output = json.loads(helper.fetch_view(7))
        self.assertEqual(output["conclusion"], "failure")
        self.assertEqual(output["jobs"][0]["conclusion"], "failure")


class MainTests(unittest.TestCase):
    def test_failure_has_empty_stdout_fixed_stderr_and_no_input_leakage(self) -> None:
        stdout = io.BytesIO()
        stderr = io.StringIO()

        class BinaryStdout:
            buffer = stdout

        secret_like_input = "synthetic-private-value"
        with (
            mock.patch.object(helper.sys, "stdout", BinaryStdout()),
            mock.patch.object(helper.sys, "stderr", stderr),
        ):
            self.assertEqual(helper.main(["view", secret_like_input]), 1)
        self.assertEqual(stdout.getvalue(), b"")
        self.assertEqual(stderr.getvalue(), helper.FIXED_ERROR + "\n")
        self.assertNotIn(secret_like_input, stderr.getvalue())

        stdout = io.BytesIO()
        stderr = io.StringIO()

        class CliFailureStdout:
            buffer = stdout

        with (
            mock.patch.object(
                helper, "fetch_list", side_effect=helper.PolicyError("raw CLI detail")
            ),
            mock.patch.object(helper.sys, "stdout", CliFailureStdout()),
            mock.patch.object(helper.sys, "stderr", stderr),
        ):
            self.assertEqual(helper.main(["list"]), 1)
        self.assertEqual(stdout.getvalue(), b"")
        self.assertEqual(stderr.getvalue(), helper.FIXED_ERROR + "\n")
        self.assertNotIn("raw CLI detail", stderr.getvalue())

    def test_success_has_only_compact_ascii_json_and_empty_stderr(self) -> None:
        stdout = io.BytesIO()
        stderr = io.StringIO()

        class BinaryStdout:
            buffer = stdout

        with (
            mock.patch.object(helper, "fetch_list", return_value=b"[]\n"),
            mock.patch.object(helper.sys, "stdout", BinaryStdout()),
            mock.patch.object(helper.sys, "stderr", stderr),
        ):
            self.assertEqual(helper.main(["list"]), 0)
        self.assertEqual(stdout.getvalue(), b"[]\n")
        self.assertEqual(stderr.getvalue(), "")


if __name__ == "__main__":
    unittest.main()
